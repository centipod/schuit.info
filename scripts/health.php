<?php
declare(strict_types=1);
require '/var/www/html/wp-load.php';
global $wpdb;
if ((int) $wpdb->get_var('SELECT 1') !== 1) { exit(1); }
$c = parse_ini_file('/var/www/html/tree/data/config.ini.php');
$p = new PDO('mysql:host='.$c['dbhost'].';dbname='.$c['dbname'], $c['dbuser'], $c['dbpass']);
if (!is_file('/run/secrets/schuit-cognito.json') || @file_get_contents('http://cognito:8081/health',false,stream_context_create(['http'=>['timeout'=>2]]))===false) {exit(1);}
exit((int) $p->query('SELECT 1')->fetchColumn() === 1 ? 0 : 1);
