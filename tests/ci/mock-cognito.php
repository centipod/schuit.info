<?php
// Synthetic broker only. This file is never copied into the release image.
header('Content-Type: application/json');
$d=json_decode(file_get_contents('php://input'),true);
$users=['manager'=>['11111111-1111-4111-8111-111111111111','ci_manager@example.invalid'],'reader'=>['22222222-2222-4222-8222-222222222222','ci_reader@example.invalid'],'tree'=>['33333333-3333-4333-8333-333333333333','ci_tree_admin@example.invalid'],'unlinked'=>['44444444-4444-4444-8444-444444444444','unknown@example.invalid']];
if(!isset($users[$d['code']??''])){http_response_code(403);echo '{}';return;}
[$sub,$email]=$users[$d['code']];echo json_encode(['sub'=>$sub,'email'=>$email,'email_verified'=>true]);
