<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Presence.php';
require_once __DIR__ . '/../src/Alerts.php';
require_once __DIR__ . '/../src/Settings.php';

/**
 * In-duel message relay - the FALLBACK when the peer-to-peer DataChannel
 * cannot be established. The server becomes the hub and forwards opaque
 * messages between the two peers of a duel.
 *
 * POST {"id": sender, "peer": recipient, "payload": string, "pts": ms?}
 *   -> {"ok":true}
 *   -> 429 "relay backlog full"  receiver stopped fetching; back off
 *   -> 503 "relay busy"          concurrent-duel cap reached; the pair
 *                                cannot start relaying now
 *
 * GET ?id=<me>&peer=<sender>[&wait=<seconds>]
 *   -> 200 {"ok":true,"messages":[{"seq":n,"payload":"...","created":s}]}
 *      oldest first, drained on delivery (exactly-once)
 *   -> 204 nothing pending (after the long-poll hold, like poll.php)
 *
 * Capacity notes: every relayed duel occupies FPM workers with its long
 * polls, so admission is capped (relay_max_duels); a pair counts as an
 * active relay while it exchanged anything within the last 30 s. Expect
 * one-way latency of ~200-400 ms: relay INPUT events and hashes, not
 * high-rate state (see docs/API.md).
 */
Util::cors();
$method = $_SERVER['REQUEST_METHOD'] ?? '';

if ($method === 'GET') {
    $id = $_GET['id'] ?? null;
    $peer = $_GET['peer'] ?? null;
    if (!Util::isValidId($id) || !Util::isValidId($peer) || $id === $peer) {
        Util::fail('invalid id/peer');
    }
    $wait = min((int)($_GET['wait'] ?? 0), Settings::int('poll_wait_max'));
    $db = Db::get();
    $st = $db->prepare('SELECT id, payload, created FROM relay WHERE to_id = ? AND from_id = ? ORDER BY id');
    $deadline = microtime(true) + $wait;
    while (true) {
        $st->execute([$id, $peer]);
        $rows = $st->fetchAll();
        if ($rows !== []) {
            $ids = array_column($rows, 'id');
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $db->prepare("DELETE FROM relay WHERE id IN ($ph)")->execute($ids);
            Util::jsonOut(['ok' => true, 'messages' => array_map(static fn(array $r) => [
                'seq' => (int)$r['id'],
                'payload' => $r['payload'],
                'created' => (int)$r['created'],
            ], $rows)]);
        }
        if (microtime(true) >= $deadline || connection_aborted()) {
            http_response_code(204);
            exit;
        }
        usleep(FOK_POLL_CHECK_USEC);
    }
}

if ($method !== 'POST') {
    Util::fail('GET or POST only', 405);
}

$body = Util::jsonBody();
$id = $body['id'] ?? null;
$peer = $body['peer'] ?? null;
if (!Util::isValidId($id) || !Util::isValidId($peer) || $id === $peer) {
    Util::fail('invalid id/peer');
}
$payload = $body['payload'] ?? null;
if (!is_string($payload) || $payload === '' || strlen($payload) > Settings::int('relay_max_payload')) {
    Util::fail('invalid payload');
}
Util::checkPts($body['pts'] ?? null, "player $id");

$db = Db::get();
$now = time();
$db->prepare('DELETE FROM relay WHERE created < ?')->execute([$now - 60]);

$st = $db->prepare('SELECT COUNT(*) FROM relay WHERE to_id = ?');
$st->execute([$peer]);
if ((int)$st->fetchColumn() >= Settings::int('relay_pending_cap')) {
    Alerts::raise('spam', "Relay backlog full for $peer (sender $id)");
    Util::fail('relay backlog full', 429);
}

[$a, $b] = $id < $peer ? [$id, $peer] : [$peer, $id];
$pair = "$a:$b";
$st = $db->prepare('SELECT 1 FROM relay WHERE pair = ? AND created > ? LIMIT 1');
$st->execute([$pair, $now - 30]);
if ($st->fetchColumn() === false) {
    $st = $db->prepare('SELECT COUNT(DISTINCT pair) FROM relay WHERE created > ?');
    $st->execute([$now - 30]);
    if ((int)$st->fetchColumn() >= Settings::int('relay_max_duels')) {
        Alerts::raise('relay', 'Relay duel cap reached: new relayed duel rejected');
        Util::fail('relay busy', 503);
    }
}

$db->prepare('INSERT INTO relay (pair, from_id, to_id, payload, created) VALUES (?, ?, ?, ?, ?)')
    ->execute([$pair, $id, $peer, $payload, $now]);
Util::bump('relay');

Util::jsonOut(['ok' => true]);
