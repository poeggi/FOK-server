<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Presence.php';
require_once __DIR__ . '/../src/Alerts.php';
require_once __DIR__ . '/../src/Settings.php';
require_once __DIR__ . '/../src/ConnTrack.php';
require_once __DIR__ . '/../src/RelayRate.php';

/**
 * In-duel message relay - the FALLBACK when the peer-to-peer DataChannel
 * cannot be established. The server becomes the hub and forwards opaque
 * messages between the two peers of a duel.
 *
 * POST {"id": sender, "peer": recipient, "payload": string, "pts": ms?}
 *   -> {"ok":true}
 *   -> 429 "relay backlog full"  receiver stopped fetching; back off
 *   -> 429 "relay rate limit"    sender sustained too high a send rate;
 *                                blocked for relay_rate_block_secs
 *   -> 503 "relay busy"          concurrent-duel cap reached; the pair
 *                                cannot start relaying now
 *
 * GET ?id=<me>&peer=<sender>[&wait=<seconds>]
 *   -> 200 {"ok":true,"messages":[{"seq":n,"payload":"...","created":s}]}
 *      oldest first, drained on delivery (exactly-once)
 *   -> 204 nothing pending (after the long-poll hold, like poll.php)
 *
 * Every relayed duel occupies FPM workers with its long polls, so
 * admission is capped (relay_max_duels). A pair holds its slot from its
 * first message through the hub until FOK_RELAY_WINDOW after its last,
 * so a running duel is never turned away. Expect ~200-400 ms one-way:
 * relay INPUT events and hashes, not high-rate state (see docs/API.md).
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
    // The hold loop peeks with an indexed read and takes no lock while
    // idle: SQLite has one writer, and a waiting poll must not fight the
    // duels that are actually sending.
    $peek = $db->prepare('SELECT 1 FROM relay WHERE to_id = ? AND from_id = ? LIMIT 1');
    $st = $db->prepare('SELECT id, payload, created FROM relay WHERE to_id = ? AND from_id = ? ORDER BY id');
    $deadline = microtime(true) + $wait;
    while (true) {
        $peek->execute([$id, $peer]);
        $rows = [];
        if ($peek->fetchColumn() !== false) {
            // Read and drain in ONE transaction: two overlapping polls
            // must never both be handed the same message - replayed
            // inputs desync the duel.
            $db->exec('BEGIN IMMEDIATE');
            try {
                $st->execute([$id, $peer]);
                $rows = $st->fetchAll();
                if ($rows !== []) {
                    $ids = array_column($rows, 'id');
                    $ph = implode(',', array_fill(0, count($ids), '?'));
                    $db->prepare("DELETE FROM relay WHERE id IN ($ph)")->execute($ids);
                }
                $db->exec('COMMIT');
            } catch (Throwable $e) {
                // SQLite auto-rolls back on some faults; a bare ROLLBACK
                // would then throw and mask the real error.
                if ($db->inTransaction()) {
                    $db->exec('ROLLBACK');
                }
                throw $e;
            }
        }
        if ($rows !== []) {
            Load::tick('msg_out', count($rows));
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
// A client sustaining too high a send rate is turned away for a while
// (see RelayRate) - a cheap indexed read, before any of the work below.
if (RelayRate::blocked($id)) {
    Util::fail('relay rate limit: slow down', 429);
}
$payload = $body['payload'] ?? null;
if (!is_string($payload) || $payload === '' || strlen($payload) > Settings::int('relay_max_payload')) {
    Util::fail('invalid payload');
}
Util::checkPts($body['pts'] ?? null, "player $id");

$db = Db::get();
$now = time();
$db->prepare('DELETE FROM relay WHERE created < ?')->execute([$now - Settings::int('relay_ttl')]);

$st = $db->prepare('SELECT COUNT(*) FROM relay WHERE to_id = ?');
$st->execute([$peer]);
if ((int)$st->fetchColumn() >= Settings::int('relay_pending_cap')) {
    Alerts::raise('spam', "Relay backlog full for $peer (sender $id)");
    Util::fail('relay backlog full', 429);
}

[$a, $b] = $id < $peer ? [$id, $peer] : [$peer, $id];
$pair = "$a:$b";
// Only a pair without a slot is checked: a duel already relaying must
// never be cut off mid-game by a full server.
if (!ConnTrack::isRelaying($id, $peer)
    && ConnTrack::relayPairs() >= Settings::int('relay_max_duels')) {
    Alerts::raise('relay', 'Relay duel cap reached: new relayed duel rejected');
    Util::fail('relay busy', 503);
}

$db->prepare('INSERT INTO relay (pair, from_id, to_id, payload, created) VALUES (?, ?, ?, ?, ?)')
    ->execute([$pair, $id, $peer, $payload, $now]);
// Ground truth for "this pair runs through the hub": no declared
// no-P2P bit is needed to end up here.
ConnTrack::relaying($id, $peer);
Util::bump('relay');
// Deferred, like bump: count this message and check the sender's rate
// after the response is flushed, never in its latency.
Util::defer(static fn() => RelayRate::record($id));

Util::jsonOut(['ok' => true]);
