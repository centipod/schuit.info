<?php
declare(strict_types=1);
// Operator-only settings maintenance, passed to PHP over stdin inside schuit-web.
// Save the default audit output privately before using --apply.
if (PHP_SAPI !== 'cli') { exit(1); }
define('WP_ADMIN', true);
require '/var/www/html/wp-load.php';
if (!in_array('nl_NL', get_available_languages(), true)) {
    throw new RuntimeException('Install the official Dutch WordPress catalogs first.');
}
$config = parse_ini_file('/var/www/html/tree/data/config.ini.php', false, INI_SCANNER_RAW);
$prefix = $config['tblpfx'] ?? 'wt_';
if (!preg_match('/^[a-zA-Z0-9_]+$/', $prefix)) { throw new RuntimeException('Invalid prefix'); }
$db = new PDO('mysql:host=' . $config['dbhost'] . ';port=' . ($config['dbport'] ?? '3306') . ';dbname=' . $config['dbname'] . ';charset=utf8mb4', $config['dbuser'], $config['dbpass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$snapshot = static function () use ($db, $prefix): array {
    $users = [];
    foreach (get_users(['fields'=>'ID']) as $id) {
        $users[(int)$id] = get_user_meta((int)$id, 'locale', true);
    }
    return [
        'wordpress_site'=>get_option('WPLANG'),
        'wordpress_users'=>$users,
        'webtrees_site'=>$db->query("SELECT * FROM {$prefix}site_setting WHERE setting_name='LANGUAGE'")->fetchAll(PDO::FETCH_ASSOC),
        'webtrees_users'=>$db->query("SELECT * FROM {$prefix}user_setting WHERE setting_name='language'")->fetchAll(PDO::FETCH_ASSOC),
        'webtrees_modules'=>$db->query("SELECT module_name,status FROM {$prefix}module WHERE module_name LIKE 'language-%' ORDER BY module_name")->fetchAll(PDO::FETCH_ASSOC),
    ];
};
$before = $snapshot();
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--restore-module-statuses=')) {
        $saved = json_decode(file_get_contents(substr($argument, strlen('--restore-module-statuses='))), true, 512, JSON_THROW_ON_ERROR);
        $restore = $db->prepare("UPDATE {$prefix}module SET status=? WHERE module_name=?");
        $db->beginTransaction();
        try {
            foreach ($saved['before']['webtrees_modules'] as $module) {
                if (!str_starts_with($module['module_name'], 'language-') || !in_array($module['status'], ['enabled','disabled'], true)) {
                    throw new RuntimeException('Invalid saved language module');
                }
                $restore->execute([$module['status'], $module['module_name']]);
            }
            $db->commit();
        } catch (Throwable $error) { $db->rollBack(); throw $error; }
    }
}
if (in_array('--apply', $argv, true)) {
    if (!(int)$db->query("SELECT COUNT(*) FROM {$prefix}module WHERE module_name='language-nl'")->fetchColumn()) {
        throw new RuntimeException('Dutch webtrees module is missing');
    }
    $db->beginTransaction();
    try {
        $db->exec("INSERT INTO {$prefix}site_setting (setting_name,setting_value) VALUES ('LANGUAGE','nl') ON DUPLICATE KEY UPDATE setting_value='nl'");
        $db->exec("INSERT INTO {$prefix}user_setting (user_id,setting_name,setting_value) SELECT user_id,'language','nl' FROM {$prefix}user ON DUPLICATE KEY UPDATE setting_value='nl'");
        $db->exec("UPDATE {$prefix}module SET status='enabled' WHERE module_name='language-nl'");
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack(); throw $error;
    }
    update_option('WPLANG', 'nl_NL');
    foreach (array_keys($before['wordpress_users']) as $id) { update_user_meta($id, 'locale', 'nl_NL'); }
}
echo json_encode(['applied'=>in_array('--apply', $argv, true), 'before'=>$before, 'after'=>$snapshot()], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
