<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Presence.php';
require_once __DIR__ . '/../src/Signals.php';
require_once __DIR__ . '/../src/Friends.php';
require_once __DIR__ . '/../src/FriendFeed.php';
require_once __DIR__ . '/../src/ConnTrack.php';
require_once __DIR__ . '/../src/Tournament.php';
require_once __DIR__ . '/../src/Events.php';
require_once __DIR__ . '/../src/Pace.php';

/**
 * The heartbeat: the beat a client sends when it has nothing else to say
 * (every request is a beat, see Presence), and the slow drain of its
 * mailbox.
 * POST {
 *   "id": "8-hex",
 *   "name": "PLAYER",           optional, display name; recorded and shown
 *                               to accepted friends
 *   "duel_with": "8-hex",       optional, the peer while a 1vs1 runs. It
 *                               REFRESHES a duel start.php already put on
 *                               record; it is not what puts it there
 *   "duel_private": bool,       optional, 4.7: this duel counts but is
 *                               never attributed to the caller, so no
 *                               friend is offered a spectate link for it
 *   "duel_end": "8-hex",        optional, 4.7: the peer the caller has just
 *                               STOPPED playing, sent at teardown
 *   "latency": int ms,          optional, the client's measured latency
 *                               (mandated regularly, see docs/API.md)
 *   "auto_accept": bool,        optional, true while the QR/add-friend
 *                               screen is open (auto-accepts requests)
 *   "debug": bool,              optional, whether the client IS in debug
 *                               mode (absent means it is not)
 *   "friends_since": int ms     the cursor: answer with the caller's
 *                               accepted friends whose presence changed
 *                               after it (0 = all of them)
 *   "friends_list": bool        optional, return the caller's WHOLE roster
 *                               (the same array friend.php `list` returns)
 *                               so a screen showing it needs no second
 *                               request alongside this heartbeat
 *   "tourneys": bool            optional, ask for open tournament lobbies
 *   "events": bool              optional, ask for the caller's own events
 *                               hosted on one of the caller's own networks
 *   "nets": ["ip", ...]         optional, the caller's OWN public addresses
 *                               as it discovered them (STUN), so the family
 *                               this request did not arrive over is known
 *                               too - see Presence::claim
 * }
 * Returns presence counters, the server's debug wish for this client,
 * pending signaling messages for the caller (drained on read) and, when a
 * cursor is sent, what changed about the caller's ACCEPTED friends since
 * it - the server names them, the caller never does.
 * Clients send this every ~60 s when nothing else is in flight (docs/API.md,
 * Pacing); fast polling belongs to poll.php.
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

$latency = $body['latency'] ?? null;
if ($latency !== null && (!is_int($latency) || $latency < 0 || $latency > 60000)) {
    Util::fail('invalid latency');
}
$name = null;
if (isset($body['name'])) {
    if (!is_string($body['name'])) {
        Util::fail('invalid name');
    }
    $name = mb_substr(trim($body['name']), 0, FOK_MAX_NAME_LEN);
    if ($name === '') {
        $name = null;
    }
}
$autoAccept = $body['auto_accept'] ?? false;
if (!is_bool($autoAccept)) {
    Util::fail('invalid auto_accept');
}
// What the client reports it IS doing, which is not the same as what the
// admin asked for: a client may enter debug mode by itself, and a fresh
// wish is not honoured until this hello. Both are kept (see Db step 12).
$debugActive = $body['debug'] ?? false;
if (!is_bool($debugActive)) {
    Util::fail('invalid debug');
}
// What the client says about ITSELF: the public addresses it found for its
// own machine. We see one address family per request and cannot ask a
// browser for the other, so this is the only way the second one can be
// known - it is a claim, and Presence ranks it below what we observed.
// Structure is validated strictly; an individual address that turns out to
// be unusable (a private ICE candidate, an mDNS placeholder) is dropped
// there rather than failed here, because gathering those is normal.
$nets = $body['nets'] ?? null;
if ($nets !== null) {
    if (!is_array($nets) || count($nets) > FOK_MAX_NETS) {
        Util::fail('invalid nets');
    }
    foreach ($nets as $n) {
        if (!is_string($n) || strlen($n) > 45) {
            Util::fail('invalid nets');
        }
    }
}

$debug = Presence::touch($id, Util::clientIp(), $latency, $name, $autoAccept, $debugActive);
Util::bump('hello');
if ($nets !== null) {
    Presence::claim($id, $nets);
}

// The duel a client is in is stated the way online/offline is: an edge in,
// an edge out, and a window that expires if neither arrives (see
// Presence::touchDuel). The edge IN is start.php, which both peers call at
// the moment play begins - what arrives here is the refresh that holds the
// duel up, and the teardown. The END is applied FIRST, so one hello may
// carry both and a rematch announced in a single request lands on the new
// pairing rather than being cancelled by the old one's teardown.
$duelEnd = $body['duel_end'] ?? null;
if ($duelEnd !== null) {
    if (!Util::isValidId($duelEnd)) {
        Util::fail('invalid duel_end');
    }
    Presence::endDuel($id, $duelEnd);
}

$duelWith = $body['duel_with'] ?? null;
if ($duelWith !== null) {
    if (!Util::isValidId($duelWith) || $duelWith === $id) {
        Util::fail('invalid duel_with');
    }
    $duelPrivate = $body['duel_private'] ?? false;
    if (!is_bool($duelPrivate)) {
        Util::fail('invalid duel_private');
    }
    Presence::touchDuel($id, $duelWith, $duelPrivate);
    ConnTrack::playing($id, $duelWith);
}

// EVERY input is validated before the mailbox is touched: Signals::take()
// deletes what it returns, so a Util::fail() after it would drop the
// caller's pending invites for good.
$wantRoster = !empty($body['friends_list']);
$since = $body['friends_since'] ?? null;
if ($since !== null && (!is_int($since) || $since < 0)) {
    Util::fail('invalid friends_since');
}
$tourneys = $body['tourneys'] ?? false;
if (!is_bool($tourneys)) {
    Util::fail('invalid tourneys');
}
$events = $body['events'] ?? false;
if (!is_bool($events)) {
    Util::fail('invalid events');
}

// A tournament participant's heartbeat carries that tournament's deadlines
// the way its poll does (see poll.php), so a client that is not polling
// still keeps the clock moving - and picks up what a deadline produced in
// the drain right below.
Tournament::pulse($id);
$signals = Signals::take($id);
Load::tick('msg_out', count($signals));

// What this client is in the middle of decides how much patience the server
// will spend on it when the pool fills (see Pace). A duel is the only thing
// here whose handshake a player can feel; a lobby is waiting for something
// that has not happened yet and gives way first.
$tier = $duelWith !== null ? Pace::TIER_DUEL
    : ($tourneys ? Pace::TIER_TOURNEY : Pace::TIER_LOBBY);
$q = Load::queueUs();

$out = [
    'ok' => true,
    'api' => FOK_API_VERSION,
    'now' => Util::nowMs(),
    // Additive since 4.4: how long THIS request waited for a worker before
    // any PHP ran. Normally 0. A client reads it to know the host is busy
    // right now - above all, not to anchor its clock against this moment.
    'q_ms' => $q === null ? 0 : (int)round($q / 1000),
    // Additive since 4.4: the beat the server wants from this client. Only
    // the server can see its own load, so only the server can set it.
    'pace' => Pace::forTier($tier),
    // The client MUST honour this: true turns its debug mode on, false
    // turns it off again. It reports back what it actually did via the
    // debug field of the next hello.
    'debug' => $debug,
    'signals' => $signals,
] + Presence::counts();

if ($since !== null) {
    // 4.6: the same authorization, asked the other way round. The caller
    // names no ids - the server answers for the friendships it already
    // knows about, and only for what changed since the cursor.
    $delta = FriendFeed::delta($id, $since);
    $out['friends_delta'] = (object)$delta['rows'];
    $out['friends_at'] = $delta['at'];
    $out['friends_more'] = $delta['more'];
}

// The roster itself, which is not what the status maps above it are. Those
// can only answer for ids the request already named; this is who the
// caller's friends ARE - pending rows, outgoing rows, and names for ids the
// client has never seen. Serving it here is the whole point of the flag: a
// screen that shows the roster was sending this heartbeat anyway, and its
// second request queued behind it.
if ($wantRoster) {
    $out['friends'] = Friends::rosterOf($id);
}

// Lobbies are announced by NETWORK, not by friendship: a tournament is a
// room full of people who are in the same room. The code stays the way in
// from anywhere else, and it is the capability - so nothing here reveals a
// lobby to someone who could not already see the host.
//
// A lobby whose host walked away needs no reaping: tournament state is held
// in shared memory with the join TTL on it, so an unstarted lobby simply
// stops existing (see TourneyStore).
if ($tourneys) {
    $out['tourneys'] = Tournament::announce($id, Util::clientIp());
}
// The caller's own events, and the only way a client knows it is in one
// at all - a removed member finds the row simply gone. Costs one
// shared-memory read in the steady state (see Events::mine).
if ($events) {
    $out['events'] = Events::listFor($id);
}

Util::jsonOut($out);
