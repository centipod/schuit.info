<?php
declare(strict_types=1);
// Explicit administrator operation after a verified backup; never runs on page views.
require '/var/www/html/wp-load.php';
$page = get_page_by_path('privacyreglement');
if (!$page instanceof WP_Post) { throw new RuntimeException('Privacy page missing'); }
$notice = file_get_contents('/opt/schuit-repo/docs/privacy/technical-notice.html');
if ($notice === false) { throw new RuntimeException('Reviewed notice missing'); }
if (str_contains($page->post_content, 'schuit-privacy-technical-v1')) { echo "Already updated\n"; exit; }
// Preserve the original verbatim in a private revision, not as current policy on the public page.
wp_save_post_revision($page->ID);
$id = wp_update_post(['ID'=>$page->ID, 'post_content'=>$notice], true);
if (is_wp_error($id)) { throw new RuntimeException('Privacy update failed'); }
echo "Privacy technical notice updated\n";
