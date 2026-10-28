<?php
declare(strict_types=1);
require '/opt/schuit-security/cognito-verification.php';
use Firebase\JWT\JWT;
use function Schuit\Cognito\{verifyIdToken,localIdentity,b64,consume,stateDirectory,treeRoute};
function ok(bool $v,string $name):void{if(!$v){throw new RuntimeException($name);}echo "PASS $name\n";}
function rejects(callable $f,string $name):void{try{$f();}catch(Throwable $e){ok(true,$name);return;}throw new RuntimeException('Accepted '.$name);}
$key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
$d=openssl_pkey_get_details($key);openssl_pkey_export($key,$pem);
$jwks=['keys'=>[['kty'=>'RSA','use'=>'sig','alg'=>'RS256','kid'=>'test','n'=>b64($d['rsa']['n']),'e'=>b64($d['rsa']['e'])]]];
$cfg=['issuer'=>'https://cognito-idp.eu-west-1.amazonaws.com/eu-west-1_test','client_id'=>'testclient'];$nonce=b64(random_bytes(32));
$claims=['iss'=>$cfg['issuer'],'aud'=>$cfg['client_id'],'sub'=>'11111111-1111-4111-8111-111111111111','email'=>'manager@example.invalid','email_verified'=>true,'token_use'=>'id','iat'=>time(),'exp'=>time()+300,'nonce'=>$nonce];
$sign=fn($c)=>JWT::encode($c,$pem,'RS256','test');$identity=verifyIdToken($sign($claims),$jwks,$cfg,$nonce);ok($identity['sub']===$claims['sub'],'valid signed token');
foreach(['iss'=>'https://attacker.invalid','aud'=>'wrong','nonce'=>'wrong','email_verified'=>false,'token_use'=>'access','exp'=>time()-100,'iat'=>time()+120,'email'=>'invalid','sub'=>'bad'] as $field=>$value){$bad=$claims;$bad[$field]=$value;rejects(fn()=>verifyIdToken($sign($bad),$jwks,$cfg,$nonce),'reject '.$field);}
$wrong=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);openssl_pkey_export($wrong,$wrongPem);rejects(fn()=>verifyIdToken(JWT::encode($claims,$wrongPem,'RS256','test'),$jwks,$cfg,$nonce),'wrong signing key');
rejects(fn()=>verifyIdToken(JWT::encode($claims,str_repeat('x',64),'HS256','test'),$jwks,$cfg,$nonce),'algorithm substitution');
$users=[$claims['sub']=>['email'=>$claims['email'],'wordpress'=>12,'webtrees'=>7]];
ok(localIdentity($identity,'wordpress',$users)['id']===12,'fixed local account mapping');
rejects(fn()=>localIdentity($identity,'wordpress',[]),'unlinked account');$identity['email']='other@example.invalid';rejects(fn()=>localIdentity($identity,'wordpress',$users),'changed email');
$binding=b64(random_bytes(32));$state=b64(random_bytes(32));$path=stateDirectory().'/'.hash('sha256',$state).'.json';file_put_contents($path,json_encode(['app'=>'wordpress','binding'=>hash('sha256',$binding),'until'=>time()+60]));
rejects(fn()=>consume('webtrees',$state,$binding),'cross-application state');rejects(fn()=>consume('wordpress',$state,b64(random_bytes(32))),'wrong browser binding');
ok(consume('wordpress',$state,$binding)['app']==='wordpress','valid browser state');rejects(fn()=>consume('wordpress',$state,$binding),'state replay');rejects(fn()=>consume('wordpress','../../passwd',$binding),'state traversal');
file_put_contents($path,json_encode(['app'=>'wordpress','binding'=>hash('sha256',$binding),'until'=>time()-1]));rejects(fn()=>consume('wordpress',$state,$binding),'expired state');unlink($path);
ok(treeRoute(['REQUEST_URI'=>'/tree/index.php/login/0'],[])==='/login/0','path login');ok(treeRoute(['REQUEST_URI'=>'/tree/index.php?route=/login/0'],['route'=>'/login/0'])==='/login/0','query route login');
// State budgets are applied before allocating and before redirecting to AWS.
$budget = sys_get_temp_dir().'/schuit-budget-'.bin2hex(random_bytes(8));mkdir($budget,0700);
try {
    for($i=0;$i<20;$i++){file_put_contents($budget.'/'.$i.'.json',json_encode(['client'=>'one','browser'=>'browser-'.$i]));}
    rejects(fn()=>Schuit\Cognito\checkStateBudget($budget,'one','new-browser',time()),'IP cannot consume global pool');
    Schuit\Cognito\checkStateBudget($budget,'two','second-browser',time());ok(true,'another client retains capacity');
    for($i=0;$i<3;$i++){file_put_contents($budget.'/b'.$i.'.json',json_encode(['client'=>'two','browser'=>'same-browser']));}
    rejects(fn()=>Schuit\Cognito\checkStateBudget($budget,'two','same-browser',time()),'browser outstanding state bounded');
    foreach(glob($budget.'/*.json') as $f){touch($f,time()-601);}
    Schuit\Cognito\checkStateBudget($budget,'one','same-browser',time());ok(count(glob($budget.'/*.json'))===0,'expired slots reclaimed');
} finally {foreach(glob($budget.'/*') as $f){unlink($f);}rmdir($budget);}
