<?php
declare(strict_types=1);
$c=parse_ini_file('/var/www/html/tree/data/config.ini.php',false,INI_SCANNER_RAW);
$p=new PDO('mysql:host='.$c['dbhost'].';dbname='.$c['dbname'].';charset=utf8mb4',$c['dbuser'],$c['dbpass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$prefix=$c['tblpfx'];if(!preg_match('/^[a-zA-Z0-9_]+$/',$prefix)){throw new RuntimeException('Invalid prefix');}
$counts=['individuals'=>0,'families'=>0,'chunks'=>0,'chunks_with_payload'=>0];
foreach($p->query("SELECT chunk_data FROM {$prefix}gedcom_chunk WHERE imported=1 ORDER BY gedcom_chunk_id") as $row){
 $row['chunk_data'] = (string) $row['chunk_data'];
 $counts['individuals']+=preg_match_all('/(?:^|[\r\n])0 @[^@]+@ INDI(?:[\r\n]|$)/',$row['chunk_data']);
 $counts['families']+=preg_match_all('/(?:^|[\r\n])0 @[^@]+@ FAM(?:[\r\n]|$)/',$row['chunk_data']);
 $counts['chunks']++;
 if ($row['chunk_data'] !== null && $row['chunk_data'] !== '') { $counts['chunks_with_payload']++; }
}
echo json_encode(['retained_import_records'=>$counts['chunks_with_payload'] > 0 ? $counts : null, 'note'=>$counts['chunks_with_payload'] > 0 ? 'Retained imported records counted' : 'Completed import chunks no longer contain the uploaded GEDCOM; use a fresh export for file reconciliation','database'=>['individuals'=>(int)$p->query("SELECT COUNT(*) FROM {$prefix}individuals")->fetchColumn(),'families'=>(int)$p->query("SELECT COUNT(*) FROM {$prefix}families")->fetchColumn()]],JSON_PRETTY_PRINT)."\n";
