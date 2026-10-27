<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('CI_FIXTURE') !== 'synthetic-only') {throw new RuntimeException('Fixture-only command');}
$dbpass=getenv('CI_DB_PASSWORD');$password=getenv('CI_LOGIN_PASSWORD');
$db=new PDO('mysql:host=db','root',$dbpass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
foreach (['wp_ci','wt_ci'] as $name) {$db->exec('CREATE DATABASE IF NOT EXISTS '.$name.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');}
$config="<?php\n";
foreach (['DB_HOST'=>'db','DB_NAME'=>'wp_ci','DB_USER'=>'root','DB_PASSWORD'=>$dbpass,'DB_CHARSET'=>'utf8mb4','WP_HOME'=>'https://localhost:19443','WP_SITEURL'=>'https://localhost:19443','WP_ENVIRONMENT_TYPE'=>'staging'] as $k=>$v) {$config.='define('.var_export($k,true).','.var_export($v,true).");\n";}
foreach (['AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT'] as $k) {$config.='define('.var_export($k,true).','.var_export(bin2hex(random_bytes(32)),true).");\n";}
$config.='define("FORCE_SSL_ADMIN",true); define("DISABLE_WP_CRON",true); define("DISALLOW_FILE_MODS",true); define("DISALLOW_FILE_EDIT",true); $table_prefix="wp_"; $_SERVER["HTTPS"]="on"; if (!defined("ABSPATH")) {define("ABSPATH","/var/www/html/");} require ABSPATH."wp-settings.php";';
file_put_contents('/var/www/html/wp-config.php',$config);
define('WP_INSTALLING',true); require '/var/www/html/wp-load.php'; require '/var/www/html/wp-admin/includes/upgrade.php';
if (is_blog_installed()) {throw new RuntimeException('Refusing to reuse an existing fixture database');}
wp_install('SCHUIT CI','ci_admin','ci_admin@example.invalid',false,'',$password,'en_US');
require_once '/var/www/html/wp-admin/includes/plugin.php';activate_plugin('schuit-gedcom/schuit-gedcom.php');switch_theme('schuit-portal');
foreach (['ci_manager'=>'schuit_gedcom_manager','ci_other'=>'schuit_gedcom_manager','ci_reader'=>'subscriber'] as $name=>$role) {wp_insert_user(['user_login'=>$name,'user_pass'=>$password,'user_email'=>$name.'@example.invalid','role'=>$role]);}
update_option('permalink_structure','/%postname%/');update_option('users_can_register',0);
foreach (['Home'=>'home','Publicaties'=>'publicaties','De stichting'=>'about','Contact'=>'contact'] as $title=>$slug) {wp_insert_post(['post_title'=>$title,'post_name'=>$slug,'post_content'=>'Fictieve CI-inhoud.','post_status'=>'publish','post_type'=>'page']);}
$treeconfig="; <?php exit; ?> DO NOT DELETE THIS LINE\n";
foreach (['dbtype'=>'mysql','dbhost'=>'db','dbport'=>'3306','dbname'=>'wt_ci','dbuser'=>'root','dbpass'=>$dbpass,'tblpfx'=>'wt_','base_url'=>'https://localhost:19443/tree','rewrite_urls'=>'1'] as $k=>$v) {$treeconfig.=$k.'="'.str_replace('"','\\"',$v)."\"\n";}
file_put_contents('/var/www/html/tree/data/config.ini.php',$treeconfig);
echo "Synthetic WordPress fixture initialized.\n";
