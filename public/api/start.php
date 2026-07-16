<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Presence.php';
require_once __DIR__ . '/../src/Starts.php';

/**
 * Server-issued level start.
 * POST {"id": "8-hex", "peer": "8-hex"}
 *   -> {"ok":true, "start_pts": <ms>, "now": <ms>}
 *
 * BOTH peers call this when they are ready (DataChannel open, level
 * loaded); each receives the identical absolute start PTS, issued by
 * the server from its own clock. The lead time is at least the
 * start_lead_min_ms setting and scales with the players' reported
 * latencies. A new start can be requested once the previous one has
 * passed (next level, rematch).
 */
Util::cors();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Util::fail('POST only', 405);
}

$body = Util::jsonBody();
$id = $body['id'] ?? null;
$peer = $body['peer'] ?? null;
if (!Util::isValidId($id) || !Util::isValidId($peer) || $id === $peer) {
    Util::fail('invalid id/peer');
}

Presence::touch($id, Util::clientIp());
Util::bump('start');

Util::jsonOut([
    'ok' => true,
    'start_pts' => Starts::request($id, $peer),
    'now' => Util::nowMs(),
]);
