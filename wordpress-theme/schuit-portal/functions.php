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

function schuit_portal_card_excerpt(string $content, int $words = 24): string {
    $excerpt = trim(wp_strip_all_tags($content));

    if ($excerpt === '') {
        return '';
    }

    return wp_trim_words($excerpt, $words, '…');
}

function schuit_portal_card_image_url(int $post_id, string $fallback): string {
    $thumbnail = get_the_post_thumbnail_url($post_id, 'large');

    return $thumbnail ?: $fallback;
}

function schuit_portal_home_news_cards(int $limit = 3): array {
    $cards = [];
    $query = new WP_Query([
        'post_type' => 'post',
        'posts_per_page' => $limit,
        'ignore_sticky_posts' => true,
    ]);

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();
            $cards[] = [
                'eyebrow' => get_the_date('j F Y', $post_id),
                'title' => get_the_title($post_id),
                'text' => schuit_portal_card_excerpt(get_the_content('', false, $post_id), 26),
                'url' => get_permalink($post_id),
                'image' => schuit_portal_card_image_url($post_id, home_url('/banner.png')),
            ];
        }

        wp_reset_postdata();
    }

    if ($cards !== []) {
        return array_slice($cards, 0, $limit);
    }

    return array_slice([
        [
            'eyebrow' => '18 mei 2024',
            'title' => 'Familie Reünie 2024 in Hoorn',
            'text' => 'Plannen voor een volgende familie-bijeenkomst blijven hier als achtergrondinformatie beschikbaar.',
            'url' => home_url('/schuit/?cat=5'),
            'image' => home_url('/banner.png'),
        ],
        [
            'eyebrow' => '27 april 2024',
            'title' => 'Nieuwe documenten toegevoegd',
            'text' => 'Historische documenten en foto’s uit het archief worden stapsgewijs samengebracht in het portal.',
            'url' => home_url('/schuit/?cat=5'),
            'image' => home_url('/logo.png'),
        ],
        [
            'eyebrow' => '12 maart 2024',
            'title' => 'Schuit schepen en onze geschiedenis',
            'text' => 'De maritieme geschiedenis en familieverhalen blijven belangrijke ankerpunten binnen het project.',
            'url' => home_url('/schuit/?cat=5'),
            'image' => home_url('/banner.png'),
        ],
    ], 0, $limit);
}

function schuit_portal_home_story_cards(): array {
    $story_specs = [
        [
            'slug' => 'verhalen',
            'eyebrow' => 'Verhalen',
            'image' => home_url('/banner.png'),
        ],
        [
            'slug' => 'familietakken',
            'eyebrow' => 'Stamboom',
            'image' => home_url('/logo.png'),
        ],
        [
            'slug' => 'archief',
            'eyebrow' => 'Archief',
            'image' => home_url('/banner.png'),
        ],
    ];

    $cards = [];

    foreach ($story_specs as $spec) {
        $page = get_page_by_path($spec['slug']);

        if ($page instanceof WP_Post) {
            $title = get_the_title($page);
            $content = get_post_field('post_excerpt', $page->ID);

            if (trim((string) $content) === '') {
                $content = get_post_field('post_content', $page->ID);
            }

            $cards[] = [
                'eyebrow' => $spec['eyebrow'],
                'title' => $title,
                'text' => schuit_portal_card_excerpt((string) $content, 24),
                'url' => get_permalink($page),
                'image' => schuit_portal_card_image_url($page->ID, $spec['image']),
            ];

            continue;
        }

        $fallback = schuit_portal_fallback_content($spec['slug']);
        $cards[] = [
            'eyebrow' => $spec['eyebrow'],
            'title' => $fallback['title'] ?: ucfirst($spec['slug']),
            'text' => schuit_portal_card_excerpt($fallback['content'], 24),
            'url' => home_url('/' . $spec['slug'] . '/'),
            'image' => $spec['image'],
        ];
    }

    return $cards;
}

function schuit_portal_fallback_menu(): void {
    $items = [
        ['label' => 'Home', 'url' => home_url('/')],
        ['label' => 'Nieuws', 'url' => home_url('/?post_type=post')],
        ['label' => 'Verhalen', 'url' => home_url('/verhalen/')],
        ['label' => 'Stamboom', 'url' => home_url('/tree/')],
        ['label' => 'Archief', 'url' => home_url('/archief/')],
        ['label' => 'Contact', 'url' => home_url('/contact/')],
    ];

    echo '<ul class="site-nav__list">';
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
