<?php
declare(strict_types=1);

use Fisharebest\Webtrees\GedcomFilters\GedcomEncodingFilter;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\GedcomImportService;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Tree;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\StreamFactoryInterface;

require_once __DIR__ . '/normalize.php';

class SchuitImportTreeService extends TreeService {
    public function importGedcomFile(Tree $tree, StreamInterface $stream, string $filename, string $encoding): void {
        $directory = getenv('SCHUIT_IMPORT_ARCHIVE') ?: '/var/www/html/tree/data/import-originals';
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
            throw new RuntimeException('Het originele GEDCOM-bestand kon niet veilig worden bewaard.');
        }
        $original = tempnam($directory, 'original-');
        if ($original === false) { throw new RuntimeException('GEDCOM-archief is niet beschikbaar.'); }
        chmod($original, 0600);
        $input = $stream->detach();
        $output = fopen($original, 'wb');
        if ($input === null || $output === false) { throw new RuntimeException('GEDCOM-bestand kon niet worden gelezen.'); }
        try {
            if (stream_copy_to_stream($input, $output) === false) { throw new RuntimeException('GEDCOM-kopie is mislukt.'); }
        } finally { fclose($input); fclose($output); }
        $saved = $directory . '/' . gmdate('Ymd-His') . '-' . hash_file('sha256', $original) . '.ged';
        if (!rename($original, $saved)) { throw new RuntimeException('GEDCOM-archief kon niet worden opgeslagen.'); }
        $input = fopen($saved, 'rb');
        $normalized = tmpfile();
        if ($input === false || $normalized === false) { throw new RuntimeException('GEDCOM-verwerking kon niet starten.'); }
        if (stream_filter_append($input, GedcomEncodingFilter::class, STREAM_FILTER_READ, ['src_encoding' => $encoding]) === false) {
            throw new RuntimeException('GEDCOM-tekencodering kon niet worden gelezen.');
        }
        try {
            while (($line = fgets($input)) !== false) {
                if (fwrite($normalized, schuit_normalize_gedcom_line($line)) === false) { throw new RuntimeException('GEDCOM-verwerking is mislukt.'); }
            }
            rewind($normalized);
            $factory = Registry::container()->get(StreamFactoryInterface::class);
            parent::importGedcomFile($tree, $factory->createStreamFromResource($normalized), $filename, 'UTF-8');
        } finally {
            fclose($input);
            if (is_resource($normalized)) { fclose($normalized); }
        }
    }
}

return new class extends AbstractModule implements ModuleCustomInterface {
    use ModuleCustomTrait;
    public function title(): string { return 'SCHUIT GEDCOM-import'; }
    public function description(): string { return 'Bewaart het origineel en normaliseert naamvelden bij een GEDCOM-import.'; }
    public function customModuleAuthorName(): string { return 'Centipod'; }
    public function customModuleVersion(): string { return '1.0.0'; }
    public function boot(): void {
        if (PHP_SAPI !== 'cli' && getenv('SCHUIT_GEDCOM_JOBS')) {
            $directory = getenv('SCHUIT_GEDCOM_JOBS');
            $lock = fopen($directory . '/webtrees.lock', 'c');
            if (!$lock || is_file($directory . '/maintenance') || !flock($lock, LOCK_SH | LOCK_NB)) {
                http_response_code(503);
                header('Retry-After: 60');
                header('Content-Type: text/html; charset=UTF-8');
                echo '<!doctype html><html lang="nl"><meta name="viewport" content="width=device-width"><title>Stamboom wordt bijgewerkt</title><main style="max-width:640px;margin:12vh auto;padding:24px;font-family:system-ui"><h1>De stamboom wordt bijgewerkt</h1><p>We verwerken een nieuw GEDCOM-bestand. Probeer het over enkele minuten opnieuw. De overige website blijft beschikbaar.</p><a href="/">Terug naar de website</a></main></html>';
                exit;
            }
            register_shutdown_function(static function () use ($lock): void { flock($lock, LOCK_UN); fclose($lock); });
        }
        $container = Registry::container();
        $container->set(TreeService::class, new SchuitImportTreeService($container->get(GedcomImportService::class)));
    }
};
