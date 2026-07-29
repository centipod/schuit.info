<?php

declare(strict_types=1);

add_filter('redirect_canonical', static function ($redirect_url) {
    return false;
});

add_filter('rest_endpoints', static function (array $endpoints): array {
    if (is_user_logged_in()) {
        return $endpoints;
    }

    unset($endpoints['/wp/v2/users'], $endpoints['/wp/v2/users/(?P<id>[\\d]+)']);

    return $endpoints;
});
