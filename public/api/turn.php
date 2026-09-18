<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Ident.php';
require_once __DIR__ . '/../src/Presence.php';
require_once __DIR__ . '/../src/Turn.php';

/**
 * TURN credentials for a duel no direct path can carry (API 4.22).
 * POST {"id": "8-hex", "tok": "<32-hex>"}
 *   -> {"ok":true, "ice":[...iceServers...], "ttl":<seconds left>}
 *   -> 503 {"ok":false, "error":"turn_unavailable"}   STUN only, then
 *
 * One refusal for every reason - no key, switched off, the month's
 * budget spent, the relay's API silent - because the client does the
 * same thing in all of them. What decides is Turn::mint.
 */
Util::cors();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Util::fail('POST only', 405);
}

$body = Util::jsonBody();
$id = $body['id'] ?? null;
if (!Util::isValidId($id)) {
    Util::fail('invalid id');
}
Util::noteCaller($id);
Ident::require($id, Ident::read($body)[1], Util::clientIp());

Presence::touch($id, Util::clientIp());
Util::bump('turn');

$r = Turn::mint($id);
if ($r === null) {
    Util::fail('turn_unavailable', 503);
}
Util::jsonOut(['ok' => true, 'ice' => $r['ice'], 'ttl' => $r['ttl']]);
