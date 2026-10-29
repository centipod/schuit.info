<?php
declare(strict_types=1);
require '/var/www/html/tree/vendor/autoload.php';
require __DIR__.'/../wordpress-plugins/schuit-gedcom/includes/jobs.php';
require __DIR__.'/../wordpress-plugins/schuit-gedcom/includes/consistency-pdf.php';
require __DIR__.'/../wordpress-plugins/schuit-gedcom/includes/report-download.php';
use function Schuit\Gedcom\{consistencyPdf,openConsistencyReport};
function assertReport(bool $ok,string $message): void { if (!$ok) { throw new RuntimeException($message); } }
$root=sys_get_temp_dir().'/report-'.bin2hex(random_bytes(8));mkdir($root,0700);putenv('SCHUIT_GEDCOM_JOBS='.$root);
$id=str_repeat('a',32);mkdir($root.'/'.$id,0700);$path=$root.'/'.$id.'/consistency.pdf';
$report=['checked_at'=>'2026-10-29T00:00:00Z','total'=>24,'reported'=>24,'truncated'=>false,'rows'=>[]];
for ($i=1;$i<=24;$i++) { $report['rows'][]=['subject'=>'Aná Voorbeeld en Élise Testpersoon','ids'=>['I'.$i,'F1'],'finding'=>'Geboorte: 1 JAN 1900. Overlijden: 1 JAN 1899. <img src="https://example.invalid/private">','reason'=>'Overlijden ligt vóór geboorte.','solution'=>'Controleer de originele geboorte- en overlijdensakte en de identiteit. Wijzig niets zonder bevestiging.']; }
try {
    consistencyPdf($report,$path);
    assertReport(str_starts_with(file_get_contents($path),'%PDF-'),'PDF generated');
    assertReport((fileperms($path)&0777)===0600,'Private permissions');
    $job=['id'=>$id,'analysis'=>['consistency'=>['pdf_sha256'=>hash_file('sha256',$path)]]];
    $handle=openConsistencyReport($job);assertReport(fread($handle,5)==='%PDF-','Authorized verified stream');fclose($handle);
    if ($destination=getenv('SCHUIT_TEST_PDF')) { copy($path,$destination); }
    file_put_contents($path,'modified',FILE_APPEND);
    try { openConsistencyReport($job);throw new LogicException('Tampered report accepted'); } catch (RuntimeException) {}
    unlink($path);symlink('/etc/passwd',$path);
    try { openConsistencyReport($job);throw new LogicException('Symlink accepted'); } catch (RuntimeException) {}
    unlink($path);
    try { openConsistencyReport($job);throw new LogicException('Missing report accepted'); } catch (RuntimeException) {}
    $job['id']='../escape';
    try { openConsistencyReport($job);throw new LogicException('Traversal accepted'); } catch (RuntimeException) {}
    $report['total']=0;$report['reported']=0;$report['rows']=[];consistencyPdf($report,$path);
    assertReport(str_starts_with(file_get_contents($path),'%PDF-'),'Empty report generated');
    echo "PDF generation, Unicode, private mode, integrity, symlink and traversal checks passed.\n";
} finally { if (is_file($path)||is_link($path)) { unlink($path); }rmdir($root.'/'.$id);rmdir($root); }
