<?php
/** Private fixed-purpose verifier; no general proxy, no public port, no passwords. */
declare(strict_types=1);
require_once __DIR__ . '/recaptcha.php';
header('Content-Type: application/json'); header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($_SERVER['REQUEST_URI'] ?? '') === '/health') {
    try { \Schuit\Captcha\config(secret: true); echo '{"ready":true}'; }
    catch (\Throwable) { http_response_code(503); echo '{"ready":false}'; }
    return;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_SERVER['REQUEST_URI'] ?? '') !== '/verify') {
    http_response_code(404); echo '{"success":false}'; return;
}
try {
    $raw = file_get_contents('php://input', false, null, 0, 9217);
    if ($raw === false || strlen($raw) > 9216) { http_response_code(413); echo '{"success":false}'; return; }
    $input = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    $settings = \Schuit\Captcha\config(secret: true);
    $host = $input['host'] ?? null; $token = $input['token'] ?? null;
    if (!is_string($host) || !in_array($host, $settings['hosts'], true) || !\Schuit\Captcha\validToken($token)) {
        http_response_code(400); echo '{"success":false}'; return;
    }
    $response = \Schuit\Captcha\post('https://www.google.com/recaptcha/api/siteverify',
        http_build_query(['secret' => $settings['secret_key'], 'response' => $token]), 'application/x-www-form-urlencoded');
    echo json_encode(['success' => \Schuit\Captcha\verified($response, $host, time())], JSON_THROW_ON_ERROR);
} catch (\Throwable) {
    http_response_code(503); echo '{"success":false}';
}
