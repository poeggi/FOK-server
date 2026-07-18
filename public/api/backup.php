<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Vault.php';

/**
 * Client stats backup / restore (see docs/API.md for the payload manifest).
 *
 *   POST {"id": <8hex>, "payload": <string>, "token"?: <hex>}
 *        -> 200 {"ok": true, "token": <hex>, "updated": <unix seconds>}
 *        First backup: omit token; the server mints one and returns it -
 *        the client MUST store it (with its id). Every later backup must
 *        send that token; it comes back unchanged.
 *        -> 403 {"error": "bad token"}   a backup exists and the token is
 *                                        missing or wrong.
 *
 *   GET  ?id=<8hex>&token=<hex>
 *        -> 200 {"ok": true, "payload": <string>, "updated": <unix seconds>}
 *        -> 404 {"error": "no backup"}   nothing stored for this id
 *        -> 403 {"error": "bad token"}   wrong token
 *        Restores it on a new device from id + token alone.
 *
 * The payload is OPAQUE to the server (never parsed), capped at
 * FOK_STATS_MAX bytes. Its shape and versioning are the client's; the token
 * binds the backup to whoever made it (a client that loses the token loses
 * access - see Vault).
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
