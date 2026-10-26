<?php
declare(strict_types=1);
// WEBTREES_ROOT points to an unmodified webtrees release. No DB or live tree needed.
use Fisharebest\Webtrees\Webtrees;
use function Schuit\Gedcom\analyze;
require dirname(__DIR__) . '/wordpress-plugins/schuit-gedcom/includes/jobs.php';
require dirname(__DIR__) . '/wordpress-plugins/schuit-gedcom/includes/analyze.php';
$webtrees = getenv('WEBTREES_ROOT') ?: '/var/www/html/tree';
chdir($webtrees); require 'vendor/autoload.php'; Webtrees::new()->bootstrap();
$folder = sys_get_temp_dir() . '/schuit-analysis-' . bin2hex(random_bytes(8)); mkdir($folder,0700);
$header = "0 HEAD\n1 SOUR Fixture\n2 VERS 9.9\n1 GEDC\n2 VERS 5.5.1\n1 CHAR UTF-8\n";
$valid = $header . "0 @I1@ INDI\n1 NAME Zoë/Schuit/\n1 WWW https://example.invalid/a/b\n1 OBJE\n2 FILE media/a.jpg\n1 FAMS @F1@\n0 @F1@ FAM\n1 HUSB @I1@\n0 TRLR\n";
$tested = [];
try {
    foreach (['LF'=>$valid,'CRLF'=>str_replace("\n","\r\n",$valid),'CR'=>str_replace("\n","\r",$valid),
        'UTF16'=>"\xFF\xFE" . mb_convert_encoding(str_replace('CHAR UTF-8','CHAR UNICODE',$valid),'UTF-16LE','UTF-8'),
        'CP1252'=>mb_convert_encoding(str_replace('CHAR UTF-8','CHAR CP1252',$valid),'Windows-1252','UTF-8')] as $name=>$data) {
        file_put_contents($folder.'/input.ged',$data);
        $a = analyze($folder.'/input.ged',$folder.'/normalized.ged',static function ():void {});
        if ($a['counts']['INDI']!==1 || $a['counts']['FAM']!==1 || $a['version']!=='5.5.1' || $a['normalized_names']!==1 || $a['missing_references']!==0) { throw new Exception('Metrics incorrect: '.$name); }
        $out = file_get_contents($folder.'/normalized.ged');
        if (!str_contains($out,'Zoë /Schuit /') || !str_contains($out,'https://example.invalid/a/b') || !str_contains($out,'media/a.jpg')) { throw new Exception('Normalization incorrect: '.$name.' '.json_encode($out)); }
        if (hash_file('sha256',$folder.'/input.ged')!==hash('sha256',$data)) { throw new Exception('Original changed'); }
        $tested[]=$name;
    }
    foreach (['duplicate'=>$header."0 @I1@ INDI\n0 @I1@ INDI\n0 TRLR\n",'truncated'=>str_replace("0 TRLR\n",'',$valid),
        'trailing'=>$valid."0 @I2@ INDI\n",'empty'=>$header."0 TRLR\n",'not-gedcom'=>'<html>This is not a genealogy file</html>',
        'version7'=>str_replace('5.5.1','7.0',$valid),'level-jump'=>str_replace('1 NAME','3 NAME',$valid),
        'long-line'=>$header."0 @I1@ INDI\n1 NOTE ".str_repeat('x',140000)."\n0 TRLR\n"] as $name=>$data) {
        file_put_contents($folder.'/input.ged',$data); $failed=false;
        try { analyze($folder.'/input.ged',$folder.'/normalized.ged',static function ():void {}); } catch (Throwable) { $failed=true; }
        if (!$failed) { throw new Exception('Accepted invalid fixture: '.$name); }
        $tested[]=$name;
    }
    echo json_encode(['analysis_tests'=>count($tested),'passed'=>$tested],JSON_PRETTY_PRINT)."\n";
} finally { foreach(glob($folder.'/*') as $file){unlink($file);} rmdir($folder); }
