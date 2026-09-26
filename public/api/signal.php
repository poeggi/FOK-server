<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Ident.php';
require_once __DIR__ . '/../src/Presence.php';
require_once __DIR__ . '/../src/Signals.php';
require_once __DIR__ . '/../src/Friends.php';
require_once __DIR__ . '/../src/ConnTrack.php';
require_once __DIR__ . '/../src/Starts.php';

/**
 * Matchmaking / WebRTC signaling: SDP, ICE and the handshake between peers.
 * POST {"id": sender, "to": recipient,
 *       "type": one of Signals::TYPES (invite, accept, decline, offer,
 *         answer, ice, ices, bye, watch) - the
 *         reserved 'friend' and 'undelivered' types are server-generated
 *         and rejected here,
 *       "payload": string, opaque to the server (SDP/ICE/profile JSON;
 *         max 16 KB),
 *       "pts": int ms on the shared clock (optional but expected once the
 *         client is time-synced; future-dated values are rejected + logged)}
 *
 * Authorization: invite requires an accepted friendship with "to" (403
 * otherwise); the other types are free-form signaling the client
 * correlates to its own in-progress handshake. Delivery is via the
 * recipient's hello.php or poll.php poll; a flooded recipient mailbox
 * answers 429.
 */
Util::cors();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Util::fail('POST only', 405);
}

$body = Util::jsonBody();
$id = $body['id'] ?? null;
$to = $body['to'] ?? null;
$type = $body['type'] ?? null;
$payload = $body['payload'] ?? '';

if (!Util::isValidId($id) || !Util::isValidId($to) || $id === $to) {
    Util::fail('invalid id/to');
}
Util::noteCaller($id);
Ident::require($id, Ident::read($body)[1], Util::clientIp());
if (!is_string($type) || !in_array($type, Signals::TYPES, true)) {
    Util::fail('invalid type');
}
Util::noteAction($type);
if (!is_string($payload) || strlen($payload) > FOK_SIGNAL_MAX_PAYLOAD) {
    Util::fail('invalid payload');
}
// 'ices' (4.4) is the one payload with a shape, because its whole reason for
// existing is the count: a side's trickle in ONE request instead of a request
// per candidate. The server checks that it IS a bounded list and nothing
// more - what a candidate looks like stays between the two peers, as it does
// for every other payload here.
if ($type === 'ices') {
    $cands = json_decode($payload, true);
    $n = is_array($cands) ? count($cands) : 0;
    if ($n < 1 || $n > Settings::int('ices_max')
        || array_keys($cands) !== range(0, $n - 1)) {
        Util::fail('invalid payload');
    }
}
Util::checkPts($body['pts'] ?? null, "player $id");

// Game invites require a recorded, accepted friendship; quick match
// (match.php) is the deliberate way to play with strangers.
if ($type === 'invite' && !Friends::isFriend($id, $to)) {
    Util::fail('not friends', 403);
}
// A pair blocked in either direction (docs/API.md, Moderation): the
// signal is accepted like any other and goes nowhere, so the sender cannot
// tell a block from a peer that never answers. Two shared-memory reads in
// the steady state (see Friends::blockedIds).
if (Friends::isBlocked($id, $to)) {
    Presence::touch($id, Util::clientIp());
    Util::jsonOut(['ok' => true]);
}

// The start epoch counts halts within ONE connection, so it resets with
// the connection - but it resets where the connection BEGINS, because
// that is the only end the server reliably sees. A bye travels over the
// open DataChannel and never reaches us, which left the pair's finished
// epoch line standing and refused their rematch at epoch 0 with a 409
// until the row aged out. 'invite' opens the friend flow,
// 'offer' opens quick match (which has no invite) and any renegotiation:
// one DELETE per duel setup, never per signal. Dropping the row is always
// safe - the pair simply re-creates it on their next start - so erring
// towards resetting costs nothing, while missing one costs the rematch.
//
// 'bye' is deliberately NOT in that list. It is an END, and the three above
// already cover every pairing that follows one; a bye that does reach the
// server is the fallback case, where the pair re-handshakes through one of
// them anyway. Resetting on it bought nothing and spent the writer at the
// one moment the pair's own start.php wants it.
if ($type === 'invite' || $type === 'offer') {
    Starts::forget($id, $to);
}

Presence::touch($id, Util::clientIp());
if (!Signals::send($id, $to, $type, $payload)) {
    Alerts::raise('spam', "Client spam: mailbox of $to flooded (last sender $id)");
    Util::fail('mailbox full', 429);
}
// Only a queued message says anything about the connection.
ConnTrack::note($id, $to, $type);

// An 'accept' confirms the pairing: hand both sides the peer-net hint now,
// before offer/answer, so a same-family pair can try direct first.
if ($type === 'accept') {
    Presence::announceNet($id, $to);
}
Util::bump('signal');

Util::jsonOut(['ok' => true]);
