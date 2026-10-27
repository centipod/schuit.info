<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('CI_FIXTURE') !== 'synthetic-only') {throw new RuntimeException('Fixture-only command');}
use Fisharebest\Webtrees\{DB,I18N,Registry,Tree,Webtrees,Site};
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\Services\{MigrationService,GedcomImportService,UserService};
chdir('/var/www/html/tree');require 'vendor/autoload.php';Webtrees::new()->bootstrap();I18N::init('en-US',true);
DB::connect('mysql','db','3306','wt_ci','root',getenv('CI_DB_PASSWORD'),'wt_','','','',false);
$m=new MigrationService();$m->updateSchema('\\Fisharebest\\Webtrees\\Schema','WT_SCHEMA_VERSION',Webtrees::SCHEMA_VERSION);$m->seedDatabase();
DB::table('gedcom')->insert(['gedcom_id'=>1,'gedcom_name'=>'0','title'=>'Synthetic CI family tree','media_folder'=>'','gedcom_filename'=>'fixture.ged','imported'=>1,'private'=>0]);
$tree=Tree::fromDB(DB::table('gedcom')->where('gedcom_id',1)->first());
foreach (['THEME_DIR'=>'_schuit-modern-theme_','HIDE_LIVE_PEOPLE'=>'1','SHOW_LIVING_NAMES'=>'1','SHOW_DEAD_PEOPLE'=>'2','SHOW_PRIVATE_RELATIONSHIPS'=>'1'] as $k=>$v) {$tree->setPreference($k,$v);}
Site::setPreference('THEME_DIR','_schuit-modern-theme_');
Site::setPreference('LANGUAGE','en-US');Site::setPreference('DEFAULT_GEDCOM','0');Site::setPreference('ALLOW_USER_REGISTRATION','0');
$user=Registry::container()->get(UserService::class)->create('ci_tree_admin','CI administrator','ci_tree_admin@example.invalid',getenv('CI_LOGIN_PASSWORD'));
foreach ([UserInterface::PREF_IS_EMAIL_VERIFIED,UserInterface::PREF_IS_ACCOUNT_APPROVED,UserInterface::PREF_IS_ADMINISTRATOR] as $k) {$user->setPreference($k,'1');}
$s=Registry::container()->get(GedcomImportService::class);
foreach (preg_split('/\n(?=0 )/',trim(file_get_contents('/opt/schuit-repo/tests/ci/baseline.ged'))) as $record) {$s->importRecord($record,$tree,false);}
$s->importRecord("0 @LIVING@ INDI\n1 NAME Synthetic /Living/\n1 BIRT\n2 DATE 1 JAN 2026",$tree,false);
echo "Synthetic Webtrees fixture initialized.\n";
