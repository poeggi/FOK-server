<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Vault.php';

/**
 * Client config backup / restore (contract + payload manifest in docs/API.md).
 *   POST {id, payload, token?} -> {ok, token, updated} | 403 bad token
 *        First backup omits the token; the server mints and returns it, and
 *        every later backup must send it (it comes back unchanged).
 *   GET  ?id=&token=           -> {ok, payload, updated} | 404 no backup |
 *        403 bad token
 * Payload is OPAQUE (never parsed), capped at FOK_STATS_MAX; the token binds
 * a backup to its owner (see Vault).
 */
Util::cors();
$method = $_SERVER['REQUEST_METHOD'] ?? '';

if ($method === 'GET') {
    $id = $_GET['id'] ?? '';
    if (!Util::isValidId($id)) {
        Util::fail('invalid id');
    }
    $token = $_GET['token'] ?? '';
    if ($token === '') {
        Util::fail('token required');
    }
    $res = Vault::restore($id, $token);
    if ($res === null) {
        Util::fail('no backup', 404);
    }
    if ($res === false) {
        Util::fail('bad token', 403);
    }
    Util::jsonOut(['ok' => true, 'payload' => $res['payload'], 'updated' => $res['updated']]);
}

if ($method !== 'POST') {
    Util::fail('GET or POST only', 405);
}

$body = Util::jsonBody();
$id = $body['id'] ?? '';
if (!Util::isValidId($id)) {
    Util::fail('invalid id');
}
$payload = $body['payload'] ?? null;
if (!is_string($payload) || $payload === '') {
    Util::fail('invalid payload');
}
if (strlen($payload) > FOK_STATS_MAX) {
    Util::fail('payload too large', 413);
}
$token = $body['token'] ?? null;
if ($token !== null && !is_string($token)) {
    Util::fail('invalid token');
}
$res = Vault::backup($id, $payload, $token);
if ($res === null) {
    Util::fail('bad token', 403);
}
Util::jsonOut(['ok' => true, 'token' => $res['token'], 'updated' => $res['updated']]);
