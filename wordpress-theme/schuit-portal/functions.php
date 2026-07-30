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
        ],
        [
            'eyebrow' => '27 april 2024',
            'title' => 'Nieuwe documenten toegevoegd',
            'text' => 'Historische documenten en foto’s uit het archief worden stapsgewijs samengebracht in het portal.',
            'url' => home_url('/schuit/?cat=5'),
        ],
        [
            'eyebrow' => '12 maart 2024',
            'title' => 'Schuit schepen en onze geschiedenis',
            'text' => 'De maritieme geschiedenis en familieverhalen blijven belangrijke ankerpunten binnen het project.',
            'url' => home_url('/schuit/?cat=5'),
        ],
    ], 0, $limit);
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

function schuit_portal_webtrees_count(mysqli $connection, string $query): ?int {
    $result = $connection->query($query);
    if (!$result instanceof mysqli_result) {
        return null;
    }

    $row = $result->fetch_row();
    $result->free();

    return isset($row[0]) ? (int) $row[0] : null;
}

function schuit_portal_webtrees_metrics(): array {
    $cached = get_transient('schuit_portal_webtrees_metrics');
    if (is_array($cached)) {
        return $cached;
    }

    $metrics = [
        'individuals' => null,
        'families' => null,
        'places' => null,
        'trees' => null,
    ];

    if (!class_exists('mysqli')) {
        return $metrics;
    }

    $config = schuit_portal_webtrees_config();
    if ($config['host'] === '' || $config['user'] === '' || $config['name'] === '') {
        return $metrics;
    }

    $prefix = preg_replace('/[^A-Za-z0-9_]/', '', $config['prefix']);
    if (!is_string($prefix) || $prefix === '') {
        $prefix = 'wt_';
    }

    mysqli_report(MYSQLI_REPORT_OFF);
    $connection = @new mysqli($config['host'], $config['user'], $config['password'], $config['name'], (int) $config['port']);
    if ($connection->connect_errno !== 0) {
        return $metrics;
    }

    $connection->set_charset('utf8mb4');

    $metrics['individuals'] = schuit_portal_webtrees_count($connection, "SELECT COUNT(*) FROM `{$prefix}individuals`");
    $metrics['families'] = schuit_portal_webtrees_count($connection, "SELECT COUNT(*) FROM `{$prefix}families`");
    $metrics['places'] = schuit_portal_webtrees_count($connection, "SELECT COUNT(DISTINCT p_place) FROM `{$prefix}places`");
    $metrics['trees'] = schuit_portal_webtrees_count($connection, "SELECT COUNT(DISTINCT i_file) FROM `{$prefix}individuals`");

    $connection->close();
    set_transient('schuit_portal_webtrees_metrics', $metrics, 30 * MINUTE_IN_SECONDS);

    return $metrics;
}

function schuit_portal_metric_value(?int $value): string {
    if ($value === null) {
        return '...';
    }

    return number_format_i18n($value);
}

function schuit_portal_page_url(string $slug, string $fallback): string {
    $page = get_page_by_path($slug);

    if ($page instanceof WP_Post) {
        return get_permalink($page);
    }

    return home_url($fallback);
}

function schuit_portal_category_url(string $slug, int $fallback_id): string {
    $category = get_category_by_slug($slug);

    if ($category instanceof WP_Term) {
        $url = get_category_link($category);
        if (!is_wp_error($url)) {
            return $url;
        }
    }

    return add_query_arg('cat', (string) $fallback_id, home_url('/'));
}

function schuit_portal_fallback_menu(): void {
    $items = [
        ['label' => 'Home', 'url' => home_url('/')],
        ['label' => 'Familiearchief', 'url' => schuit_portal_category_url('publicaties', 5)],
        ['label' => 'Nieuws', 'url' => schuit_portal_category_url('nieuws', 1)],
        ['label' => 'Over de stichting', 'url' => schuit_portal_page_url('about', '/?page_id=2')],
        ['label' => 'Contact', 'url' => 'mailto:info@stichtingschu-y-i-ij-t.nl'],
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
