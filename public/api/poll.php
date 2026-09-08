<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Holds.php';
require_once __DIR__ . '/../src/Presence.php';
require_once __DIR__ . '/../src/Signals.php';
require_once __DIR__ . '/../src/Tournament.php';
require_once __DIR__ . '/../src/FriendFeed.php';
require_once __DIR__ . '/../src/Pace.php';

/**
 * Fast, cheap signal poll for the matchmaking/signaling window.
 * GET /api/poll.php?id=<8-hex>[&wait=<seconds>][&fs=<cursor ms>]
 *   -> 204 No Content        nothing pending (empty body; the hold reads
 *                            shared memory only)
 *   -> 200 {"ok":true,"signals":[...]}   pending messages, drained on read
 *
 * With fs the answer also carries the friend presence delta, the presence
 * counters and the pace (4.6), and a friend TRANSITION ends the hold the
 * way a signal does - so the screens that watch friends need no heartbeat
 * of their own. signals is then allowed to be empty.
 *
 * With wait > 0 (long poll, capped by FOK_POLL_WAIT_MAX) the
 * request is held open and answers the moment a signal arrives, checking
 * the mailbox every 20 ms: signal forwarding latency is then ~20 ms
 * instead of a full client poll interval. The hold cap keeps one handshake
 * from sitting on a worker indefinitely, and the pool-wide budget (Holds)
 * keeps concurrent handshakes from exhausting the shared-hosting FPM
 * worker pool between them - past it a poll answers 204 without waiting.
 *
 * A poll is a beat like any other request: it refreshes presence (one
 * shared-memory store, see Presence) and nothing else - no counters, no
 * row. It is not needed during gameplay: game traffic and the 1 Hz alive
 * check run in-band over the peer-to-peer DataChannel; the server only
 * sees the slow hello heartbeat (with duel_with) every ~60 s.
 */
Util::cors();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    Util::fail('GET only', 405);
}

$id = $_GET['id'] ?? null;
if (!Util::isValidId($id)) {
    Util::fail('invalid id');
}
Util::noteCaller($id);
// Every request is a beat (see Presence): a client looping this poll
// cannot read as offline for want of a hello.
Presence::touch($id, Util::clientIp());
// A tournament participant's poll carries that tournament's deadlines, and
// it does so HERE, before the hold: what a deadline produces lands in the
// mailbox this request is about to drain. Once per request, never inside
// the loop below - the hold touches nothing but the mailbox.
Tournament::pulse($id);
$fs = $_GET['fs'] ?? null;
if ($fs !== null) {
    if (!is_string($fs) || preg_match('/^[0-9]{1,15}$/', $fs) !== 1) {
        Util::fail('invalid fs');
    }
    $fs = (int)$fs;
}
$wait = min((int)($_GET['wait'] ?? 0), FOK_POLL_WAIT_MAX);
// Waiting costs an FPM worker for its whole duration, and the pool has a
// budget for that (see Holds). Over the budget it is the WAIT that is given
// up, not the request: the mailbox is still read and anything pending is
// still delivered, exactly as a wait=0 poll would.
if ($wait > 0 && !Holds::claim()) {
    $wait = 0;
}

// Read before the hold, exactly like the mailbox: a screen opening with a
// cursor of 0 has its whole answer waiting and must not wait for a signal
// that is not coming.
$delta = $fs === null ? null : FriendFeed::delta($id, $fs);

$deadline = microtime(true) + $wait;
while (!Signals::any($id) && ($delta === null || $delta['rows'] === [])) {
    // Only the deadline can end this: PHP does not learn that the client
    // went away until the script tries to write to it, and this loop writes
    // nothing until it answers. connection_aborted() is 0 here however long
    // ago the caller left, so a hold always runs its full wait.
    if (microtime(true) >= $deadline) {
        http_response_code(204);
        exit;
    }
    usleep(FOK_POLL_CHECK_USEC);
    // What a friend's transition wakes. One shared-memory read per turn,
    // and it is a read of two stamps, never of the friends themselves.
    if ($fs !== null && FriendFeed::pending($id, $fs)) {
        $delta = FriendFeed::delta($id, $fs);
    }
}

$signals = Signals::take($id);
Load::tick('msg_out', count($signals));
$out = ['ok' => true, 'signals' => $signals];
if ($delta !== null) {
    $out['friends_delta'] = (object)$delta['rows'];
    $out['friends_at'] = $delta['at'];
    $out['friends_more'] = $delta['more'];
    // The counters and the hold decision ride the same return, so a screen
    // watching friends has no reason left to send a hello of its own. Both
    // are shared-memory reads (see Presence::counts, Holds).
    $out += Presence::counts();
    $e = Presence::entryOf($id);
    $out['pace'] = Pace::forTier(
        $e !== null && (int)($e['duel'] ?? 0) >= Util::since(FOK_DUEL_WINDOW)
            ? Pace::TIER_DUEL : Pace::TIER_LOBBY
    );
}
Util::jsonOut($out);
