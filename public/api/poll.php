<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Holds.php';
require_once __DIR__ . '/../src/Presence.php';
require_once __DIR__ . '/../src/Signals.php';
require_once __DIR__ . '/../src/Tournament.php';
require_once __DIR__ . '/../src/Events.php';
require_once __DIR__ . '/../src/Friends.php';
require_once __DIR__ . '/../src/FriendFeed.php';
require_once __DIR__ . '/../src/Pace.php';

/**
 * Fast, cheap signal poll for the matchmaking/signaling window.
 * GET /api/poll.php?id=<8-hex>[&wait=<seconds>][&fs=<cursor ms>]
 *                          [&aa=1][&fl=1][&tl=1][&ev=1][&de=<8-hex>]
 *                          [&db=0|1]
 *   -> 204 No Content        nothing pending (empty body; the hold reads
 *                            shared memory only)
 *   -> 200 {"ok":true,"signals":[...]}   pending messages, drained on read
 *
 * With fs the answer also carries the friend presence delta, the presence
 * counters and the pace (4.6), and a friend TRANSITION ends the hold the
 * way a signal does - so the screens that watch friends need no heartbeat
 * of their own. signals is then allowed to be empty.
 *
 * aa, fl, tl, de and db (4.9), and ev (4.11), are the rest of that same
 * idea: what a screen HOLDING this poll would otherwise have had to send
 * a hello for - arming auto-accept, the whole roster, the local
 * tournament announce, the caller's own events, the announced end of a
 * duel, and the client's own debug state. Each is the same field of the
 * same name on hello. fl, tl and ev ANSWER AT ONCE: a screen that just
 * opened is not waiting for a signal that is not coming.
 *
 * The line this draws, and the reason it lands here: everything the SERVER
 * has to say unasked rides this response - api and debug on every body,
 * the pace and the counters beside them. now and q_ms do not: they are
 * readings a client asks for with a hello, and the clock is t.txt's.
 * What stays hello's otherwise is what the CLIENT knows is due and the
 * server cannot: a rename, a latency reading, its networks, and
 * duel_with, which belongs to a game where nothing is holding a poll
 * anyway.
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
// The hello answers a holding screen can ask for here instead (4.9).
// Absent is null and changes nothing - a poll that did not mention a thing
// is not asserting anything about it - and anything but a flag is a typo
// worth saying so about, the way fs is.
$flag = static function (string $k): ?bool {
    $v = $_GET[$k] ?? null;
    if ($v === null) {
        return null;
    }
    if ($v !== '0' && $v !== '1') {
        Util::fail("invalid $k");
    }
    return $v === '1';
};
$armAccept = $flag('aa') === true;
$wantRoster = $flag('fl') === true;
$wantTourneys = $flag('tl') === true;
$wantEvents = $flag('ev') === true;
// What the client reports its OWN debug mode to be, as hello's `debug`
// does. Absent is null here rather than hello's false: a poll that did not
// say is not a client saying no.
$debugActive = $flag('db');
// Every request is a beat (see Presence): a client looping this poll
// cannot read as offline for want of a hello. Auto-accept is the one
// window a beat does NOT carry, so a poll may ARM it - and only arm it.
// Clearing stays hello's, because absence here is a client that simply
// did not ask, and the window expires on its own either way.
$debug = Presence::touch($id, Util::clientIp(), null, null, $armAccept ? true : null, $debugActive);
// The other edge of a duel, as hello's duel_end (4.7). Once the
// DataChannel is open a bye goes peer-to-peer and the server never sees
// it, so the end has to be STATED - and the screen a client returns to
// afterwards is one holding this poll.
$duelEnd = $_GET['de'] ?? null;
if ($duelEnd !== null) {
    if (!Util::isValidId($duelEnd)) {
        Util::fail('invalid de');
    }
    Presence::endDuel($id, $duelEnd);
}
// The one thing only the SERVER can know is due: an operator has flipped
// this client's debug mode and it has not reported acting on it. A 204 has
// no body to carry an instruction in, so this answers instead of holding.
// Only for a caller that reports its own state, and at most once per hold
// period. A disagreement can legitimately STAND for ever - a wish of false
// against a developer who turned debug on locally, which is the 'self'
// state the admin view exists to show - and a client that re-arms the
// moment it is answered turns a standing one into a loop. So the wake is
// an edge as far as shared memory can make it one: the first poll that
// sees a difference is answered, and the ones behind it wait as usual.
$instruct = $debugActive !== null && $debug !== $debugActive
    && apcu_add(FOK_APCU_NS . 'dbgwake:' . $id, 1, FOK_POLL_WAIT_MAX + 1) === true;
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
$roster = $wantRoster ? Friends::rosterOf($id) : null;
$tourneys = $wantTourneys ? Tournament::announce($id, Util::clientIp()) : null;
$events = $wantEvents ? Events::listFor($id) : null;

$deadline = microtime(true) + $wait;
while (!Signals::any($id) && ($delta === null || $delta['rows'] === [])
       && $roster === null && $tourneys === null && $events === null && !$instruct) {
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
// api and debug ride EVERY answer with a body, because they are the two
// things that travel server to client only: a client cannot know either is
// due, so it can never be its job to ask. api un-latches a client after a
// rollback; debug is the operator's reach into it.
$out = [
    'ok' => true,
    'api' => FOK_API_VERSION,
    'debug' => $debug,
    'signals' => $signals,
];
if ($roster !== null) {
    $out['friends'] = $roster;
}
if ($tourneys !== null) {
    $out['tourneys'] = $tourneys;
}
if ($events !== null) {
    $out['events'] = $events;
}
if ($delta !== null) {
    $out['friends_delta'] = (object)$delta['rows'];
    $out['friends_at'] = $delta['at'];
    $out['friends_more'] = $delta['more'];
}
if ($delta !== null || $roster !== null || $tourneys !== null) {
    // The counters and the hold decision ride the same return, so a screen
    // that asked for any of hello's answers has no reason left to send one.
    // Both are shared-memory reads (see Presence::counts, Holds). The pace
    // above all: it is how the server withdraws holding, and a client that
    // has stopped beating must still be able to hear that.
    $out += Presence::counts();
    $e = Presence::entryOf($id);
    $tier = Pace::TIER_LOBBY;
    if ($e !== null && (int)($e['duel'] ?? 0) >= Util::since(FOK_DUEL_WINDOW)) {
        $tier = Pace::TIER_DUEL;
    } elseif ($tourneys !== null) {
        $tier = Pace::TIER_TOURNEY;
    }
    $out['pace'] = Pace::forTier($tier);
}
Util::jsonOut($out);
