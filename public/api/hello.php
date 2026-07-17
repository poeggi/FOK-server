<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Presence.php';
require_once __DIR__ . '/../src/Signals.php';
require_once __DIR__ . '/../src/Friends.php';
require_once __DIR__ . '/../src/ConnTrack.php';

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
 *   "friends": ["8-hex", ...]   optional, ids to check
 * }
 * Returns presence counters, pending signaling messages for the caller
 * (drained on read) and, for requested friends, online/latency/name -
 * filled ONLY for ids with an ACCEPTED friendship to the caller.
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

Presence::touch($id, Util::clientIp(), $latency, $name, $autoAccept);
Util::bump('hello');

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
// caller's pending invites on the floor with no way to ever get them back.
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

$out = [
    'ok' => true,
    'api' => FOK_API_VERSION,
    'now' => Util::nowMs(),
    'signals' => Signals::take($id),
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
}

Util::jsonOut($out);
