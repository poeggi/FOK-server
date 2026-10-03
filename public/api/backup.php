<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Ident.php';
require_once __DIR__ . '/../src/Vault.php';

/**
 * Client config backup / restore (contract + payload manifest in docs/API.md).
 *   POST {id, tok, payload}        -> {ok, updated} | 401 bad token
 *   POST {id, tok, restore: true}  -> {ok, payload, updated} | 404 no backup |
 *                                     401 bad token
 * Payload is OPAQUE (never parsed), capped at FOK_STATS_MAX. The identity
 * token is what binds a backup to its owner (see Ident): the same one every
 * other request carries, minted by hello. A bound id's backup is read and
 * replaced by its token and nothing else.
 */
Util::cors();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Util::fail('POST only', 405);
}

$body = Util::jsonBody();
$id = $body['id'] ?? '';
if (!Util::isValidId($id)) {
    Util::fail('invalid id');
}
Util::noteCaller($id);
[, $tok] = Ident::read($body);
Ident::require($id, $tok, Util::clientIp());
if (array_key_exists('restore', $body)) {
    if ($body['restore'] !== true) {
        Util::fail('invalid restore');
    }
    $res = Vault::restore($id);
    if ($res === null) {
        Util::fail('no backup', 404);
    }
    if (!Ident::proves($id, $tok)) {
        Util::fail('bad token', 401);
    }
    Util::jsonOut(['ok' => true, 'payload' => $res['payload'], 'updated' => $res['updated']]);
}
$payload = $body['payload'] ?? null;
if (!is_string($payload) || $payload === '') {
    Util::fail('invalid payload');
}
if (strlen($payload) > FOK_STATS_MAX) {
    Util::fail('payload too large', 413);
}
if (!Ident::proves($id, $tok)) {
    Util::fail('bad token', 401);
}
Util::jsonOut(['ok' => true, 'updated' => Vault::backup($id, $payload)]);
