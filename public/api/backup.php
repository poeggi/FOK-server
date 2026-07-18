<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Vault.php';

/**
 * Client stats backup / restore (see docs/API.md for the payload manifest).
 *
 *   POST {"id": <8hex>, "payload": <string>}
 *        -> 200 {"ok": true, "updated": <unix seconds>}
 *        stores (or replaces) this player's backup.
 *
 *   GET  ?id=<8hex>
 *        -> 200 {"ok": true, "payload": <string>, "updated": <unix seconds>}
 *        -> 404 {"error": "no backup"}     nothing stored for this id
 *        restores it on a new device.
 *
 * The payload is OPAQUE to the server (it is never parsed), capped at
 * FOK_STATS_MAX bytes. Its shape and versioning are the client's - see the
 * manifest in docs/API.md.
 *
 * SECURITY (future): restore and overwrite are keyed by player id ALONE, and
 * ids are exchanged during a duel, so anyone who learns an id can read or
 * replace that player's backup. This is intentionally open for now. Before
 * clients trust it with anything sensitive, gate restore (and overwrite)
 * behind a shared secret the client sets on its first backup.
 */
Util::cors();
$method = $_SERVER['REQUEST_METHOD'] ?? '';

if ($method === 'GET') {
    $id = $_GET['id'] ?? '';
    if (!Util::isValidId($id)) {
        Util::fail('invalid id');
    }
    $row = Vault::get($id);
    if ($row === null) {
        Util::fail('no backup', 404);
    }
    Util::jsonOut(['ok' => true, 'payload' => $row['payload'], 'updated' => $row['updated']]);
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

Util::jsonOut(['ok' => true, 'updated' => Vault::put($id, $payload)]);
