<?php
declare(strict_types=1);
// Exercise native webtrees staging inside a rolled-back transaction. No real
// family tree or account is changed; only a synthetic fixture is archived in /tmp.
use Fisharebest\Webtrees\{DB,Registry,Tree,Webtrees};
use Fisharebest\Webtrees\Services\TreeService;
use Psr\Http\Message\StreamFactoryInterface;
require '/var/www/html/tree/vendor/autoload.php';
Webtrees::new()->bootstrap();
$c=parse_ini_file('/var/www/html/tree/data/config.ini.php',false,INI_SCANNER_RAW);
DB::connect($c['dbtype']??'mysql',$c['dbhost'],(string)($c['dbport']??'3306'),$c['dbname'],$c['dbuser'],$c['dbpass'],$c['tblpfx'],'','','',false);
$directory='/tmp/schuit-import-test-'.bin2hex(random_bytes(8));
putenv('SCHUIT_IMPORT_ARCHIVE='.$directory);
$module=require '/var/www/html/tree/modules_v4/schuit-import/module.php';
$module->boot();
$service=Registry::container()->get(TreeService::class);
if(!$service instanceof SchuitImportTreeService){throw new RuntimeException('Import hook not installed');}
$before=[DB::table('individuals')->count(),DB::table('families')->count(),DB::table('gedcom')->count()];
DB::connection()->beginTransaction();
try {
 $id=DB::table('gedcom')->insertGetId(['gedcom_name'=>'integration-'.bin2hex(random_bytes(6)),'title'=>'Synthetic import fixture','media_folder'=>'','gedcom_filename'=>'fixture.ged','imported'=>1,'private'=>1],'gedcom_id');
 $tree=new Tree($id,'fixture','Fixture','','fixture.ged',true,true,null,null);
 $input="0 HEAD\n1 CHAR UTF-8\n0 @I1@ INDI\n1 NAME Zoë/Schuit/\n1 WWW https://example.invalid/a/b\n1 OBJE\n2 FILE media/a.jpg\n0 TRLR\n";
 $stream=Registry::container()->get(StreamFactoryInterface::class)->createStream($input);
 $service->importGedcomFile($tree,$stream,'fixture.ged','UTF-8');
 $chunks=DB::table('gedcom_chunk')->where('gedcom_id',$id)->orderBy('gedcom_chunk_id')->pluck('chunk_data')->implode('');
 $expected=str_replace('Zoë/Schuit/','Zoë /Schuit /',$input);
 if($chunks!==$expected){throw new RuntimeException('Native import stream differs from expected normalization');}
 $files=glob($directory.'/*.ged');
 if(count($files)!==1||file_get_contents($files[0])!==$input||(fileperms($files[0])&0777)!==0600){throw new RuntimeException('Original archive or permissions incorrect');}
 if((int)DB::table('gedcom')->where('gedcom_id',$id)->value('imported')!==0){throw new RuntimeException('Import completion guard not staged');}
} finally {
 DB::connection()->rollBack();
 foreach(glob($directory.'/*')?:[] as $file){unlink($file);}
 if(is_dir($directory)){rmdir($directory);}
}
$after=[DB::table('individuals')->count(),DB::table('families')->count(),DB::table('gedcom')->count()];
if($before!==$after){throw new RuntimeException('Fixture rollback failed');}
echo "Native import hook: original preserved, UTF-8 names normalized, URLs and paths preserved, pending import staged; fixture rolled back.\n";
