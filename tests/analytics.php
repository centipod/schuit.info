<?php
declare(strict_types=1);
define('ABSPATH', '/test/');
$hooks = []; $scripts = []; $admin = false; $logged = false; $preview = false;
function add_action($name, $callback): void { global $hooks; $hooks[$name] = $callback; }
function is_admin(): bool { global $admin; return $admin; }
function is_user_logged_in(): bool { global $logged; return $logged; }
function is_preview(): bool { global $preview; return $preview; }
function content_url($path): string { return 'https://schuit.centipod.ai/wp-content' . $path; }
function wp_enqueue_script(...$args): void { global $scripts; $scripts[] = $args; }
require dirname(__DIR__) . '/wordpress-mu-plugins/schuit-analytics.php';
foreach ([[false,false,false,1],[true,false,false,0],[false,true,false,0],[false,false,true,0]] as [$admin,$logged,$preview,$expected]) {
    $scripts=[]; $hooks['wp_enqueue_scripts']();
    if (count($scripts)!==$expected) { throw new RuntimeException('Analytics authentication boundary failed'); }
    if ($scripts && (!str_contains($scripts[0][1], '/mu-plugins/schuit-analytics.js') || !$scripts[0][4])) { throw new RuntimeException('Unexpected script source or placement'); }
}
echo "Analytics authentication boundaries passed\n";
