<?php
declare(strict_types=1);
namespace Schuit\Cognito;

final class LoginCapacity extends \RuntimeException {}

const CONFIG = '/run/secrets/schuit-cognito.json';
function allowedLoginDomain(string $domain): bool {
    return $domain === 'https://login.schuit.info' ||
        preg_match('~^https://[a-z0-9-]+\.auth\.eu-west-1\.amazoncognito\.com$~D', $domain) === 1;
}
function config(): array {
    static $config;
    if ($config !== null) { return $config; }
    if (!is_file(CONFIG)) { throw new \RuntimeException('Auth configuration missing'); }
    $config = json_decode(file_get_contents(CONFIG), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($config) || !is_bool($config['enabled'] ?? null)) { throw new \RuntimeException('Invalid auth configuration'); }
    if (!$config['enabled']) { return $config; }
    foreach (['origin', 'domain', 'issuer', 'client_id'] as $key) {
        if (!is_string($config[$key] ?? null)) { throw new \RuntimeException('Missing auth configuration'); }
    }
    if (!preg_match('~^https://[a-z0-9.-]+(?::[0-9]+)?$~D', $config['origin']) ||
        !allowedLoginDomain($config['domain']) ||
        !preg_match('~^https://cognito-idp\.eu-west-1\.amazonaws\.com/eu-west-1_[A-Za-z0-9]+$~D', $config['issuer']) ||
        !preg_match('/^[a-z0-9]{10,128}$/D', $config['client_id'])) { throw new \RuntimeException('Invalid auth endpoints'); }
    return $config;
}
function enabled(): bool { return config()['enabled']; }
function b64(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
function callback(string $app): string {
    return config()['origin'] . ($app === 'wordpress' ? '/wp-login.php?action=schuit_callback' : '/tree/module/_schuit-cognito_/Callback');
}
function redirect(string $url): never {
    header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer'); header('Location: ' . $url, true, 303); exit;
}
function fail(): never {
    http_response_code(403); header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="nl"><meta charset="utf-8"><title>Aanmelden — SCHUIT</title><h1>Aanmelden niet voltooid</h1><p>Probeer opnieuw aan te melden. Neem contact op met het bestuur als dit blijft gebeuren.</p><p><a href="/wp-login.php">Aanmelden bij de website</a> · <a href="/tree/login">Aanmelden bij webtrees</a></p></html>'; exit;
}
function stateDirectory(): string {
    $dir = '/tmp/schuit-oidc';
    if (!is_dir($dir) && !mkdir($dir, 0700) && !is_dir($dir)) { throw new \RuntimeException('State storage unavailable'); }
    return $dir;
}
function cookieName(string $app): string { return '__Host-schuit-oidc-' . $app; }
function cookie(string $app, string $value, int $expires): void {
    setcookie(cookieName($app), $value, ['expires'=>$expires, 'path'=>'/', 'secure'=>true, 'httponly'=>true, 'samesite'=>'Lax']);
}
/** Called under the allocation lock; limits one IP and browser before AWS redirect. */
function checkStateBudget(string $dir, string $client, string $browser, int $now): void {
    $total = 0; $perClient = 0; $perBrowser = 0;
    foreach (glob($dir . '/*.json') ?: [] as $file) {
        if (is_link($file)) { throw new \RuntimeException('Invalid state file'); }
        if (filemtime($file) < $now - 600) { unlink($file); continue; }
        $raw = file_get_contents($file, false, null, 0, 4097);
        if ($raw === false || strlen($raw) > 4096) { throw new \RuntimeException('Invalid state data'); }
        $state = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        ++$total;
        if (($state['client'] ?? '') === $client) { ++$perClient; }
        if (($state['browser'] ?? '') === $browser) { ++$perBrowser; }
    }
    if ($total >= 512 || $perClient >= 20 || $perBrowser >= 3) {
        throw new LoginCapacity('Login initiation limit reached');
    }
}
function authorizationUrl(string $app): string {
    $cfg = config(); $dir = stateDirectory();
    // REMOTE_ADDR is normalised by trusted Apache mod_remoteip, not request headers.
    $ip = inet_pton($_SERVER['REMOTE_ADDR'] ?? '');
    if ($ip === false) { throw new \RuntimeException('Invalid client address'); }
    $client = hash('sha256', $ip);
    $binding = $_COOKIE[cookieName($app)] ?? '';
    if (!is_string($binding) || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $binding)) { $binding = b64(random_bytes(32)); }
    $browser = hash('sha256', $binding);
    $lock = fopen($dir . '/allocation.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) { throw new \RuntimeException('State lock unavailable'); }
    try {
        checkStateBudget($dir, $client, $browser, time());
        $state = b64(random_bytes(32));
        $data = ['app'=>$app, 'client'=>$client, 'browser'=>$browser, 'binding'=>$browser, 'nonce'=>b64(random_bytes(32)), 'verifier'=>b64(random_bytes(48)), 'until'=>time()+600];
        $file = $dir . '/' . hash('sha256', $state) . '.json';
        $f = fopen($file, 'x');
        if (!$f) { throw new \RuntimeException('Cannot create state'); }
        try { chmod($file, 0600); $json=json_encode($data, JSON_THROW_ON_ERROR); if (fwrite($f,$json)!==strlen($json) || !fflush($f)) { throw new \RuntimeException('Cannot save state'); } } finally { fclose($f); }
    } finally { flock($lock, LOCK_UN); fclose($lock); }
    cookie($app, $binding, time()+600);
    return $cfg['domain'].'/oauth2/authorize?'.http_build_query([
        'response_type'=>'code', 'client_id'=>$cfg['client_id'], 'redirect_uri'=>callback($app),
        'scope'=>'openid email', 'state'=>$state, 'nonce'=>$data['nonce'], 'code_challenge_method'=>'S256',
        'code_challenge'=>b64(hash('sha256',$data['verifier'],true)), 'lang'=>'nl', 'prompt'=>'login',
    ], '', '&', PHP_QUERY_RFC3986);
}
function limited(): never {
    http_response_code(429); header('Retry-After: 600'); header('Cache-Control: no-store');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="nl"><meta charset="utf-8"><title>Aanmelden — SCHUIT</title><h1>Even wachten met aanmelden</h1><p>Er zijn te veel aanmeldpogingen. Rond een bestaande aanmelding af of probeer het over tien minuten opnieuw.</p><a href="/">Terug naar de website</a></html>'; exit;
}
function consume(string $app, mixed $state, mixed $binding): array {
    if (!is_string($state) || !preg_match('/^[A-Za-z0-9_-]{43}$/D',$state) || !is_string($binding) || !preg_match('/^[A-Za-z0-9_-]{43}$/D',$binding)) { throw new \RuntimeException('Invalid state'); }
    $path = stateDirectory().'/'.hash('sha256',$state).'.json';
    $f = @fopen($path,'r');
    if (!$f || !flock($f,LOCK_EX)) { throw new \RuntimeException('Missing state'); }
    try {
        if (!is_file($path)) { throw new \RuntimeException('Consumed state'); }
        $data=json_decode(stream_get_contents($f,4097),true,8,JSON_THROW_ON_ERROR);
        if (($data['app']??null)!==$app || ($data['until']??0)<time() || !hash_equals($data['binding']??'',hash('sha256',$binding))) { throw new \RuntimeException('State mismatch'); }
        if (!unlink($path)) { throw new \RuntimeException('Cannot consume state'); }
        return $data;
    } finally { flock($f,LOCK_UN); fclose($f); }
}
function exchange(array $data, string $code): array {
    $ch=curl_init('http://cognito:8081/exchange'); $raw='';
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['code'=>$code,'app'=>$data['app'],'verifier'=>$data['verifier'],'nonce'=>$data['nonce']],JSON_THROW_ON_ERROR),CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_WRITEFUNCTION=>static function($c,$chunk)use(&$raw){if(strlen($raw)+strlen($chunk)>8192){return 0;}$raw.=$chunk;return strlen($chunk);}]);
    $ok=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
    if ($ok===false || $status!==200) { throw new \RuntimeException('Identity service rejected login'); }
    return json_decode($raw,true,16,JSON_THROW_ON_ERROR);
}
function localIdentity(array $identity, string $app, array $users): array {
    if (!is_string($identity['sub']??null) || ($identity['email_verified']??null)!==true || !is_string($identity['email']??null)) { throw new \RuntimeException('Unverified identity'); }
    $mapping=$users[$identity['sub']]??null;
    if (!is_array($mapping) || !is_string($mapping['email']??null) || strcasecmp($mapping['email'],$identity['email'])!==0 || !is_int($mapping[$app]??null) || $mapping[$app]<1) { throw new \RuntimeException('Identity not linked'); }
    return ['id'=>$mapping[$app],'email'=>$mapping['email']];
}
function finish(string $app): array {
    if (($_SERVER['REQUEST_METHOD']??'')!=='GET') { throw new \RuntimeException('Invalid callback method'); }
    $data=consume($app,$_GET['state']??null,$_COOKIE[cookieName($app)]??null); cookie($app,'',time()-3600);
    $code=$_GET['code']??null;
    if (isset($_GET['error']) || !is_string($code) || $code==='' || strlen($code)>2048) { throw new \RuntimeException('Invalid code'); }
    return localIdentity(exchange($data,$code),$app,config()['users']??[]);
}
function treeRoute(array $server,array $query): string {
    $path=rawurldecode(parse_url($server['REQUEST_URI']??'',PHP_URL_PATH)?:'');
    if(!str_starts_with($path,'/tree/')){return '';}
    if(isset($query['route']) && is_string($query['route'])){return $query['route'];}
    return preg_replace('~^/tree/(?:index\.php/)?~','/',$path);
}
function blockNativeLogin(): void {
    if(PHP_SAPI==='cli' || !enabled()){return;}
    $route=treeRoute($_SERVER,$_GET);
    if(($_SERVER['REQUEST_METHOD']??'')==='POST' && (basename($_SERVER['SCRIPT_FILENAME']??'')==='wp-login.php' || preg_match('~^/(?:login|register|password-request|password-reset)(?:/|$)~D',$route))){fail();}
}

function begin(string $app): never { redirect(authorizationUrl($app)); }
