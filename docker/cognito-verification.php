<?php
declare(strict_types=1);
namespace Schuit\Cognito;
require_once __DIR__.'/cognito.php';
require_once __DIR__.'/vendor/autoload.php';
use Firebase\JWT\{JWT,JWK};

function verifyIdToken(string $token, array $jwks, array $cfg, string $nonce): array {
    // Bind key type/algorithm; reject algorithm substitution even if a JWKS entry is malformed.
    $keys=[];
    foreach ($jwks['keys']??[] as $key) {
        if (($key['kty']??null)==='RSA' && ($key['alg']??null)==='RS256' && ($key['use']??null)==='sig') { $keys[]=$key; }
    }
    JWT::$leeway=30;
    $claims=(array)JWT::decode($token,JWK::parseKeySet(['keys'=>$keys],'RS256'));
    if (($claims['iss']??null)!==$cfg['issuer'] || ($claims['aud']??null)!==$cfg['client_id'] ||
        ($claims['token_use']??null)!=='id' || !is_int($claims['exp']??null) || !is_int($claims['iat']??null) ||
        $claims['iat']<time()-600 || $claims['iat']>time()+30 || !is_string($claims['nonce']??null) || !hash_equals($nonce,$claims['nonce']) ||
        !is_string($claims['sub']??null) || !preg_match('/^[a-f0-9-]{36}$/D',$claims['sub']) ||
        ($claims['email_verified']??null)!==true || !is_string($claims['email']??null) || !filter_var($claims['email'],FILTER_VALIDATE_EMAIL)) { throw new \RuntimeException('Invalid identity claims'); }
    return ['sub'=>$claims['sub'],'email'=>$claims['email'],'email_verified'=>true];
}
function awsRequest(string $url, ?array $form=null): array {
    $ch=curl_init($url);$raw='';
    $options=[CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>7,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_WRITEFUNCTION=>static function($c,$chunk)use(&$raw){if(strlen($raw)+strlen($chunk)>65536){return 0;}$raw.=$chunk;return strlen($chunk);}];
    if($form!==null){$options[CURLOPT_POST]=true;$options[CURLOPT_POSTFIELDS]=http_build_query($form);$options[CURLOPT_HTTPHEADER]=['Content-Type: application/x-www-form-urlencoded'];}
    curl_setopt_array($ch,$options);$ok=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
    if($ok===false || $status!==200){throw new \RuntimeException('AWS identity request failed');}
    return json_decode($raw,true,32,JSON_THROW_ON_ERROR);
}
