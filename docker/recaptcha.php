<?php
declare(strict_types=1);
namespace Schuit\Captcha;

const CONFIG = '/run/secrets/schuit-recaptcha.json';
const TOKEN_LIMIT = 8192;
const RESPONSE_LIMIT = 16384;

function config(string $path = CONFIG, bool $secret = false): array {
    $raw = @file_get_contents($path, false, null, 0, 8193);
    if ($raw === false || strlen($raw) > 8192) { throw new \RuntimeException('Captcha configuration unavailable'); }
    $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    foreach ($secret ? ['site_key', 'secret_key'] : ['site_key'] as $key) {
        if (!is_string($data[$key] ?? null) || !preg_match('/^[A-Za-z0-9_-]{20,200}$/D', $data[$key])) {
            throw new \RuntimeException('Invalid captcha configuration');
        }
    }
    if (!is_array($data['hosts'] ?? null) || !$data['hosts'] || count($data['hosts']) > 10) { throw new \RuntimeException('Invalid captcha hosts'); }
    foreach ($data['hosts'] as $host) {
        if (!is_string($host) || !preg_match('/^[a-z0-9]+(?:[.-][a-z0-9]+)*$/D', $host)) { throw new \RuntimeException('Invalid captcha host'); }
    }
    return $data;
}

function validToken(mixed $token): bool {
    return is_string($token) && $token !== '' && strlen($token) <= TOKEN_LIMIT && !preg_match('/[\x00-\x20\x7f]/', $token);
}

/** Fixed destinations only; never log the request body, token or secret. */
function post(string $url, string $body, string $contentType): array {
    $handle = curl_init($url);
    $response = '';
    try {
        curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: ' . $contentType], CURLOPT_CONNECTTIMEOUT_MS => 1500,
            CURLOPT_TIMEOUT_MS => 6000, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response): int {
                if (strlen($response) + strlen($chunk) > RESPONSE_LIMIT) { return 0; }
                $response .= $chunk; return strlen($chunk);
            },
        ]);
        if (curl_exec($handle) === false || curl_getinfo($handle, CURLINFO_RESPONSE_CODE) !== 200) {
            throw new \RuntimeException('Captcha verification unavailable');
        }
        $data = json_decode($response, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($data)) { throw new \RuntimeException('Invalid captcha response'); }
        return $data;
    } finally { curl_close($handle); }
}

function verified(array $response, string $host, int $now): bool {
    if (($response['success'] ?? null) !== true || ($response['hostname'] ?? null) !== $host || !empty($response['error-codes'])) { return false; }
    $stamp = $response['challenge_ts'] ?? null;
    if (!is_string($stamp) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $stamp)) { return false; }
    $time = strtotime($stamp);
    return $time !== false && $time >= $now - 120 && $time <= $now + 30;
}

function check(array $settings, mixed $token, string $host, callable $transport): bool {
    if (!validToken($token) || !in_array($host, $settings['hosts'], true)) { return false; }
    $body = json_encode(['token' => $token, 'host' => $host], JSON_THROW_ON_ERROR);
    $response = $transport('http://recaptcha:8081/verify', $body, 'application/json');
    return ($response['success'] ?? null) === true;
}

function widget(): string {
    try { $settings = config(); }
    catch (\Throwable) { return '<p role="alert">De aanmeldbeveiliging is tijdelijk niet beschikbaar. Probeer het later opnieuw.</p>'; }
    $key = htmlspecialchars($settings['site_key'], ENT_QUOTES, 'UTF-8');
    return '<div class="schuit-captcha" style="margin:16px 0"><div class="g-recaptcha" data-sitekey="' . $key . '" data-size="compact"></div>'
        . '<p style="font-size:12px">Google reCAPTCHA beschermt dit aanmeldformulier tegen misbruik. '
        . '<a href="https://policies.google.com/privacy" rel="noreferrer">Privacybeleid</a> en '
        . '<a href="https://policies.google.com/terms" rel="noreferrer">voorwaarden</a> van Google zijn van toepassing.</p>'
        . '<noscript><p>Schakel JavaScript in om de beveiligingscontrole uit te voeren.</p></noscript></div>'
        . '<script src="https://www.google.com/recaptcha/api.js?hl=nl" async defer referrerpolicy="no-referrer"></script>';
}

/** Password reset/logout are separate flows; protect actual username/password sign-in. */
function loginRequest(array $server, array $query, array $body): bool {
    $target = \Schuit\Security\loginTarget($server, $query);
    if ($target === 'webtrees') { return true; }
    if ($target !== 'wordpress') { return false; }
    $action = $body['action'] ?? $query['action'] ?? 'login';
    return $action === 'login' || isset($body['log'], $body['pwd']);
}

function enforce(): void {
    if (PHP_SAPI === 'cli' || !loginRequest($_SERVER, $_GET, $_POST)) { return; }
    $code = 403;
    $message = 'De beveiligingscontrole is niet gelukt of verlopen. Ga terug en bevestig opnieuw dat u geen robot bent.';
    try {
        $settings = config();
        $host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '', 2)[0]);
        if (check($settings, $_POST['g-recaptcha-response'] ?? null, $host, __NAMESPACE__ . '\\post')) { return; }
    } catch (\Throwable) {
        // Deliberately omit exception detail: upstream errors could contain secrets.
        error_log('SCHUIT reCAPTCHA verification unavailable');
        $code = 503; $message = 'De aanmeldbeveiliging is tijdelijk niet beschikbaar. Probeer het over een minuut opnieuw.';
    }
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store');
    if ($code === 503) { header('Retry-After: 60'); }
    $back = \Schuit\Security\loginTarget($_SERVER, $_GET) === 'wordpress' ? '/wp-login.php' : '/tree/login';
    echo '<!doctype html><html lang="nl"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Aanmelden — SCHUIT</title><main><h1>Aanmelden</h1><p role="alert">'
        . $message . '</p><p><a href="' . $back . '">Terug naar aanmelden</a></p></main></html>';
    exit;
}
