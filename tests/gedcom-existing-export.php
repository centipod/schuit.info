<?php
declare(strict_types=1);
define('SCHUIT_GEDCOM_TEST_LIBRARY', true);
require dirname(__DIR__) . '/scripts/gedcom-worker.php';
$input = $argv[1];
$temporary = tempnam('/tmp','schuit-export-analysis-');
try {
    $metrics = Schuit\Gedcom\analyze($input,$temporary,static function ():void {});
    $current = baseline(target());
    foreach(['INDI','FAM'] as $tag){if($metrics['counts'][$tag]!==$current['counts'][$tag]){throw new RuntimeException('Export/database count mismatch');}}
    echo json_encode(['analysis_only'=>true,'counts'=>$metrics['counts'],'records'=>$metrics['records'],
        'encoding'=>$metrics['encoding'],'version'=>$metrics['version'],'normalized_names'=>$metrics['normalized_names'],
        'missing_references'=>$metrics['missing_references'],'warnings'=>$metrics['warnings']],JSON_PRETTY_PRINT)."\n";
} finally { unlink($temporary); }
