<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Presence.php';
require_once __DIR__ . '/../src/Alerts.php';
require_once __DIR__ . '/../src/Settings.php';
require_once __DIR__ . '/../src/ConnTrack.php';
require_once __DIR__ . '/../src/RelayRate.php';
require_once __DIR__ . '/../src/RelayStore.php';

/**
 * In-duel message relay - the FALLBACK when the peer-to-peer DataChannel
 * cannot be established. The server becomes the hub and forwards opaque
 * messages between the two peers of a duel.
 *
 * POST {"id": sender, "peer": recipient, "payload": string, "pts": ms?,
 *       "pull": bool?}
 *   -> {"ok":true}
 *   -> {"ok":true,"messages":[...]}  when "pull" is set and the sender has
 *                                inbound pending: piggybacked so delivery
 *                                does not depend on the held GET alone. The
 *                                messages are DRAINED, so a client that does
 *                                not read them loses them (v3.2, docs/API.md).
 *   -> 429 "relay backlog full"  receiver stopped fetching; back off
 *   -> 429 "relay store full"    hub shared memory momentarily full; the
 *                                message was refused - resend it
 *   -> 429 "relay rate limit"    sender sustained too high a send rate;
 *                                blocked for relay_rate_block_secs
 *   -> 503 "relay busy"          concurrent-duel cap reached; the pair
 *                                cannot start relaying now
 *
 * GET ?id=<me>&peer=<sender>[&wait=<seconds>]
 *   -> 200 {"ok":true,"messages":[{"seq":n,"payload":"...","created":s,"age":ms}]}
 *      oldest first, drained on delivery (exactly-once). "age" is ms on the
 *      server before this delivery (mailbox delay vs FPM-queue delay).
 *   -> 200 {"ok":true,"gone":true}  the pairing was torn down (a bye/decline
 *      marked it ended): the peer left, end the session now (v3.3). A client
 *      that ignores it falls back to its own liveness timeout.
 *   -> 204 nothing pending (after the long-poll hold, like poll.php)
 *
 * Every relayed duel occupies FPM workers with its long polls, so
 * admission is capped (relay_max_duels). A pair holds its slot from its
 * first message through the hub until FOK_RELAY_WINDOW after its last,
 * so a running duel is never turned away. Budget ~200-400 ms one-way as a
 * CONSERVATIVE upper bound - what the client's prediction model should be
 * built to absorb, not a measured typical. The server's own share is small:
 * about the poll interval on the database transport, ~1 ms on shared memory
 * (see FOK_POLL_CHECK_USEC_APCU); the rest is client cadence and the network.
 * Relay INPUT events and hashes, not high-rate state (see docs/API.md).
 *
 * TODO: replace this concept. The blocking one-worker-per-long-poll model
 * is a dead end: each relayed player holds an FPM worker for the whole
 * duel, so relay_max_duels can never exceed a fraction of the shared-
 * hosting pool (a few dozen workers, not configurable from here).
 * Concurrency is bounded by the process model, not by CPU or DB, and the
 * poll interval adds latency no persistent link would. Move the relay to a
 * persistent async process (single event-loop / WebSocket hub holding many
 * connections at once) on a VPS or container: a duel then costs a socket
 * and some RAM instead of a blocked worker, concurrency scales into the
 * thousands, and forwarding becomes a push with no poll term.
 */
Util::cors();
$method = $_SERVER['REQUEST_METHOD'] ?? '';

