<?php
declare(strict_types=1);
// Invoked with wp eval 'require "/opt/schuit-scripts/finalize-content.php";'.
// Imports preserve all existing source post IDs.
$contact = get_page_by_path('contact');
$contact_id = wp_insert_post([
    'ID' => $contact instanceof WP_Post ? $contact->ID : 0,
    'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'contact',
    'post_title' => 'Contact',
    'post_content' => '<p>Voor vragen over de stichting, publicaties, de nieuwsbrief of genealogisch onderzoek kunt u contact opnemen met Jan Schuit.</p><h2>Secretariaat en penningmeester</h2><p><a href="mailto:jan@schuit.info">jan@schuit.info</a><br><a href="tel:+31653627933">06 53 62 79 33</a></p><p>Werkt de e-maillink niet? Kopieer dan jan@schuit.info naar uw e-mailprogramma.</p><p><a href="'.esc_url(get_permalink(242)).'">Bekijk het bestuur en de overige contactgegevens</a></p>',
], true);
if (is_wp_error($contact_id)) { throw new RuntimeException($contact_id->get_error_message()); }
foreach ([234, 277] as $id) {
    $post = get_post($id);
    if (!$post) { continue; }
    $content = str_replace(
        ['href="http://www.schuit.info/"', 'href="www.facebook.com/stichting.schuyiijt"'],
        ['href="'.esc_url(home_url('/')).'"', 'href="https://www.facebook.com/stichting.schuyiijt"'],
        $post->post_content
    );
    if ($content !== $post->post_content) { wp_update_post(['ID'=>$id, 'post_content'=>wp_slash($content)]); }
}
update_option('upload_path', ''); update_option('upload_url_path', '');
update_option('permalink_structure', '/%postname%/');
update_option('blog_public', '0');
update_option('stylesheet', 'schuit-portal'); update_option('template', 'schuit-portal');
flush_rewrite_rules(false);
delete_transient('schuit_portal_webtrees_metrics');
echo "Contact page and portal settings updated.\n";
