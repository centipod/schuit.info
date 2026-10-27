<?php
// SPDX-License-Identifier: GPL-3.0-or-later
declare(strict_types=1);
// Characterization probes for upstream ExtendedImportExport. Synthetic data only.
// Usage: php review-upstream.php WEBTREES_ROOT MODULE_ROOT
// Does not bootstrap webtrees, connect to a DB, or modify either project.
use Jefferson49\Webtrees\Module\ExtendedImportExport\AbstractGedcomFilter;
use Jefferson49\Webtrees\Module\ExtendedImportExport\FilteredGedcomExportService;
use Jefferson49\Webtrees\Module\ExtendedImportExport\GEDCOM_7_GedcomFilter;
use Jefferson49\Webtrees\Module\ExtendedImportExport\OptimizeWebtreesGEDCOM_7_GedcomFilter;
use Nyholm\Psr7\Factory\Psr17Factory;

require $argv[1] . '/vendor/autoload.php';
$module = realpath($argv[2]);
spl_autoload_register(static function (string $class) use ($module): void {
    $prefix = 'Jefferson49\\Webtrees\\Module\\ExtendedImportExport\\';
    if (str_starts_with($class, $prefix)) {
        $name = substr($class, strlen($prefix));
        foreach (['/', '/resources/filter/'] as $folder) {
            $path = $module . $folder . $name . '.php';
            if (is_file($path)) { require $path; return; }
        }
    }
    $commonPrefix = 'Jefferson49\\Webtrees\\';
    if (str_starts_with($class, $commonPrefix)) {
        $path = $module . '/vendor/jefferson49/webtrees-common/' . str_replace('\\', '/', substr($class, strlen($commonPrefix))) . '.php';
        if (is_file($path)) { require $path; }
    }
});

$factory = new Psr17Factory();
$service = new FilteredGedcomExportService($factory, $factory);
$filter = new GEDCOM_7_GedcomFilter();
function convert(array $records, array $filters): string {
    global $service;
    $matched = [];
    return implode('', $service->applyGedcomFilters($records, $filters, $matched));
}
$head = "0 HEAD\n1 SOUR SYNTHETIC\n1 GEDC\n2 VERS 5.5.1\n2 FORM LINEAGE-LINKED\n1 CHAR UTF-8\n";
$cases = [
    'baseline_551' => [$head, "0 @I1@ INDI\n1 NAME Test /Example/\n1 NOTE @N1@\n", "0 @N1@ NOTE Shared text\n", "0 TRLR\n"],
    'source_55_header' => [str_replace('5.5.1', '5.5', $head), "0 @I1@ INDI\n1 NAME Test /Example/\n", "0 TRLR\n"],
    'literal_at_signs' => [$head, "0 @I1@ INDI\n1 NOTE Contact test@@example.org\n", "0 TRLR\n"],
    'dual_year_single_digit' => [$head, "0 @I1@ INDI\n1 BIRT\n2 DATE 30 JAN 1648/9\n", "0 TRLR\n"],
    'phrase_only_date' => [$head, "0 @I1@ INDI\n1 BIRT\n2 DATE (during winter)\n", "0 TRLR\n"],
    'legacy_calendar' => [$head, "0 @I1@ INDI\n1 BIRT\n2 DATE @#DROMAN@ 71\n", "0 TRLR\n"],
    'deep_shared_note' => [$head, "0 @I1@ INDI\n1 BIRT\n2 SOUR @S1@\n3 DATA\n4 NOTE @N1@\n", "0 @N1@ NOTE Shared text\n", "0 @S1@ SOUR\n1 TITL Test\n", "0 TRLR\n"],
    'privacy_optimization' => [$head, "0 @I1@ INDI\n1 NAME Test /Example/\n1 NOTE Private note\n2 RESN confidential\n", "0 TRLR\n"],
];
$out = [];
foreach ($cases as $name => $records) {
    $filters = $name === 'privacy_optimization' ? [new OptimizeWebtreesGEDCOM_7_GedcomFilter(), $filter] : [$filter];
    $out[$name] = convert($records, $filters);
}
class RuntimeFailureProbe extends AbstractGedcomFilter {
    protected const GEDCOM_FILTER_RULES = [
        'INDI:NOTE' => ['(a+)+$' => 'replaced'],
        '*' => [],
    ];
}
$before = ini_get('pcre.backtrack_limit');
try {
    ini_set('pcre.backtrack_limit', '100');
    $out['regex_resource_failure'] = convert(["0 @I1@ INDI\n1 NOTE " . str_repeat('a', 50) . "!\n"], [new RuntimeFailureProbe()]);
} finally {
    ini_set('pcre.backtrack_limit', $before);
}
echo json_encode(['scope' => 'Actual filter engine only; no HTTP or database import test', 'cases' => $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
