<?php
declare(strict_types=1);
// Synthetic private tree inside an OUTER rollback. Never replace the real tree.
use Fisharebest\Webtrees\{DB,Tree};
use function Schuit\Gedcom\{analyze,write};
define('SCHUIT_GEDCOM_TEST_LIBRARY',true);
$folder='/tmp/schuit-transaction-'.bin2hex(random_bytes(8)); mkdir($folder,0700);
putenv('SCHUIT_GEDCOM_JOBS='.$folder);
require dirname(__DIR__).'/scripts/gedcom-worker.php';
$before=[DB::table('gedcom')->count(),DB::table('individuals')->count(),DB::table('families')->count()];
DB::connection()->beginTransaction();
try {
    $id=DB::table('gedcom')->insertGetId(['gedcom_name'=>'fixture-'.bin2hex(random_bytes(8)), 'title'=>'Synthetic workflow fixture',
        'media_folder'=>'','gedcom_filename'=>'fixture.ged','imported'=>1,'private'=>1],'gedcom_id');
    $tree=Tree::fromDB(DB::table('gedcom')->where('gedcom_id',$id)->first());
    $jobId=bin2hex(random_bytes(16)); mkdir($folder.'/'.$jobId,0700);
    $original="0 HEAD\n1 SOUR Fixture\n1 GEDC\n2 VERS 5.5.1\n2 FORM LINEAGE-LINKED\n1 CHAR UTF-8\n0 @I1@ INDI\n1 NAME Zoë/Schuit/\n1 FAMS @F1@\n0 @F1@ FAM\n1 HUSB @I1@\n0 TRLR\n";
    file_put_contents($folder.'/'.$jobId.'/original.ged',$original);
    $analysis=analyze($folder.'/'.$jobId.'/original.ged',$folder.'/'.$jobId.'/normalized.ged',static function ():void {});
    $job=['id'=>$jobId,'state'=>'backing_up','filename'=>'fixture.ged','baseline'=>baseline($tree),'analysis'=>$analysis,
        'normalized_sha256'=>hash_file('sha256',$folder.'/'.$jobId.'/normalized.ged')];
    write($job); processImport($job,$tree);
    if($job['state']!=='completed'||!imported($jobId,$tree)||DB::table('individuals')->where('i_file',$id)->count()!==1){throw new Exception('Import did not complete');}
    $record=DB::table('individuals')->where('i_file',$id)->value('i_gedcom');
    if(!str_contains($record,'Zoë /Schuit /')){throw new Exception('Name not normalized');}
    // Deliberate post-import count mismatch must roll back ALL replacement work.
    $job['baseline']=baseline($tree);$job['analysis']['counts']['INDI']=2;$failed=false;
    try { processImport($job,$tree); } catch(RuntimeException $e){$failed=true;}
    if(!$failed||DB::table('individuals')->where('i_file',$id)->value('i_gedcom')!==$record){throw new Exception('Rollback did not preserve old data');}
    $job['analysis']['counts']['INDI']=1;
    file_put_contents($folder.'/'.$jobId.'/normalized.ged',"\n",FILE_APPEND); $failed=false;
    try { processImport($job,$tree); } catch(RuntimeException $e){$failed=true;}
    if(!$failed){throw new Exception('Modified file was accepted');}
    echo "Synthetic native import, verified counts, completion marker, rollback on mismatch, and tamper rejection: passed.\n";
} finally {
    while(DB::connection()->transactionLevel()>0){DB::connection()->rollBack();}
    foreach(glob($folder.'/*') as $entry){if(is_dir($entry)){foreach(glob($entry.'/*') as $f){unlink($f);}rmdir($entry);}else{unlink($entry);}}rmdir($folder);
}
$after=[DB::table('gedcom')->count(),DB::table('individuals')->count(),DB::table('families')->count()];
if($before!==$after){throw new Exception('Live database changed');}
echo "Outer transaction rolled back; original tree/record counts unchanged.\n";
