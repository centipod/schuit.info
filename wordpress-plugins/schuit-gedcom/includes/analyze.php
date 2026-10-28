<?php
declare(strict_types=1);
namespace Schuit\Gedcom;

use Fisharebest\Webtrees\GedcomFilters\GedcomEncodingFilter;
use Fisharebest\Webtrees\Registry;
require_once __DIR__.'/privacy.php';

// Resource budgets keep one import within the PHP worker's memory/time allowance.
const READ_BYTES = 65536;
const MAX_LINE_BYTES = 131072;
const MIN_FILE_BYTES = 20;
const MAX_RECORD_BYTES = 4 * 1024 * 1024;
const PROGRESS_LINES = 10000;
const MAX_ANALYSIS_MEMORY = 300 * 1024 * 1024;
const MAX_RECORDS = 500000;

/** Bounded streaming reader: supports LF, CRLF and legacy CR files. */
function lines($stream): \Generator {
    $buffer = '';
    while (!feof($stream)) {
        $chunk = fread($stream, READ_BYTES);
        if ($chunk === false) { throw new \RuntimeException('Bestand lezen mislukt.'); }
        $buffer .= $chunk;
        $parts = preg_split('/\r\n|\n|\r(?!$)/', $buffer);
        $buffer = array_pop($parts);
        foreach ($parts as $line) {
            if (strlen($line) > MAX_LINE_BYTES) { throw new \RuntimeException('Een GEDCOM-regel is te lang.'); }
            yield $line;
        }
        if (strlen($buffer) > MAX_LINE_BYTES) { throw new \RuntimeException('Een GEDCOM-regel is te lang.'); }
    }
    if ($buffer !== '') { yield rtrim($buffer, "\r"); }
}

function prepareEncoding($source): string {
    $detected = Registry::encodingFactory()->detect(fread($source, READ_BYTES));
    if ($detected === null) { throw new \RuntimeException('De GEDCOM-header ontbreekt of is langer dan 64 KB.'); }
    $name = constant(get_class($detected) . '::NAME');
    if (!is_string($name)) { throw new \RuntimeException('Onbekende tekencodering.'); }
    rewind($source);
    // Upstream's UTF-16 converter loses valid Latin-1 accents; iconv preserves them.
    if (str_starts_with($name, 'UTF-16')) {
        if (!stream_filter_append($source, 'convert.iconv.' . $name . '/UTF-8', STREAM_FILTER_READ)) {
            throw new \RuntimeException('UTF-16-conversie niet beschikbaar.');
        }
    } elseif ($name !== 'UTF-8' && !stream_filter_append($source, GedcomEncodingFilter::class, STREAM_FILTER_READ, ['src_encoding'=>$name])) {
        throw new \RuntimeException('Tekencodering wordt niet ondersteund.');
    }
    return $name;
}

/** Validation state is private to a single streaming analysis, never persisted. */
final class Analysis {
    private array $counts = ['INDI'=>0, 'FAM'=>0, 'SOUR'=>0, 'OBJE'=>0, 'NOTE'=>0, 'REPO'=>0];
    private array $ids = [];
    private array $refs = [];
    private int $head = 0;
    private int $trailer = 0;
    private int $lineNumber = 0;
    private int $recordBytes = 0;
    private int $records = 0;
    private int $normalized = 0;
    private int $previousLevel = 0;
    private int $lastProgress = 0;
    private string $tag = '';
    private string $version = '';
    private string $encoding = '';
    private string $levelOne = '';

    public function __construct(private array $excluded) {}

    private function structure(string $line): array {
        if (!mb_check_encoding($line, 'UTF-8') || str_contains($line, "\0") || str_contains($line, "\u{FFFD}")) {
            throw new \RuntimeException('Ongeldige of niet-converteerbare tekens op regel ' . $this->lineNumber . '. Exporteer het bronbestand opnieuw als UTF-8.');
        }
        if (!preg_match('/^([0-9]) (?:@([A-Za-z0-9:_.-]{1,20})@ )?([_A-Z][_A-Z0-9]*)(?: (.*))?$/D', $line, $match)) {
            throw new \RuntimeException('Ongeldige GEDCOM-structuur op regel ' . $this->lineNumber . '.');
        }
        $level = (int)$match[1];
        if ($this->lineNumber === 1 && ($level !== 0 || $match[3] !== 'HEAD')) {
            throw new \RuntimeException('Het bestand moet met 0 HEAD beginnen.');
        }
        if ($this->trailer > 0) { throw new \RuntimeException('Gegevens na het einde van het bestand.'); }
        if ($level > $this->previousLevel + 1) { throw new \RuntimeException('Een niveau ontbreekt op regel ' . $this->lineNumber . '.'); }
        $this->previousLevel = $level;
        return [$level, $match[2], $match[3], $match[4] ?? ''];
    }

