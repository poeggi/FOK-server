<?php
declare(strict_types=1);

// A stand-in for Cloudflare's two TURN APIs, for the local smoke test:
// php -S serves this file as its router, and turn.json's rtc_base and
// api_base point the server at it. FAKE_DIR names a directory the smoke
// controls: usage.json is what the usage read answers ({"egress","ingress",
// "top":[[id,bytes],...]}, or {"fail":true} for a 500), mints.log and
// revokes.log get one line per call, so the smoke can count them.
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
if ($method === 'POST' && $path === '/client/v4/graphql') {
    if ($auth !== 'Bearer atok') {
        http_response_code(403);
        echo json_encode(['errors' => [['message' => 'authentication error']]]);
        exit;
    }
    $u = json_decode((string)@file_get_contents($dir . '/usage.json'), true) ?: [];
    if (!empty($u['fail'])) {
        http_response_code(500);
        echo 'boom';
        exit;
    }
    $top = [];
    foreach ($u['top'] ?? [] as $t) {
        $top[] = ['dimensions' => ['customIdentifier' => (string)$t[0]], 'sum' => ['egressBytes' => (int)$t[1]]];
    }
    header('Content-Type: application/json');
    echo json_encode(['data' => ['viewer' => ['accounts' => [[
        'total' => [['sum' => ['egressBytes' => (int)($u['egress'] ?? 0), 'ingressBytes' => (int)($u['ingress'] ?? 0)]]],
        'top' => $top,
    ]]]], 'errors' => null]);
    exit;
}
http_response_code(404);
echo json_encode(['error' => 'no such route: ' . $method . ' ' . $path]);
