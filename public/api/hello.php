<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Presence.php';
require_once __DIR__ . '/../src/Signals.php';

/**
 * Heartbeat and poll endpoint, the client's single periodic request.
 * POST {"id": "8-hex", "duel_with": "8-hex" (optional, while in a 1:1 game)}
 * Returns presence counters plus any pending signaling messages for the
 * caller (drained on read). Clients poll slowly (~30s) when idle and fast
 * (~1-2s) while matchmaking or signaling.
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

Presence::touch($id, Util::clientIp());
Util::bump('hello');

$duelWith = $body['duel_with'] ?? null;
if ($duelWith !== null) {
    if (!Util::isValidId($duelWith) || $duelWith === $id) {
        Util::fail('invalid duel_with');
    }
    Presence::touchDuel($id, $duelWith);
}

Util::jsonOut([
    'ok' => true,
    'now' => time(),
    'signals' => Signals::take($id),
] + Presence::counts());
