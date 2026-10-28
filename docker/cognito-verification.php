<?php
declare(strict_types=1);
namespace Schuit\Cognito;
require_once __DIR__.'/cognito.php';
require_once __DIR__.'/vendor/autoload.php';
use Firebase\JWT\{JWT,JWK};

const IDENTITY_MAX_AGE_SECONDS = 600;
const IDENTITY_CLOCK_SKEW_SECONDS = 30;

function signingKeys(array $jwks): array {
    $keys = [];
    foreach ($jwks['keys'] ?? [] as $key) {
        if (($key['kty'] ?? null) === 'RSA' && ($key['alg'] ?? null) === 'RS256' && ($key['use'] ?? null) === 'sig') {
            $keys[] = $key;
        }
    }
    return $keys;
}

function validateTokenContext(array $claims, array $cfg, string $nonce): void {
    if (($claims['iss'] ?? null) !== $cfg['issuer'] || ($claims['aud'] ?? null) !== $cfg['client_id'] || ($claims['token_use'] ?? null) !== 'id') {
        throw new \RuntimeException('Invalid identity claims');
    }
    if (!is_string($claims['nonce'] ?? null) || !hash_equals($nonce, $claims['nonce'])) {
        throw new \RuntimeException('Invalid identity claims');
    }
}

function validateTokenAge(array $claims): void {
    if (!is_int($claims['exp'] ?? null) || !is_int($claims['iat'] ?? null)) {
        throw new \RuntimeException('Invalid identity claims');
    }
    if ($claims['iat'] < time() - IDENTITY_MAX_AGE_SECONDS || $claims['iat'] > time() + IDENTITY_CLOCK_SKEW_SECONDS) {
        throw new \RuntimeException('Invalid identity claims');
    }
}

function validateIdentity(array $claims): void {
    if (!is_string($claims['sub'] ?? null) || !preg_match('/^[a-f0-9-]{36}$/D', $claims['sub'])) {
        throw new \RuntimeException('Invalid identity claims');
    }
    if (($claims['email_verified'] ?? null) !== true || !is_string($claims['email'] ?? null) || !filter_var($claims['email'], FILTER_VALIDATE_EMAIL)) {
        throw new \RuntimeException('Invalid identity claims');
    }
}

function verifyIdToken(string $token, array $jwks, array $cfg, string $nonce): array {
    // JWT verifies signature and expiry; helpers keep each additional trust check explicit.
    JWT::$leeway = IDENTITY_CLOCK_SKEW_SECONDS;
    $claims = (array)JWT::decode($token, JWK::parseKeySet(['keys'=>signingKeys($jwks)], 'RS256'));
    validateTokenContext($claims, $cfg, $nonce);
    validateTokenAge($claims);
    validateIdentity($claims);
    return ['sub'=>$claims['sub'], 'email'=>$claims['email'], 'email_verified'=>true];
}
function awsRequest(string $url, ?array $form=null): array {
    $ch=curl_init($url);$raw='';
    $options=[CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>7,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_WRITEFUNCTION=>static function($c,$chunk)use(&$raw){if(strlen($raw)+strlen($chunk)>65536){return 0;}$raw.=$chunk;return strlen($chunk);}];
    if($form!==null){$options[CURLOPT_POST]=true;$options[CURLOPT_POSTFIELDS]=http_build_query($form);$options[CURLOPT_HTTPHEADER]=['Content-Type: application/x-www-form-urlencoded'];}
    curl_setopt_array($ch,$options);$ok=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
    if($ok===false || $status!==200){throw new \RuntimeException('AWS identity request failed');}
    return json_decode($raw,true,32,JSON_THROW_ON_ERROR);
}
