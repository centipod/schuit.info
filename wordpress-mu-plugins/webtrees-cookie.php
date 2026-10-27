<?php
/**
 * Establish Webtrees' non-identifying cookie from the portal, avoiding its
 * first-visit HTML refresh. This is a session cookie, not authentication.
 * The native Webtrees bot guard remains unchanged, including direct visits.
 */
declare(strict_types=1);
add_action('send_headers', static function (): void {
    if (is_ssl() && !headers_sent()) {
        setcookie('schuit_webtrees_visit', '1', [
            'expires' => 0,
            'path' => '/tree/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }
});
