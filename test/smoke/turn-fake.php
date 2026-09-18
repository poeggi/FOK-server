<?php
declare(strict_types=1);

// A stand-in for Cloudflare's TURN key API, for the local smoke test:
// php -S serves this file as its router and turn.json's rtc_base points
// the server at it. Two routes, the mint and the revoke, both under key
// "kid" with token "ktok". FAKE_DIR names a directory the smoke controls:
// mints.log gets "<n> <id> <ttl>" per mint and revokes.log the username
// per revoke, so the smoke can count and match them.
$dir = getenv('FAKE_DIR') ?: sys_get_temp_dir();
$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$body = json_decode((string)file_get_contents('php://input'), true) ?: [];

if ($method === 'GET' && $path === '/') {
    header('Content-Type: text/plain');
    echo "fake\n";
    exit;
}
if ($method === 'POST' && preg_match('#^/v1/turn/keys/([^/]+)/credentials/generate-ice-servers$#', $path, $m)) {
    if ($auth !== 'Bearer ktok' || $m[1] !== 'kid') {
        http_response_code(401);
        echo json_encode(['success' => false, 'errors' => [['message' => 'bad key']]]);
        exit;
    }
    $n = (is_file($dir . '/mints.log') ? count(file($dir . '/mints.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : 0) + 1;
    $id = (string)($body['customIdentifier'] ?? '');
    file_put_contents($dir . '/mints.log', "$n $id " . (int)($body['ttl'] ?? 0) . "\n", FILE_APPEND);
    http_response_code(201);
    header('Content-Type: application/json');
    echo json_encode(['iceServers' => [
        ['urls' => ['stun:stun.cloudflare.com:3478', 'stun:stun.cloudflare.com:53']],
        ['urls' => ['turn:turn.cloudflare.com:3478?transport=udp', 'turn:turn.cloudflare.com:53?transport=udp',
            'turn:turn.cloudflare.com:3478?transport=tcp', 'turns:turn.cloudflare.com:5349?transport=tcp'],
         'username' => "u$n-$id", 'credential' => "c$n-" . bin2hex(random_bytes(8))],
    ]]);
    exit;
}
if ($method === 'POST' && preg_match('#^/v1/turn/keys/([^/]+)/credentials/([^/]+)/revoke$#', $path, $m)) {
    if ($auth !== 'Bearer ktok' || $m[1] !== 'kid') {
        http_response_code(401);
        exit;
    }
    file_put_contents($dir . '/revokes.log', $m[2] . "\n", FILE_APPEND);
    http_response_code(204);
    exit;
}
http_response_code(404);
echo json_encode(['error' => 'no such route: ' . $method . ' ' . $path]);
