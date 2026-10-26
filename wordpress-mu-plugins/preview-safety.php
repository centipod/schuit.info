<?php
declare(strict_types=1);
if (wp_get_environment_type() !== 'staging') { return; }
add_filter('pre_wp_mail', '__return_false');
add_filter('pre_http_request', static fn () => new WP_Error('preview_external_request', 'Extern verkeer is uitgeschakeld in deze reviewomgeving.'), PHP_INT_MAX);
add_filter('pre_option_blog_public', static fn () => '0');
add_action('send_headers', static function (): void { header('X-Robots-Tag: noindex, nofollow, noarchive'); });
