<?php
declare(strict_types=1);
/** Explicit WP-CLI editorial migration; defaults to validation, never runs on page views. */
if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Run this editorial migration through WP-CLI.');
}
$rows = json_decode((string) file_get_contents(__DIR__ . '/content/member-feedback-20261029.json'), true, 512, JSON_THROW_ON_ERROR);
$pending = [];
foreach ($rows as $row) {
    $post = get_post((int) $row['ID']);
    if (!$post instanceof WP_Post || $post->post_type !== 'page' || $post->post_name !== $row['post_name']) {
        throw new RuntimeException('Unexpected page identity: ' . $row['ID']);
    }
    if ($post->post_title === $row['post_title'] && $post->post_content === $row['post_content']) {
        continue;
    }
    if (hash('sha256', $post->post_title . "\n" . $post->post_content) !== $row['expected_sha256']) {
        throw new RuntimeException('Page changed since review; refusing overwrite: ' . $row['ID']);
    }
    $pending[] = $row;
}
if (getenv('SCHUIT_APPLY_MEMBER_FEEDBACK') !== 'yes') {
    echo count($pending) . " reviewed pages ready; no changes applied.\n";
    return;
}
foreach ($pending as $row) {
    wp_save_post_revision((int) $row['ID']);
    $result = wp_update_post(wp_slash([
        'ID' => (int) $row['ID'],
        'post_title' => $row['post_title'],
        'post_content' => $row['post_content'],
        'post_excerpt' => '',
    ]), true);
    if (is_wp_error($result)) {
        throw new RuntimeException($result->get_error_message());
    }
}
echo count($pending) . " reviewed pages updated; previous content retained as revisions.\n";
