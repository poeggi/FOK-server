<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Signals.php';

/**
 * Fast, cheap signal poll for the matchmaking/signaling window.
 * GET /api/poll.php?id=<8-hex>
 *   -> 204 No Content        nothing pending (the common case; empty body,
 *                            single indexed read, no database writes)
 *   -> 200 {"ok":true,"signals":[...]}   pending messages, drained on read
 *
 * Unlike hello.php this does NOT touch presence or counters, so clients
 * can poll it at 1-2 Hz without generating write load. It is not needed
 * during gameplay: game traffic and the 1 Hz alive check run in-band over
 * the peer-to-peer DataChannel; the server only sees the slow hello
 * heartbeat (with duel_with) every ~30 s.
 */
Util::cors();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    Util::fail('GET only', 405);
}

$id = $_GET['id'] ?? null;
if (!Util::isValidId($id)) {
    Util::fail('invalid id');
}

if (!Signals::any($id)) {
    http_response_code(204);
    exit;
}

Util::jsonOut(['ok' => true, 'signals' => Signals::take($id)]);
