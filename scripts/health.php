<?php
declare(strict_types=1);
require '/var/www/html/wp-load.php';
global $wpdb;
if ((int) $wpdb->get_var('SELECT 1') !== 1) { exit(1); }
$c = parse_ini_file('/var/www/html/tree/data/config.ini.php');
$p = new PDO('mysql:host='.$c['dbhost'].';dbname='.$c['dbname'], $c['dbuser'], $c['dbpass']);
if ((int) $p->query('SELECT 1')->fetchColumn() !== 1) { exit(1); }
$context = stream_context_create(['http' => ['timeout' => 2, 'follow_location' => 0]]);
$ready = @file_get_contents('http://recaptcha:8081/health', false, $context, 0, 1024);
$state = $ready === false ? null : json_decode($ready, true);
exit(is_array($state) && ($state['ready'] ?? null) === true ? 0 : 1);
