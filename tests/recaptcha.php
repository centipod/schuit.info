<?php
declare(strict_types=1);
require_once __DIR__ . '/../docker/login-throttle.php';
require_once __DIR__ . '/../docker/recaptcha.php';
use function Schuit\Captcha\check;
use function Schuit\Captcha\verified;
use function Schuit\Captcha\loginRequest;
function ensure(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
$settings = ['hosts' => ['schuit.info', 'schuit.centipod.ai']];
$calls = 0;
$transport = static function ($url, $body, $type) use (&$calls): array {
    ++$calls; ensure($url === 'http://recaptcha:8081/verify', 'Untrusted verifier destination');
    ensure($type === 'application/json', 'Wrong content type');
    $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    ensure(array_keys($data) === ['token', 'host'], 'Unexpected personal data sent');
    return ['success' => true];
};
foreach ([null, [], '', 'has space', "bad\nvalue", str_repeat('x',8193)] as $token) {
    ensure(!check($settings,$token,'schuit.info',$transport), 'Malformed token accepted');
}
ensure(!check($settings,'token','evil.example',$transport), 'Foreign host accepted');
ensure($calls === 0, 'Malformed request sent upstream');
ensure(check($settings,'valid-token','schuit.info',$transport), 'Valid internal response rejected');
foreach ([[], ['success'=>false], ['success'=>1], ['success'=>'true']] as $response) {
    ensure(!check($settings,'valid-token','schuit.info',static fn()=> $response), 'Loose success accepted');
}
try { check($settings,'valid-token','schuit.info',static function(){ throw new RuntimeException('network failed'); }); throw new LogicException('Failure swallowed'); }
catch (RuntimeException $e) { ensure($e->getMessage()==='network failed','Wrong failure'); }
$now = 1800000000;
$good=['success'=>true,'hostname'=>'schuit.info','challenge_ts'=>gmdate('c',$now)];
ensure(verified($good,'schuit.info',$now), 'Valid Google result rejected');
foreach ([['success'=>'true'],['hostname'=>'www.evil.example'],['challenge_ts'=>gmdate('c',$now-121)],['challenge_ts'=>gmdate('c',$now+31)],['challenge_ts'=>'tomorrow'],['error-codes'=>['timeout-or-duplicate']]] as $change) {
    ensure(!verified(array_replace($good,$change),'schuit.info',$now), 'Invalid Google result accepted');
}
$wp=['REQUEST_METHOD'=>'POST','SCRIPT_FILENAME'=>'/var/www/html/wp-login.php','REQUEST_URI'=>'/wp-login.php'];
ensure(loginRequest($wp,[],[]), 'WP login unprotected');
ensure(loginRequest($wp,['action'=>'lostpassword'],['log'=>'u','pwd'=>'p']), 'Credential action bypass');
ensure(!loginRequest($wp,['action'=>'lostpassword'],[]), 'Password recovery blocked');
ensure(!loginRequest(array_replace($wp,['REQUEST_METHOD'=>'GET']),[],[]), 'GET protected as login');
foreach (['/tree/login/0','/tree/index.php/login/0','/tree/%6cogin/0'] as $uri) {
    ensure(loginRequest(['REQUEST_METHOD'=>'POST','REQUEST_URI'=>$uri],[],[]),'Webtrees route bypass');
}
ensure(loginRequest(['REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/tree/index.php'],['route'=>'/login/0'],[]),'Legacy route bypass');
ensure(!loginRequest(['REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/tree/logout'],[],[]),'Logout blocked');
$file = tempnam(sys_get_temp_dir(), 'captcha-config-');
try {
    file_put_contents($file,json_encode(['site_key'=>str_repeat('s',30),'hosts'=>['schuit.info']]));
    ensure(Schuit\Captcha\config($file)['hosts'] === ['schuit.info'],'Public config rejected');
    try { Schuit\Captcha\config($file,true); throw new LogicException('Missing secret accepted'); } catch(RuntimeException) {}
    file_put_contents($file,str_repeat('x',8193));
    try { Schuit\Captcha\config($file); throw new LogicException('Oversized config accepted'); } catch(RuntimeException) {}
} finally { unlink($file); }
echo "reCAPTCHA token, hostname, expiry, errors, transport, routing and configuration tests passed.\n";
