<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Presence.php';
require_once __DIR__ . '/../src/Friends.php';

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
        Util::jsonOut(['ok' => true, 'state' => Friends::request($id, $peer)]);
    case 'accept':
        if (!Friends::accept($id, $peer)) {
            Util::fail('no pending request from that peer', 404);
        }
        Util::jsonOut(['ok' => true, 'state' => 'accepted']);
    case 'remove':
        Friends::remove($id, $peer);
        Util::jsonOut(['ok' => true]);
    default:
        Util::fail('invalid action');
}
