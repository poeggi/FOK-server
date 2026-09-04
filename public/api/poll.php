<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Holds.php';
require_once __DIR__ . '/../src/Signals.php';

/**
 * Fast, cheap signal poll for the matchmaking/signaling window.
 * GET /api/poll.php?id=<8-hex>[&wait=<seconds>]
 *   -> 204 No Content        nothing pending (empty body, indexed reads
 *                            only, no database writes)
 *   -> 200 {"ok":true,"signals":[...]}   pending messages, drained on read
 *
 * With wait > 0 (long poll, capped by FOK_POLL_WAIT_MAX) the
 * request is held open and answers the moment a signal arrives, checking
 * the mailbox every 20 ms: signal forwarding latency is then ~20 ms
 * instead of a full client poll interval. The hold cap keeps one handshake
 * from sitting on a worker indefinitely, and the pool-wide budget (Holds)
 * keeps concurrent handshakes from exhausting the shared-hosting FPM
 * worker pool between them - past it a poll answers 204 without waiting.
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
$wait = min((int)($_GET['wait'] ?? 0), FOK_POLL_WAIT_MAX);
// Waiting costs an FPM worker for its whole duration, and the pool has a
// budget for that (see Holds). Over the budget it is the WAIT that is given
// up, not the request: the mailbox is still read and anything pending is
// still delivered, exactly as a wait=0 poll would.
if ($wait > 0 && !Holds::claim()) {
    $wait = 0;
}

$deadline = microtime(true) + $wait;
while (!Signals::any($id)) {
    // Only the deadline can end this: PHP does not learn that the client
    // went away until the script tries to write to it, and this loop writes
    // nothing until it answers. connection_aborted() is 0 here however long
    // ago the caller left, so a hold always runs its full wait.
    if (microtime(true) >= $deadline) {
        http_response_code(204);
        exit;
    }
    usleep(FOK_POLL_CHECK_USEC);
}

$signals = Signals::take($id);
Load::tick('msg_out', count($signals));
Util::jsonOut(['ok' => true, 'signals' => $signals]);
