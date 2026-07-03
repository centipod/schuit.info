<?php

declare(strict_types=1);

function schuit_portal_setup(): void {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('custom-logo');
    add_theme_support('html5', ['comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script']);

    register_nav_menus([
        'primary' => __('Hoofdmenu', 'schuit-portal'),
        'footer' => __('Footer menu', 'schuit-portal'),
    ]);
}
add_action('after_setup_theme', 'schuit_portal_setup');

function schuit_portal_assets(): void {
    $stylesheet = get_stylesheet_directory() . '/style.css';
    wp_enqueue_style('schuit-portal-style', get_stylesheet_uri(), [], (string) filemtime($stylesheet));
}
add_action('wp_enqueue_scripts', 'schuit_portal_assets');

function schuit_portal_fallback_menu(): void {
    $items = [
        ['label' => 'Home', 'url' => home_url('/')],
        ['label' => 'Familieboom', 'url' => home_url('/tree/')],
        ['label' => 'Familietakken', 'url' => home_url('/familietakken/')],
        ['label' => 'Verhalen', 'url' => home_url('/verhalen/')],
        ['label' => 'Foto\'s en archief', 'url' => home_url('/archief/')],
        ['label' => 'Bronnen en onderzoek', 'url' => home_url('/bronnen/')],
    ];

    echo '<ul>';
    foreach ($items as $item) {
        printf(
            '<li><a href="%s">%s</a></li>',
            esc_url($item['url']),
            esc_html($item['label'])
        );
    }
    echo '</ul>';
}

function schuit_portal_fallback_content(string $slug): array {
    $pages = [
        'familietakken' => [
            'title' => 'Familietakken',
            'content' => '<p>In dit bestand staan de gegevens van de naam SCHUIT in alle variaties.</p>',
        ],
        'verhalen' => [
            'title' => 'Verhalen',
            'content' => '<p>Ons blad "In hetzelfde Schu-y-i-ij-tje" verschijnt december 2023 voor de laatste keer.</p>',
        ],
        'archief' => [
            'title' => 'Foto\'s en archief',
            'content' => '<p>Alle nummers van het blad zijn digitaal beschikbaar en opvraagbaar via jan@schuit.info.</p>',
        ],
        'bronnen' => [
            'title' => 'Bronnen en onderzoek',
            'content' => '<p>De Stichting verzamelt gegevens, feiten, verhalen en beelden uit het heden en verleden.</p>',
        ],
        'over' => [
            'title' => 'Over het project',
            'content' => '<p>De Stichting verzamelt gegevens, feiten, verhalen en beelden uit het heden en verleden om uiteindelijk de geschiedenis van alle geslachten met de naam Schuyt, Schuit of Schuijt vast te leggen.</p>',
        ],
        'contact' => [
            'title' => 'Contact',
            'content' => '<p>Voor meer informatie over de Stichting, het maandblad, de website of het donateursschap, kunt u contact met ons opnemen via de mail jan@schuit.info.</p>',
        ],
        'privacy' => [
            'title' => 'Privacy',
            'content' => '<p>Wel met in achtneming van de privacyregels. Dat betekent 100 jaar voor de geboortes, 75 jaar voor de huwelijken en 50 jaar voor de overlijdens.</p>',
        ],
    ];

    return $pages[$slug] ?? [
        'title' => '',
        'content' => '',
    ];
}
