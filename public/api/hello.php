<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Presence.php';
require_once __DIR__ . '/../src/Signals.php';

/**
 * Heartbeat and poll endpoint, the client's single periodic request.
 * POST {
 *   "id": "8-hex",
 *   "duel_with": "8-hex",       optional, while a 1:1 game runs
 *   "friends": ["8-hex", ...]   optional, ids to check for online status
 * }
 * Returns presence counters, pending signaling messages for the caller
 * (drained on read) and, when friends were sent, which of them are online.
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

Presence::touch($id, Util::clientIp());
Util::bump('hello');

$duelWith = $body['duel_with'] ?? null;
if ($duelWith !== null) {
    if (!Util::isValidId($duelWith) || $duelWith === $id) {
        Util::fail('invalid duel_with');
    }
    Presence::touchDuel($id, $duelWith);
}

$out = [
    'ok' => true,
    'api' => FOK_API_VERSION,
    'now' => time(),
    'signals' => Signals::take($id),
] + Presence::counts();

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
    $online = Presence::onlineOf($friends);
    $out['friends_online'] = new stdClass();
    foreach ($friends as $f) {
        $out['friends_online']->$f = isset($online[$f]);
    }
}

Util::jsonOut($out);
