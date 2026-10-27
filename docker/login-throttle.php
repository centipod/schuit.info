<?php
declare(strict_types=1);
namespace Schuit\Security;

// Runs before both applications. REMOTE_ADDR is set by Apache mod_remoteip;
// never read client-supplied forwarding headers in PHP.
function loginTarget(array $server, array $query): ?string {
    if (($server['REQUEST_METHOD'] ?? '') !== 'POST') { return null; }
    if (basename($server['SCRIPT_FILENAME'] ?? '') === 'wp-login.php') { return 'wordpress'; }
    $path = rawurldecode(parse_url($server['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
    if (!str_starts_with($path, '/tree/')) { return null; }
    $route = isset($query['route']) && is_string($query['route']) ? $query['route'] : $path;
    return preg_match('~^/(?:tree/)?(?:index\.php/)?login(?:/|$)~D', $route) ? 'webtrees' : null;
}

/** Atomic fixed-window limiter; bounded storage, no account names/passwords. */
function attempt(string $file, string $ip, string $target, int $now): int {
    $address = inet_pton($ip);
    if ($address === false) { throw new \RuntimeException('Invalid client address'); }
    $key = hash('sha256', $target . ':' . bin2hex($address));
    $handle = fopen($file, 'c+');
    if (!$handle || !flock($handle, LOCK_EX)) { throw new \RuntimeException('Limiter unavailable'); }
    try {
        chmod($file, 0600);
        $raw = stream_get_contents($handle, 1048577);
        if (strlen($raw) > 1048576) { throw new \RuntimeException('Invalid limiter storage'); }
        $data = $raw === '' ? [] : json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($data)) { throw new \RuntimeException('Invalid limiter storage'); }
        foreach ($data as $id => $entry) {
            if ($entry['until'] <= $now) { unset($data[$id]); }
        }
        if (!isset($data[$key])) {
            if (count($data) >= 2048) { throw new \RuntimeException('Limiter capacity reached'); }
            $data[$key] = ['until' => $now + 600, 'count' => 0];
        }
        $wait = $data[$key]['count'] >= 20 ? $data[$key]['until'] - $now : 0;
        if (!$wait) { ++$data[$key]['count']; }
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        rewind($handle);
        if (fwrite($handle, $json) !== strlen($json) || !ftruncate($handle, strlen($json)) || !fflush($handle)) {
            throw new \RuntimeException('Limiter write failed');
        }
        return $wait;
    } finally { flock($handle, LOCK_UN); fclose($handle); }
}

if (PHP_SAPI === 'cli') { return; }
$target = loginTarget($_SERVER, $_GET);
if ($target === null) { return; }
try {
    $folder = '/tmp/schuit-login-limits';
    if (!is_dir($folder) && !@mkdir($folder, 0700) && !is_dir($folder)) {
        throw new \RuntimeException('Limiter directory unavailable');
    }
    $wait = attempt($folder . '/attempts.json', $_SERVER['REMOTE_ADDR'] ?? '', $target, time());
    if (!$wait) { return; }
    http_response_code(429);
    header('Retry-After: ' . $wait);
    $message = 'Te veel aanmeldpogingen. Wacht enkele minuten en probeer het opnieuw.';
} catch (\Throwable $error) {
    error_log('SCHUIT login protection unavailable: ' . get_class($error));
    http_response_code(503);
    header('Retry-After: 60');
    $message = 'Aanmelden is tijdelijk niet beschikbaar. Probeer het over een minuut opnieuw.';
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo '<!doctype html><html lang="nl"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Aanmelden — SCHUIT</title><body><main><h1>Aanmelden</h1><p>' . $message . '</p><p><a href="/">Terug naar de website</a></p></main></body></html>';
exit;
