<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Presence.php';
require_once __DIR__ . '/../src/Friends.php';
require_once __DIR__ . '/../src/Signals.php';

/**
 * Friendship management. An ACCEPTED friendship entitles both sides to
 * query each other's status (hello's friends_* maps) and to send game
 * invites.
 *
 * POST {"id": "8-hex", "action": "request|accept|remove", "peer": "8-hex"}
 *   request -> {"ok":true,"state":"pending"}  (or "accepted" when the
 *              peer had already asked - requests auto-match)
 *   accept  -> {"ok":true,"state":"accepted"} (404 without a pending
 *              request from that peer)
 *   remove  -> {"ok":true}                    (declines or unfriends)
 *
 * A new request or an acceptance NOTIFIES the peer: the server puts a
 * reserved 'friend' signal into the peer's mailbox (payload JSON
 * {"event":"request"|"accepted","from":"8-hex"}), delivered through the
 * peer's next hello or poll.php request like any other signal.
 *
 * POST {"id": "8-hex", "action": "list"}
 *   -> {"ok":true,"friends":[{"id","state":"pending|accepted",
 *       "outgoing":bool,"name","online","latency"}]}
 *   name/online/latency are only filled for accepted friendships.
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
$action = $body['action'] ?? '';

Presence::touch($id, Util::clientIp());
Util::bump('friend');

if ($action === 'list') {
    $list = Friends::listOf($id);
    $accepted = array_column(array_filter($list, static fn(array $f) => $f['state'] === 'accepted'), 'id');
    $info = Presence::infoOf($accepted);
    foreach ($list as &$f) {
        $peerInfo = $f['state'] === 'accepted' ? ($info[$f['id']] ?? null) : null;
        $f['name'] = $peerInfo['name'] ?? null;
        $f['online'] = $peerInfo['online'] ?? false;
        $f['latency'] = $peerInfo['latency'] ?? null;
    }
    Util::jsonOut(['ok' => true, 'friends' => $list]);
}

$peer = $body['peer'] ?? null;
if (!Util::isValidId($peer) || $peer === $id) {
    Util::fail('invalid peer');
}

switch ($action) {
    case 'request':
        $st = Db::get()->prepare('SELECT friend_ban_until FROM players WHERE id = ?');
        $st->execute([$id]);
        $bannedUntil = (int)$st->fetchColumn();
        $st->closeCursor();
        if ($bannedUntil > time()) {
            Util::fail('friend requests banned', 429);
        }
        $r = Friends::request($id, $peer);
        if ($r['changed'] && $r['state'] === 'pending' && Presence::isAutoAccepting($peer)) {
            // The peer is on the QR/add-friend screen: consent is implied,
            // the handshake completes immediately.
            Friends::forceAccept($id, $peer);
            $r['state'] = 'accepted';
        }
        if ($r['changed'] && $r['state'] === 'pending') {
            // Mass-requesting spam: alert, ban for a while, and purge
            // every pending request the spammer created.
            $db = Db::get();
            $st = $db->prepare(
                "SELECT COUNT(*) FROM friends WHERE requester = ? AND state = 'pending' AND created > ?"
            );
            $st->execute([$id, time() - 3600]);
            $unanswered = (int)$st->fetchColumn();
            $st->closeCursor();
            if ($unanswered > Settings::int('friend_req_max')) {
                $ban = Settings::int('friend_ban_seconds');
                $db->prepare('UPDATE players SET friend_ban_until = ? WHERE id = ?')
                    ->execute([time() + $ban, $id]);
                $st = $db->prepare("DELETE FROM friends WHERE requester = ? AND state = 'pending'");
                $st->execute([$id]);
                Alerts::raise('friend-spam',
                    "Friend-request spam: $id banned for {$ban}s, " . $st->rowCount() . ' pending requests purged');
                Util::fail('friend request spam - banned', 429);
            }
        }
        if ($r['changed']) {
            $event = $r['state'] === 'accepted' ? 'accepted' : 'request';
            Signals::send($id, $peer, 'friend', json_encode(['event' => $event, 'from' => $id]));
        }
        Util::jsonOut(['ok' => true, 'state' => $r['state']]);
    case 'accept':
        if (!Friends::accept($id, $peer)) {
            Util::fail('no pending request from that peer', 404);
        }
        Signals::send($id, $peer, 'friend', json_encode(['event' => 'accepted', 'from' => $id]));
        Util::jsonOut(['ok' => true, 'state' => 'accepted']);
    case 'remove':
        Friends::remove($id, $peer);
        Util::jsonOut(['ok' => true]);
    default:
        Util::fail('invalid action');
}
