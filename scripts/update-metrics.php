<?php
declare(strict_types=1);
// Run from the container timer. Never publish partial imports or personal records.
$config = parse_ini_file(getenv('WEBTREES_CONFIG_PATH') ?: '/var/www/html/tree/data/config.ini.php', false, INI_SCANNER_RAW);
if (!is_array($config)) { throw new RuntimeException('Webtrees configuration unavailable'); }
$prefix = $config['tblpfx'] ?? 'wt_';
if (!preg_match('/^[a-zA-Z0-9_]+$/', $prefix)) { throw new RuntimeException('Invalid table prefix'); }
$pdo = new PDO('mysql:host=' . $config['dbhost'] . ';port=' . ($config['dbport'] ?? '3306') . ';dbname=' . $config['dbname'] . ';charset=utf8mb4', $config['dbuser'], $config['dbpass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
$pending = (int) $pdo->query("SELECT COUNT(*) FROM {$prefix}gedcom WHERE gedcom_id > 0 AND private = 0 AND imported <> 1")->fetchColumn();
$pending += (int) $pdo->query("SELECT COUNT(*) FROM {$prefix}gedcom_chunk c JOIN {$prefix}gedcom g USING (gedcom_id) WHERE g.gedcom_id > 0 AND g.private = 0 AND c.imported <> 1")->fetchColumn();
if ($pending !== 0) { $pdo->rollBack(); echo "Import in progress: previous totals retained.\n"; exit(0); }
$data = [];
foreach (['individuals' => 'i_file', 'families' => 'f_file'] as $table => $key) {
    $data[$table] = (int) $pdo->query("SELECT COUNT(*) FROM {$prefix}{$table} r JOIN {$prefix}gedcom g ON r.{$key}=g.gedcom_id WHERE g.gedcom_id>0 AND g.private=0 AND g.imported=1")->fetchColumn();
}
$pdo->commit();
$data['updated'] = gmdate('c');
$data['source'] = 'webtrees-database';
$path = getenv('WEBTREES_METRICS_PATH') ?: '/var/www/shared/webtrees/data/metrics.json';
$temp = tempnam(dirname($path), '.metrics-');
if ($temp === false || file_put_contents($temp, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n") === false) { throw new RuntimeException('Metrics write failed'); }
chmod($temp, 0644);
if (!rename($temp, $path)) { throw new RuntimeException('Atomic metrics update failed'); }
echo json_encode($data, JSON_THROW_ON_ERROR) . "\n";
