<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Presence.php';
require_once __DIR__ . '/../src/Signals.php';
require_once __DIR__ . '/../src/Friends.php';
require_once __DIR__ . '/../src/ConnTrack.php';
require_once __DIR__ . '/../src/Tournament.php';

/**
 * Heartbeat and poll endpoint, the client's single periodic request.
 * POST {
 *   "id": "8-hex",
 *   "name": "PLAYER",           optional, display name; recorded and shown
 *                               to accepted friends
 *   "duel_with": "8-hex",       optional, while a 1:1 game runs
 *   "latency": int ms,          optional, the client's measured latency
 *                               (mandated regularly, see docs/API.md)
 *   "auto_accept": bool,        optional, true while the QR/add-friend
 *                               screen is open (auto-accepts requests)
 *   "debug": bool,              optional, whether the client IS in debug
 *                               mode (absent means it is not)
 *   "friends": ["8-hex", ...]   optional, ids to check
 *   "tourneys": bool            optional, ask for open tournament lobbies
 *                               hosted on one of the caller's own networks
 *   "nets": ["ip", ...]         optional, the caller's OWN public addresses
 *                               as it discovered them (STUN), so the family
 *                               this request did not arrive over is known
 *                               too - see Presence::claim
 * }
 * Returns presence counters, the server's debug wish for this client,
 * pending signaling messages for the caller (drained on read) and, for
 * requested friends, online/latency/name plus friends_playing - all filled
 * ONLY for ids with an ACCEPTED friendship to the caller.
 * Clients send this every ~30s; fast polling belongs to poll.php.
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

$duelWith = $body['duel_with'] ?? null;
if ($duelWith !== null) {
    if (!Util::isValidId($duelWith) || $duelWith === $id) {
        Util::fail('invalid duel_with');
    }
    Presence::touchDuel($id, $duelWith);
    ConnTrack::playing($id, $duelWith);
}

// EVERY input is validated before the mailbox is touched: Signals::take()
// deletes what it returns, so a Util::fail() after it would drop the
// caller's pending invites for good.
$friends = null;
if (isset($body['friends'])) {
    $friends = $body['friends'];
    if (!is_array($friends) || count($friends) > FOK_MAX_FRIENDS) {
        Util::fail('invalid friends');
    }
    foreach ($friends as $f) {
        if (!Util::isValidId($f)) {
            Util::fail('invalid friends');
        }
    }
}
$tourneys = $body['tourneys'] ?? false;
if (!is_bool($tourneys)) {
    Util::fail('invalid tourneys');
}

$signals = Signals::take($id);
Load::tick('msg_out', count($signals));
$out = [
    'ok' => true,
    'api' => FOK_API_VERSION,
    'now' => Util::nowMs(),
    // The client MUST honour this: true turns its debug mode on, false
    // turns it off again. It reports back what it actually did via the
    // debug field of the next hello.
    'debug' => $debug,
    'signals' => $signals,
] + Presence::counts();

if ($friends !== null) {
    // Status is only served for ACCEPTED friendships; everything else
    // reads as offline/unknown so mere possession of an id leaks nothing.
    $accepted = Friends::acceptedOf($id, $friends);
    $info = Presence::infoOf(array_keys($accepted));
    $out['friends_online'] = new stdClass();
    $out['friends_latency'] = new stdClass();
    $out['friends_name'] = new stdClass();
    foreach ($friends as $f) {
        $out['friends_online']->$f = $info[$f]['online'] ?? false;
        $out['friends_latency']->$f = $info[$f]['latency'] ?? null;
        $out['friends_name']->$f = $info[$f]['name'] ?? null;
    }
    // Online is not the same as available: a friend already in a duel cannot
    // take an invite or join a lobby, and saying so up front is the
    // difference between a considered invite and a wasted one.
    $out['friends_playing'] = Presence::playingOf(array_keys($accepted));
}

// Lobbies are announced by NETWORK, not by friendship: a tournament is a
// room full of people who are in the same room. The code stays the way in
// from anywhere else, and it is the capability - so nothing here reveals a
// lobby to someone who could not already see the host.
//
// Lazy maintenance rides along, the same shape as the item ledger's
// truncation: no cron exists, so a lobby whose host walked away is reaped by
// whichever hello asks about lobbies next. Deferred - it is the server's
// bookkeeping, not this caller's, and it must not sit in a heartbeat.
if ($tourneys) {
    $out['tourneys'] = Tournament::announce($id, Util::clientIp());
    Util::defer(static fn() => Tournament::reapLobbies());
}

Util::jsonOut($out);
