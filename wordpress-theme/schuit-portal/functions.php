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
    $navigation = get_stylesheet_directory() . '/navigation.js';

    wp_enqueue_style('schuit-portal-style', get_stylesheet_uri(), [], (string) filemtime($stylesheet));
    wp_enqueue_script('schuit-portal-navigation', get_stylesheet_directory_uri() . '/navigation.js', [], (string) filemtime($navigation), true);
}
add_action('wp_enqueue_scripts', 'schuit_portal_assets');

function schuit_portal_card_excerpt(string $content, int $words = 24): string {
    $excerpt = trim(wp_strip_all_tags($content));

    if ($excerpt === '') {
        return '';
    }

    return wp_trim_words($excerpt, $words, '…');
}

function schuit_portal_home_publication_cards(int $limit = 3): array {
    $cards = [];
    $query = new WP_Query([
        'post_type' => 'post',
        'posts_per_page' => $limit,
        'ignore_sticky_posts' => true,
        'category_name' => 'publicaties',
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
            ];
        }

        wp_reset_postdata();
    }

    if ($cards !== []) {
        return array_slice($cards, 0, $limit);
    }

    return [];
}

function schuit_portal_home_story_cards(): array {
    $story_specs = [
        [
            'slug' => 'verhalen',
            'eyebrow' => 'Verhalen',
        ],
        [
            'slug' => 'familietakken',
            'eyebrow' => 'Stamboom',
        ],
        [
            'slug' => 'archief',
            'eyebrow' => 'Archief',
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
            ];

            continue;
        }

        $fallback = schuit_portal_fallback_content($spec['slug']);
        $cards[] = [
            'eyebrow' => $spec['eyebrow'],
            'title' => $fallback['title'] ?: ucfirst($spec['slug']),
            'text' => schuit_portal_card_excerpt($fallback['content'], 24),
            'url' => home_url('/' . $spec['slug'] . '/'),
        ];
    }

    return $cards;
}

function schuit_portal_webtrees_config(): array {
    $config = [
        'host' => getenv('WEBTREES_DB_HOST') ?: '',
        'port' => getenv('WEBTREES_DB_PORT') ?: '3306',
        'user' => getenv('WEBTREES_DB_USER') ?: '',
        'password' => getenv('WEBTREES_DB_PASSWORD') ?: '',
        'name' => getenv('WEBTREES_DB_NAME') ?: '',
        'prefix' => getenv('WEBTREES_TABLE_PREFIX') ?: 'wt_',
    ];

    $config_path = getenv('WEBTREES_CONFIG_PATH') ?: '/var/www/shared/webtrees/data/config.ini.php';
    if (is_readable($config_path)) {
        $parsed = parse_ini_file($config_path, false, INI_SCANNER_RAW);
        if (is_array($parsed)) {
            $config = [
                'host' => (string) ($parsed['dbhost'] ?? $config['host']),
                'port' => (string) ($parsed['dbport'] ?? $config['port']),
                'user' => (string) ($parsed['dbuser'] ?? $config['user']),
                'password' => (string) ($parsed['dbpass'] ?? $config['password']),
                'name' => (string) ($parsed['dbname'] ?? $config['name']),
                'prefix' => (string) ($parsed['tblpfx'] ?? $config['prefix']),
            ];
        }
    }

    return $config;
}

function schuit_portal_webtrees_metrics(): array {
    // This atomic snapshot is refreshed only after a completed database import.
    // WordPress receives aggregate counts, not webtrees database credentials.
    $empty = ['individuals' => null, 'families' => null];
    $path = getenv('WEBTREES_METRICS_PATH') ?: '/var/www/shared/webtrees/data/metrics.json';
    if (!is_readable($path)) { return $empty; }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) { return $empty; }
    foreach (array_keys($empty) as $key) {
        if (!isset($data[$key]) || !is_int($data[$key]) || $data[$key] < 0) { return $empty; }
    }
    return $data;
}

function schuit_portal_metric_value(?int $value): string {
    if ($value === null) {
        return '...';
    }

    return number_format_i18n($value);
}

function schuit_portal_page_url(string $slug, string $fallback): string {
    $page = get_page_by_path($slug);
    if (!$page instanceof WP_Post) {
        // Legacy pages such as Bestuur are children of De stichting.
        $matches = get_posts(['post_type' => 'page', 'post_status' => 'publish', 'name' => $slug, 'posts_per_page' => 1]);
        $page = $matches[0] ?? null;
    }

    if ($page instanceof WP_Post) {
        return get_permalink($page);
    }

    return home_url($fallback);
}

function schuit_portal_category_url(string $slug, int $fallback_id): string {
    $category = get_category_by_slug($slug);

    if ($category instanceof WP_Term) {
        return get_category_link($category);
    }

    return add_query_arg('cat', (string) $fallback_id, home_url('/'));
}

function schuit_portal_fallback_menu(): void {
    $items = [
        ['Home', home_url('/')],
        ['Publicaties', schuit_portal_category_url('publicaties', 5)],
        ['De stichting', schuit_portal_page_url('about', '/?page_id=2')],
        ['Webtrees', home_url('/tree/')],
        ['Contact', schuit_portal_page_url('contact', '/contact/')],
    ];
    echo '<ul class="site-nav__list">';
    foreach ($items as [$label, $url]) {
        printf('<li><a href="%s">%s</a></li>', esc_url($url), esc_html($label));
    }
    echo '</ul>';
}

function schuit_portal_foundation_links(): array {
    return [
        'Bestuur' => 'bestuur', 'Geslachten' => 'geslachten',
        'Schuit, Schuyt en Schuijt' => 'van-der-schuit', 'Andere namen' => 'andere-namen',
        'Aantallen wereldwijd' => 'aantallen-wereldwijd',
        'Grootschippers' => 'grootschippers', 'Wetenswaardigheden' => 'wetenswaardigheden',
        'Kwartaalblad en nieuwsbrief' => 'kwartaalblad-in-hetzelfde-schu-y-i-ij-tje',
        'Donateurs en sponsors' => 'adverteren', 'Donateurs en vrienden' => 'donateurs',
        'Privacyreglement' => 'privacyreglement', 'Algemene voorwaarden' => 'algemene-voorwaarden',
        'Disclaimer' => 'disclaimer', 'General information' => 'general-information',
        'Links' => 'links',
    ];
}

function schuit_portal_sponsors(): void {
    // The original sidebar used published dfads records. Older banner-plugin
    // tables contain obsolete advertisements and must not be reactivated.
    $ads = get_posts(['post_type' => 'dfads', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC']);
    if (!$ads) { return; }
    echo '<section class="schuit-sponsors" aria-label="Onze sponsors"><h2>Onze sponsors</h2>';
    echo '<div class="schuit-sponsors__grid">';
    foreach ($ads as $ad) {
        $html = new WP_HTML_Tag_Processor(wp_kses_post($ad->post_content));
        while ($html->next_tag()) {
            if ($html->get_tag() === 'A') { $html->set_attribute('rel', 'sponsored noopener'); }
            if ($html->get_tag() === 'IMG') {
                $html->set_attribute('loading', 'lazy');
                $html->set_attribute('alt', 'Advertentie van ' . $ad->post_title);
            }
        }
        echo '<div class="schuit-sponsors__advert">' . $html->get_updated_html() . '</div>';
    }
    echo '</div>';
    printf('<p><a href="%s">Donateurs en sponsors</a></p></section>', esc_url(schuit_portal_page_url('adverteren', '/?page_id=270')));
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
