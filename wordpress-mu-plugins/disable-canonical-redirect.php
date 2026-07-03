<?php

declare(strict_types=1);

add_filter('redirect_canonical', static function ($redirect_url) {
    return false;
});
