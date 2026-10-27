<?php
declare(strict_types=1);
require_once __DIR__.'/cognito-verification.php';
header('Content-Type: application/json');header('Cache-Control: no-store');
try {
    $cfg=\Schuit\Cognito\config();
    if (($_SERVER['REQUEST_URI']??'')==='/health' && ($_SERVER['REQUEST_METHOD']??'')==='GET') {
        echo json_encode(['ready'=>true,'enabled'=>$cfg['enabled']]);return;
    }
    if (!$cfg['enabled'] || ($_SERVER['REQUEST_URI']??'')!=='/exchange' || ($_SERVER['REQUEST_METHOD']??'')!=='POST') {http_response_code(404);echo '{}';return;}
    $raw=file_get_contents('php://input',false,null,0,8193);
    if(strlen($raw)>8192){throw new RuntimeException('Oversized request');}
    $data=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
    if(!in_array($data['app']??null,['wordpress','webtrees'],true) || !is_string($data['code']??null) || strlen($data['code'])<1 || strlen($data['code'])>2048 || !is_string($data['verifier']??null) || !preg_match('/^[A-Za-z0-9_-]{43,128}$/D',$data['verifier']) || !is_string($data['nonce']??null) || !preg_match('/^[A-Za-z0-9_-]{43}$/D',$data['nonce'])){throw new RuntimeException('Invalid request');}
    $tokens=\Schuit\Cognito\awsRequest($cfg['domain'].'/oauth2/token',['grant_type'=>'authorization_code','client_id'=>$cfg['client_id'],'redirect_uri'=>\Schuit\Cognito\callback($data['app']),'code'=>$data['code'],'code_verifier'=>$data['verifier']]);
    // Fetch only the configured issuer's JWKS. No token-controlled URLs; rotations work immediately.
    $jwks=\Schuit\Cognito\awsRequest($cfg['issuer'].'/.well-known/jwks.json');
    echo json_encode(\Schuit\Cognito\verifyIdToken($tokens['id_token']??'', $jwks, $cfg, $data['nonce']),JSON_THROW_ON_ERROR);
} catch(Throwable $e) {http_response_code(403);echo '{"error":"Login rejected"}';}
