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
        $container = Registry::container();
        $container->set(TreeService::class, new SchuitImportTreeService($container->get(GedcomImportService::class)));
    }
};
