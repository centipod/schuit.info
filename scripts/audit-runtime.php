<?php
// Aggregate-only security evidence: never print passwords, email or genealogy.
declare(strict_types=1);
require '/var/www/html/wp-load.php';
global $wpdb;
$ini = parse_ini_file('/var/www/html/tree/data/config.ini.php');
$db = new PDO('mysql:host=' . $ini['dbhost'] . ';dbname=' . $ini['dbname'], $ini['dbuser'], $ini['dbpass']);
$settings = $db->query("SELECT setting_name, setting_value FROM wt_gedcom_setting WHERE gedcom_id=7 AND setting_name IN ('HIDE_LIVE_PEOPLE','SHOW_LIVING_NAMES','SHOW_DEAD_PEOPLE','SHOW_PRIVATE_RELATIONSHIPS')")->fetchAll(PDO::FETCH_KEY_PAIR);
echo json_encode([
    'wordpress' => get_bloginfo('version'), 'php' => PHP_VERSION,
    'registration' => (bool)get_option('users_can_register'),
    'file_modifications_disabled' => defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS,
    'file_editor_disabled' => defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT,
    'staging_email_blocked' => has_filter('pre_wp_mail') !== false,
    'wordpress_users' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}"),
    'webtrees_users' => (int)$db->query('SELECT COUNT(*) FROM wt_user')->fetchColumn(),
    'individuals' => (int)$db->query('SELECT COUNT(*) FROM wt_individuals WHERE i_file=7')->fetchColumn(),
    'families' => (int)$db->query('SELECT COUNT(*) FROM wt_families WHERE f_file=7')->fetchColumn(),
    'webtrees_privacy_settings' => $settings,
], JSON_PRETTY_PRINT) . PHP_EOL;
