<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Signals.php';

/**
 * Fast, cheap signal poll for the matchmaking/signaling window.
 * GET /api/poll.php?id=<8-hex>[&wait=<seconds>]
 *   -> 204 No Content        nothing pending (empty body, indexed reads
 *                            only, no database writes)
 *   -> 200 {"ok":true,"signals":[...]}   pending messages, drained on read
 *
 * With wait > 0 (long poll, capped by the poll_wait_max setting) the
 * request is held open and answers the moment a signal arrives, checking
 * the mailbox every 150 ms: signal forwarding latency is then ~150 ms
 * instead of a full client poll interval. The hold cap keeps concurrent
 * handshakes from exhausting the shared-hosting FPM worker pool.
 *
 * Unlike hello.php this does NOT touch presence or counters. It is not
 * needed during gameplay: game traffic and the 1 Hz alive check run
 * in-band over the peer-to-peer DataChannel; the server only sees the
 * slow hello heartbeat (with duel_with) every ~30 s.
 */
Util::cors();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    Util::fail('GET only', 405);
}

$id = $_GET['id'] ?? null;
if (!Util::isValidId($id)) {
    Util::fail('invalid id');
}
$wait = min((int)($_GET['wait'] ?? 0), Settings::int('poll_wait_max'));

$deadline = microtime(true) + $wait;
while (!Signals::any($id)) {
    if (microtime(true) >= $deadline || connection_aborted()) {
        http_response_code(204);
        exit;
    }
    usleep(FOK_POLL_CHECK_USEC);
}

Util::jsonOut(['ok' => true, 'signals' => Signals::take($id)]);