    private function startRecord(string $id, string $tag, string $value): void {
        ++$this->records;
        $this->recordBytes = 0;
        $this->tag = $tag;
        if (in_array($tag, ['HEAD', 'TRLR'], true) && ($id !== '' || trim($value) !== '')) {
            throw new \RuntimeException('Ongeldige HEAD- of TRLR-regel.');
        }
        if ($tag === 'HEAD') { ++$this->head; return; }
        if ($tag === 'TRLR') { ++$this->trailer; return; }
        if ($id === '') { throw new \RuntimeException('Een record mist een identificatie op regel ' . $this->lineNumber . '.'); }
        if (isset($this->ids[$id])) { throw new \RuntimeException('Dubbele recordidentificatie op regel ' . $this->lineNumber . '.'); }
        assertNotExcluded($id, $this->excluded);
        $this->ids[$id] = true;
        $this->counts[$tag] = ($this->counts[$tag] ?? 0) + 1;
    }

    private function header(int $level, string $tag, string $value, string $line): string {
        if ($level === 1) { $this->levelOne = $tag; }
        if ($this->tag !== 'HEAD') { return $line; }
        if ($level === 2 && $this->levelOne === 'GEDC' && $tag === 'VERS') { $this->version = $value; }
        if ($level === 1 && $tag === 'CHAR') { $this->encoding = $value; return '1 CHAR UTF-8'; }
        return $line;
    }

    public function consume(string $line): ?string {
        $line = $this->lineNumber === 0 ? preg_replace('/^\xEF\xBB\xBF/', '', $line) : $line;
        if ($line === '') { return null; }
        ++$this->lineNumber;
        [$level, $id, $tag, $value] = $this->structure($line);
        if ($level === 0) { $this->startRecord($id, $tag, $value); }
        $this->recordBytes += strlen($line) + 1;
        if ($this->recordBytes > MAX_RECORD_BYTES) { throw new \RuntimeException('Een GEDCOM-record is groter dan 4 MB.'); }
        if (preg_match('/^@([^@\s]+)@$/D', $value, $reference)) { $this->refs[$reference[1]] = true; }
        $line = $this->header($level, $tag, $value, $line);
        $next = preg_match('/^\d+ NAME /', $line) ? preg_replace('/(?<![ \t])\//', ' /', $line) : $line;
        if ($next !== $line) { ++$this->normalized; }
        return $next;
    }

    public function progress(callable $callback): void {
        if ($this->lineNumber - $this->lastProgress < PROGRESS_LINES) { return; }
        if (memory_get_usage(true) > MAX_ANALYSIS_MEMORY || $this->records > MAX_RECORDS) {
            throw new \RuntimeException('Het bestand is te complex voor deze import. Neem contact op met de beheerder.');
        }
        $callback($this->lineNumber, $this->records);
        $this->lastProgress = $this->lineNumber;
    }

    public function result(string $input, int $size, string $detectedName): array {
        if ($this->head !== 1 || $this->trailer !== 1) { throw new \RuntimeException('Exact één HEAD en één TRLR zijn vereist.'); }
        if (!in_array($this->version, ['5.5', '5.5.1'], true)) { throw new \RuntimeException('Gebruik GEDCOM 5.5 of 5.5.1 voor deze versie van Webtrees.'); }
        if ($this->counts['INDI'] < 1) { throw new \RuntimeException('Het bestand bevat geen personen.'); }
        $warnings = [];
        $missing = count(array_diff_key($this->refs, $this->ids));
        if ($missing) { $warnings[] = $missing . ' verwijzingen wijzen naar een ontbrekend record. Controleer de brongegevens.'; }
        if (!$this->counts['FAM']) { $warnings[] = 'Dit bestand bevat geen gezinnen.'; }
        if (!$this->encoding) { $warnings[] = 'Geen CHAR-header; de tekencodering is automatisch bepaald.'; }
        return ['counts'=>$this->counts, 'records'=>$this->records, 'lines'=>$this->lineNumber, 'bytes'=>$size,
            'sha256'=>hash_file('sha256', $input), 'version'=>$this->version, 'encoding'=>$detectedName,
            'normalized_names'=>$this->normalized, 'missing_references'=>$missing, 'warnings'=>$warnings];
    }
}

function analyze(string $input, string $output, callable $progress): array {
    $analysis = new Analysis(exclusions());
    $size = filesize($input);
    if ($size === false || $size < MIN_FILE_BYTES || $size > MAX_BYTES) { throw new \RuntimeException('Gebruik een GEDCOM-bestand van maximaal 100 MB.'); }
    $source = fopen($input, 'rb');
    if (!$source) { throw new \RuntimeException('Bestand openen mislukt.'); }
    try {
        $destination = fopen($output, 'wb');
        if (!$destination) { throw new \RuntimeException('Bestand openen mislukt.'); }
        try {
            chmod($output, 0600);
            $encoding = prepareEncoding($source);
            foreach (lines($source) as $line) {
                $normalized = $analysis->consume($line);
                if ($normalized === null) { continue; }
                if (fwrite($destination, $normalized . "\n") === false) { throw new \RuntimeException('Genormaliseerd bestand opslaan mislukt.'); }
                $analysis->progress($progress);
            }
            return $analysis->result($input, $size, $encoding);
        } finally { fclose($destination); }
    } finally { fclose($source); }
}
