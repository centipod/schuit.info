<?php
declare(strict_types=1);
// Initialize only encoding services; never bootstrap a database connection.
use Fisharebest\Webtrees\Factories\EncodingFactory;
use Fisharebest\Webtrees\GedcomFilters\GedcomEncodingFilter;
use Fisharebest\Webtrees\Registry;
require $argv[1] . '/vendor/autoload.php';
Registry::encodingFactory(new EncodingFactory());
stream_filter_register(GedcomEncodingFilter::class, GedcomEncodingFilter::class);
require dirname(__DIR__, 2) . '/wordpress-plugins/schuit-gedcom/includes/jobs.php';
require dirname(__DIR__, 2) . '/wordpress-plugins/schuit-gedcom/includes/analyze.php';
$metrics = Schuit\Gedcom\analyze($argv[2], $argv[3], static function (): void {});
echo json_encode($metrics, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
