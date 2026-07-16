<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Presence.php';
require_once __DIR__ . '/../src/Signals.php';
require_once __DIR__ . '/../src/Friends.php';

/**
 * Matchmaking / WebRTC signaling relay.
 * POST {"id": sender, "to": recipient,
 *       "type": invite|accept|decline|offer|answer|ice|bye|chat,
 *       "payload": string, opaque to the server (SDP/ICE JSON, profile
 *       JSON on matchmaking types, plain text capped at 120 bytes for chat),
 *       "pts": int ms on the shared clock (optional but expected once the
 *       client is time-synced; future-dated values are rejected + logged)}
 * Delivery happens through the recipient's hello.php or poll.php poll.
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
if ($type === 'invite-relay' || $type === 'accept-relay') {
    $db = Db::get();
    [$a, $b] = $id < $to ? [$id, $to] : [$to, $id];
    $st = $db->prepare('SELECT 1 FROM relay WHERE pair = ? AND created > ? LIMIT 1');
    $st->execute(["$a:$b", time() - 30]);
    if ($st->fetchColumn() === false) {
        $st = $db->prepare('SELECT COUNT(DISTINCT pair) FROM relay WHERE created > ?');
        $st->execute([time() - 30]);
        if ((int)$st->fetchColumn() >= Settings::int('relay_max_duels')) {
            Alerts::raise('relay', 'Relay duel cap reached: no-P2P game declaration rejected');
            Util::fail('relay busy', 503);
        }
    }
}

Presence::touch($id, Util::clientIp());
if (!Signals::send($id, $to, $type, $payload)) {
    Alerts::raise('spam', "Client spam: mailbox of $to flooded (last sender $id)");
    Util::fail('mailbox full', 429);
}
Util::bump('signal');

Util::jsonOut(['ok' => true]);
