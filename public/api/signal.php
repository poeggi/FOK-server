<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Presence.php';
require_once __DIR__ . '/../src/Signals.php';
require_once __DIR__ . '/../src/Friends.php';
require_once __DIR__ . '/../src/ConnTrack.php';

/**
 * Matchmaking / WebRTC signaling relay.
 * POST {"id": sender, "to": recipient,
 *       "type": one of Signals::TYPES (invite, invite-relay, accept,
 *         accept-relay, decline, offer, answer, ice, bye, chat) - the
 *         reserved 'friend' and 'undelivered' types are server-generated
 *         and rejected here,
 *       "payload": string, opaque to the server (SDP/ICE/profile JSON;
 *         plain text capped at chat_max_len for chat, else max 16 KB),
 *       "pts": int ms on the shared clock (optional but expected once the
 *         client is time-synced; future-dated values are rejected + logged)}
 *
 * Authorization: invite / invite-relay require an accepted friendship
 * with "to" (403 otherwise); the other types are free-form signaling the
 * client correlates to its own in-progress handshake. The -relay types
 * declare hub-relayed play and are capacity-checked here (503 when the
 * relay-duel cap is reached). Delivery is via the recipient's hello.php
 * or poll.php poll; a flooded recipient mailbox answers 429.
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
if (!is_string($type) || !in_array($type, Signals::TYPES, true)) {
    Util::fail('invalid type');
}
$max = $type === 'chat' ? Settings::int('chat_max_len') : FOK_SIGNAL_MAX_PAYLOAD;
if (!is_string($payload) || strlen($payload) > $max) {
    Util::fail('invalid payload');
}
Util::checkPts($body['pts'] ?? null, "player $id");

// Game invites require a recorded, accepted friendship; quick match
// (match.php) is the deliberate way to play with strangers.
if (($type === 'invite' || $type === 'invite-relay') && !Friends::isFriend($id, $to)) {
    Util::fail('not friends', 403);
}

// The no-P2P declaration (from EITHER side) means the game will run
// through the server hub without a P2P attempt - so relay capacity is
// checked right now, and a full relay answers 503 before any game
// setup is wasted.
if (($type === 'invite-relay' || $type === 'accept-relay')
    && !ConnTrack::isRelaying($id, $to)
    && ConnTrack::relayPairs() >= Settings::int('relay_max_duels')) {
    Alerts::raise('relay', 'Relay duel cap reached: no-P2P game declaration rejected');
    Util::fail('relay busy', 503);
}

// 'bye' ends the pairing, so its relay backlog dies with it: an
// undelivered input must never reach the pair's next duel.
if ($type === 'bye') {
    $db = Db::get();
    [$a, $b] = $id < $to ? [$id, $to] : [$to, $id];
    $db->prepare('DELETE FROM relay WHERE pair = ?')->execute(["$a:$b"]);
}

Presence::touch($id, Util::clientIp());
if (!Signals::send($id, $to, $type, $payload)) {
    Alerts::raise('spam', "Client spam: mailbox of $to flooded (last sender $id)");
    Util::fail('mailbox full', 429);
}
// Only a queued message says anything about the connection.
ConnTrack::note($id, $to, $type);
Util::bump('signal');

Util::jsonOut(['ok' => true]);
