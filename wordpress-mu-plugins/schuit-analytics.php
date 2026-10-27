<?php
/** Plugin Name: SCHUIT Analytics — opt-in visitor statistics */
declare(strict_types=1);
if (!defined('ABSPATH')) { return; }
add_action('wp_enqueue_scripts', static function (): void {
    // Admin, authenticated sessions and previews must never be measured.
    if (is_admin() || is_user_logged_in() || is_preview()) { return; }
    wp_enqueue_script('schuit-analytics', content_url('/mu-plugins/schuit-analytics.js'), [], '1.0.0', true);
});
