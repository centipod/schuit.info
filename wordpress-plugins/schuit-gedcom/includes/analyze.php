<?php
declare(strict_types=1);

namespace Schuit\Gedcom;

use Fisharebest\Webtrees\GedcomFilters\GedcomEncodingFilter;
use Fisharebest\Webtrees\Registry;

/** Bounded streaming reader: supports LF, CRLF and legacy CR files. */
function lines($stream): \Generator {
    $buffer = '';
    while (!feof($stream)) {
        $chunk = fread($stream, 65536);
        if ($chunk === false) { throw new \RuntimeException('Bestand lezen mislukt.'); }
        $buffer .= $chunk;
        $parts = preg_split('/\r\n|\n|\r(?!$)/', $buffer);
        $buffer = array_pop($parts);
        foreach ($parts as $line) {
            if (strlen($line) > 131072) { throw new \RuntimeException('Een GEDCOM-regel is te lang.'); }
            yield $line;
        }
        if (strlen($buffer) > 131072) { throw new \RuntimeException('Een GEDCOM-regel is te lang.'); }
    }
    if ($buffer !== '') { yield rtrim($buffer, "\r"); }
}

function analyze(string $input, string $output, callable $progress): array {
    $size = filesize($input);
    if ($size === false || $size < 20 || $size > MAX_BYTES) { throw new \RuntimeException('Gebruik een GEDCOM-bestand van maximaal 100 MB.'); }
    $src = fopen($input, 'rb');
    $dst = fopen($output, 'wb');
    chmod($output, 0600);
    if (!$src || !$dst) { throw new \RuntimeException('Bestand openen mislukt.'); }
    $counts = ['INDI'=>0, 'FAM'=>0, 'SOUR'=>0, 'OBJE'=>0, 'NOTE'=>0, 'REPO'=>0];
    $ids = []; $refs = []; $warnings = [];
    $head = 0; $trailer = 0; $lineNumber = 0; $recordBytes = 0; $records = 0;
    $normalized = 0; $tag = ''; $version = ''; $encoding = ''; $previousLevel = 0; $lastProgress = 0; $levelOne = '';
    try {
        $detected = Registry::encodingFactory()->detect(fread($src,65536));
        if ($detected === null) { throw new \RuntimeException('De GEDCOM-header ontbreekt of is langer dan 64 KB.'); }
        $detectedName = $detected::NAME;
        rewind($src);
        // webtrees 2.2.6's UTF-16 converter replaces valid Latin-1 accents.
        // iconv preserves them; leave UTF-8 untouched so invalid bytes are rejected.
        if (str_starts_with($detectedName, 'UTF-16')) {
            if (!stream_filter_append($src, 'convert.iconv.' . $detectedName . '/UTF-8', STREAM_FILTER_READ)) {
                throw new \RuntimeException('UTF-16-conversie niet beschikbaar.');
            }
        } elseif ($detectedName !== 'UTF-8' && !stream_filter_append($src, GedcomEncodingFilter::class, STREAM_FILTER_READ, ['src_encoding'=>$detectedName])) {
            throw new \RuntimeException('Tekencodering wordt niet ondersteund.');
        }
        foreach (lines($src) as $line) {
            $line = $lineNumber === 0 ? preg_replace('/^\xEF\xBB\xBF/', '', $line) : $line;
            if ($line === '') { continue; }
            ++$lineNumber;
            if (!mb_check_encoding($line, 'UTF-8') || str_contains($line, "\0") || str_contains($line, "\u{FFFD}")) { throw new \RuntimeException('Ongeldige of niet-converteerbare tekens op regel ' . $lineNumber . '. Exporteer het bronbestand opnieuw als UTF-8.'); }
            if (!preg_match('/^(\d+) (?:@([^@\s]+)@ )?([A-Za-z0-9_]+)(?: (.*))?$/D', $line, $m)) {
                throw new \RuntimeException('Ongeldige GEDCOM-structuur op regel ' . $lineNumber . '.');
            }
            $level = (int)$m[1]; $value = $m[4] ?? '';
            if ($lineNumber === 1 && ($level !== 0 || $m[3] !== 'HEAD')) { throw new \RuntimeException('Het bestand moet met 0 HEAD beginnen.'); }
            if ($trailer > 0) { throw new \RuntimeException('Gegevens na het einde van het bestand.'); }
            if ($level > $previousLevel + 1) { throw new \RuntimeException('Een niveau ontbreekt op regel ' . $lineNumber . '.'); }
            $previousLevel = $level;
            if ($level === 0) {
                ++$records; $recordBytes = 0; $tag = $m[3];
                if ($tag === 'HEAD') { ++$head; }
                elseif ($tag === 'TRLR') { ++$trailer; }
                else {
                    if (($m[2] ?? '') === '') { throw new \RuntimeException('Een record mist een identificatie op regel ' . $lineNumber . '.'); }
                    if (isset($ids[$m[2]])) { throw new \RuntimeException('Dubbele recordidentificatie op regel ' . $lineNumber . '.'); }
                    $ids[$m[2]] = true;
                    $counts[$tag] = ($counts[$tag] ?? 0) + 1;
                }
            }
            $recordBytes += strlen($line) + 1;
            if ($recordBytes > 4 * 1024 * 1024) { throw new \RuntimeException('Een GEDCOM-record is groter dan 4 MB.'); }
            if (preg_match('/^@([^@\s]+)@$/D', $value, $reference)) { $refs[$reference[1]] = true; }
            if ($level === 1) { $levelOne = $m[3]; }
            if ($tag === 'HEAD' && $level === 2 && $levelOne === 'GEDC' && $m[3] === 'VERS') { $version = $value; }
            if ($tag === 'HEAD' && $level === 1 && $m[3] === 'CHAR') { $encoding = $value; $line = '1 CHAR UTF-8'; }
            $next = preg_match('/^\d+ NAME /', $line) ? preg_replace('/(?<![ \t])\//', ' /', $line) : $line;
            if ($next !== $line) { ++$normalized; }
            if (fwrite($dst, $next . "\n") === false) { throw new \RuntimeException('Genormaliseerd bestand opslaan mislukt.'); }
            if ($lineNumber - $lastProgress >= 10000) { $progress($lineNumber, $records); $lastProgress = $lineNumber; }
        }
        if ($head !== 1 || $trailer !== 1) { throw new \RuntimeException('Exact één HEAD en één TRLR zijn vereist.'); }
        if (!in_array($version, ['5.5', '5.5.1'], true)) { throw new \RuntimeException('Gebruik GEDCOM 5.5 of 5.5.1 voor deze versie van Webtrees.'); }
        if ($counts['INDI'] < 1) { throw new \RuntimeException('Het bestand bevat geen personen.'); }
        $missing = count(array_diff_key($refs, $ids));
        if ($missing) { $warnings[] = $missing . ' verwijzingen wijzen naar een ontbrekend record. Controleer de brongegevens.'; }
        if (!$counts['FAM']) { $warnings[] = 'Dit bestand bevat geen gezinnen.'; }
        if (!$encoding) { $warnings[] = 'Geen CHAR-header; de tekencodering is automatisch bepaald.'; }
        return ['counts'=>$counts, 'records'=>$records, 'lines'=>$lineNumber, 'bytes'=>$size,
            'sha256'=>hash_file('sha256', $input), 'version'=>$version, 'encoding'=>$detectedName,
            'normalized_names'=>$normalized, 'missing_references'=>$missing, 'warnings'=>$warnings];
    } finally { fclose($src); fclose($dst); }
}