if ($method === 'GET') {
    $id = $_GET['id'] ?? null;
    $peer = $_GET['peer'] ?? null;
    if (!Util::isValidId($id) || !Util::isValidId($peer) || $id === $peer) {
        Util::fail('invalid id/peer');
    }
    $wait = min((int)($_GET['wait'] ?? 0), FOK_POLL_WAIT_MAX);
    // The hold loop peeks and takes no lock while idle: a waiting poll must
    // not fight the duels that are actually sending (see RelayStore). On the
    // APCu transport a peek is two shared-memory reads, so it can poll tight
    // and deliver in about a millisecond; the database peek is a query and
    // keeps the wider interval.
    $checkUsec = RelayStore::usingApcu() ? FOK_POLL_CHECK_USEC_APCU : FOK_POLL_CHECK_USEC;
    $deadline = microtime(true) + $wait;
    // A held GET is the only channel a relayed peer is watching mid-game (it
    // is not polling the signal mailbox), so it is where the server tells it
    // the other side left. peerLeft is a DB read, so it is rate-limited to
    // about once a second rather than run on every tight APCu poll.
    $nextLeftCheck = 0.0;
    while (true) {
        $rows = RelayStore::hasAny($id, $peer) ? RelayStore::drain($id, $peer) : [];
        if ($rows !== []) {
            Load::tick('msg_out', count($rows));
            Util::jsonOut(['ok' => true, 'messages' => $rows]);
        }
        $t = microtime(true);
        if ($t >= $nextLeftCheck) {
            $nextLeftCheck = $t + 1.0;
            if (ConnTrack::peerLeft($id, $peer)) {
                // The peer tore the pairing down (bye/decline): say so at once
                // (v3.3), the relay's answer to a P2P DataChannel close. A
                // client that does not read "gone" ignores it and falls back
                // to its own liveness timeout as before.
                Util::jsonOut(['ok' => true, 'gone' => true]);
            }
        }
        if ($t >= $deadline || connection_aborted()) {
            http_response_code(204);
            exit;
        }
        usleep($checkUsec);
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

$now = time();
RelayStore::sweep($now);

if (RelayStore::pending($peer, $id) >= Settings::int('relay_pending_cap')) {
    Alerts::raise('spam', "Relay backlog full for $peer (sender $id)");
    Util::fail('relay backlog full', 429);
}

// The concurrent-duel cap is asked ONCE, when a duel starts relaying, not on
// every packet: a running pair carries a cheap APCu admission marker, so a set
// marker means "already holds a slot, forward it". Only its absence - a new
// pair, a relay-window of silence, or an evicted marker - pays for the real
// check (a conn read, plus a COUNT for a genuinely new pair).
if (!RelayStore::admitted($id, $peer)) {
    // No marker: consult the authoritative slot record FIRST, so an APCu
    // eviction can never cut off a live duel - only a pair that holds no slot
    // THERE either is genuinely new and subject to the cap.
    if (!ConnTrack::isRelaying($id, $peer)
        && ConnTrack::relayPairs() >= Settings::int('relay_max_duels')) {
        $msg = 'Relay duel cap reached: new relayed duel rejected';
        if (Alerts::raise('relay', $msg)) {
            error_log('FOK relay: ' . $msg);
        }
        Util::fail('relay busy', 503);
    }
}
// Set or refresh the marker so the pair stays admitted for as long as it keeps
// relaying (a no-op on the database transport, which gates on ConnTrack above).
RelayStore::markAdmitted($id, $peer);

if (!RelayStore::push($id, $peer, $payload)) {
    // Shared memory was momentarily full: the message was REFUSED, not
    // delivered (see RelayStore::push). 429 (back off and RESEND), not the
    // 503 "relay busy" the admission cap returns - that one tells the client
    // to give up the match, and a transient full cache must never end a live
    // duel. A dropped input is exactly what desyncs a duel into the
    // intermittent burst, so the sender must resend, never treat it as sent.
    Util::fail('relay store full, resend', 429);
}
// Ground truth for "this pair runs through the hub": no declared no-P2P bit
// is needed to end up here. On the APCu transport this database write is a
// mere liveness marker and is throttled off the per-message hot path (see
// RelayStore::shouldTrackRelay).
if (RelayStore::shouldTrackRelay($id, $peer, $now)) {
    ConnTrack::relaying($id, $peer);
}
Util::bump('relay');
// Deferred, like bump: count this message and check the sender's rate
// after the response is flushed, never in its latency.
Util::defer(static fn() => RelayRate::record($id));

$out = ['ok' => true];
if (!empty($body['pull'])) {
    // Piggyback the sender's OWN inbound (from the peer) onto this response,
    // so delivery does not hang on the held GET alone when the FPM pool is
    // saturated (v3.2, see docs/API.md). Only when the client asked: the
    // messages are DRAINED here, so a client that will not read them back
    // must not set "pull". Exactly-once holds across both drain sites, so a
    // message goes to whichever of POST-pull and GET arrives first.
    $rows = RelayStore::hasAny($id, $peer) ? RelayStore::drain($id, $peer) : [];
    if ($rows !== []) {
        Load::tick('msg_out', count($rows));
        $out['messages'] = $rows;
    }
}
Util::jsonOut($out);
