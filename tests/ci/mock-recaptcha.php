<?php
// CI-only stand-in. Not copied into the release image or deployed compose file.
header('Content-Type: application/json');
if (($_SERVER['REQUEST_URI'] ?? '') === '/health') { echo '{"ready":true}'; return; }
$input = json_decode(file_get_contents('php://input'), true);
if (($input['token'] ?? '') === 'ci-unavailable') { http_response_code(503); echo '{}'; return; }
echo json_encode(['success' => ($input['token'] ?? '') === 'ci-solved-token' && ($input['host'] ?? '') === 'localhost']);
