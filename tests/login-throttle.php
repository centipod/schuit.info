<?php
declare(strict_types=1);
require_once $argv[1] ?? __DIR__ . '/../docker/login-throttle.php';
use function Schuit\Security\{attempt,loginTarget};
function check(bool $ok, string $label): void { if (!$ok) { throw new RuntimeException($label); } }
$base=['REQUEST_METHOD'=>'POST','SCRIPT_FILENAME'=>'/var/www/html/tree/index.php'];
foreach (['/tree/login','/tree/login/0','/tree/index.php/login/0','/tree/%6cogin/0'] as $uri) {
    check(loginTarget($base+['REQUEST_URI'=>$uri],[])==='webtrees','Webtrees route '.$uri);
}
check(loginTarget($base+['REQUEST_URI'=>'/tree/index.php'],['route'=>'/tree/login/0'])==='webtrees','Query route');
check(loginTarget(['REQUEST_METHOD'=>'POST','SCRIPT_FILENAME'=>'/var/www/html/wp-login.php'],[])==='wordpress','WordPress');
check(loginTarget(['REQUEST_METHOD'=>'GET']+$base+['REQUEST_URI'=>'/tree/login/0'],[])===null,'GET unaffected');
check(loginTarget($base+['REQUEST_URI'=>'/tree/individual/0/I1'],[])===null,'Other POST unaffected');
$file=tempnam(sys_get_temp_dir(),'schuit-limiter-');
try {
    for($n=0;$n<20;$n++){check(attempt($file,'2001:db8::1','webtrees',100)===0,'Allowed attempt');}
    check(attempt($file,'2001:0db8:0:0:0:0:0:1','webtrees',101)===599,'Canonical IPv6 blocked');
    check(attempt($file,'2001:db8::2','webtrees',101)===0,'Different client unaffected');
    check(attempt($file,'2001:db8::1','wordpress',101)===0,'Other application independent');
    check(attempt($file,'2001:db8::1','webtrees',700)===0,'Expiry');
    file_put_contents($file,'invalid');
    try {attempt($file,'192.0.2.1','wordpress',100);check(false,'Corruption must fail closed');} catch (JsonException) {}
    check((fileperms($file)&0777)===0600,'Private storage');
} finally {unlink($file);}
echo "Login route, limit, isolation, IPv6, expiry and fail-closed checks passed.\n";
