<?php
/** Read-only identity inventory. Run inside schuit-web and redirect to a private file. No password hashes. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){throw new RuntimeException('CLI only');}
require '/var/www/html/wp-load.php';
$result=['wordpress'=>[],'webtrees'=>[]];
foreach(get_users(['fields'=>'all']) as $u){$result['wordpress'][]=['id'=>(int)$u->ID,'username'=>$u->user_login,'email'=>$u->user_email,'roles'=>$u->roles];}
$c=parse_ini_file('/var/www/html/tree/data/config.ini.php');$db=new PDO('mysql:host='.$c['dbhost'].';dbname='.$c['dbname'],$c['dbuser'],$c['dbpass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$prefix=$c['tblpfx']??'wt_';if(!preg_match('/^[a-zA-Z0-9_]+$/D',$prefix)){throw new RuntimeException('Invalid prefix');}
foreach($db->query('SELECT user_id,user_name,email FROM '.$prefix.'user WHERE user_id>0') as $u){
 $q=$db->prepare('SELECT setting_name,setting_value FROM '.$prefix.'user_setting WHERE user_id=? AND setting_name IN (?,?,?)');$q->execute([$u['user_id'],'verified','verified_by_admin','canadmin']);$settings=$q->fetchAll(PDO::FETCH_KEY_PAIR);
 $result['webtrees'][]=['id'=>(int)$u['user_id'],'username'=>$u['user_name'],'email'=>$u['email'],'verified'=>($settings['verified']??'')==='1','approved'=>($settings['verified_by_admin']??'')==='1','administrator'=>($settings['canadmin']??'')==='1'];
}
echo json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
