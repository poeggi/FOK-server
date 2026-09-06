<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Presence.php';
require_once __DIR__ . '/../src/Settings.php';
require_once __DIR__ . '/../src/Starts.php';
require_once __DIR__ . '/../src/Skew.php';

/**
 * Server-issued start of play.
 * POST {"id": "8-hex", "peer": "8-hex", "epoch": <n>, "reason": "level",
 *       "pts": <ms>}
 *   -> {"ok":true, "start_pts": <ms>, "epoch": <n>, "now": <ms>,
 *       "q_ms": <ms>, "resync": <bool>,        (both since API 4.4)
 *       "mid": "32-hex", "secret": "32-hex"}   (mid/secret since API 4.0)
 *
 * BOTH peers call this every time the run halts or restarts - first
 * start, next level, respawn, resume from pause - and each receives the
 * identical absolute start PTS. They NAME the start with a shared epoch
 * (see Starts), so the answer does not depend on when either one asks.
 * A peer that has fallen behind the pair's epoch gets 409 rather than a
 * start it would run from the wrong origin.
 *
 * pts is the caller's own clock reading and is REQUIRED: a start is a
 * moment on the shared clock, so a client that cannot place it there
 * gets no start. See the sync gate below for what this does and does not
 * prove.
 */
Util::cors();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Util::fail('POST only', 405);
}

$body = Util::jsonBody();
$id = $body['id'] ?? null;
$peer = $body['peer'] ?? null;
if (!Util::isValidId($id) || !Util::isValidId($peer) || $id === $peer) {
    Util::fail('invalid id/peer');
}

$epoch = $body['epoch'] ?? null;
// A run halts a few hundred times at most; the ceiling only keeps a
// garbage value from parking the pair at an epoch no peer can reach.
if (!is_int($epoch) || $epoch < 0 || $epoch > 1000000) {
    Util::fail('invalid epoch');
}

$reason = $body['reason'] ?? null;
if (!is_string($reason) || !in_array($reason, Starts::REASONS, true)) {
    Util::fail('invalid reason');
}

// The sync gate. checkPts rejects a PTS ahead of the server (zero
// tolerance, logged as bogus), and pts is required - for EVERY reason: a
// start is a moment on the shared clock, so a client that cannot place
// itself on it, or places itself in the future, gets no start.
$pts = Util::checkPts($body['pts'] ?? null, $id);
if ($pts === null) {
    Util::fail('pts required: sync before requesting a start');
}
// The staleness half is enforced only where play BEGINS (first/rematch),
// so the pair enters the run aligned. What arrives is pts + one-way delay
// + clock error, which the server cannot separate from a single direction
// (the reason NTP needs a round trip), so even here the gate is GROSS: it
// catches a client that never synced (a raw device clock is off by seconds
// to minutes) and passes any that did (min-RTT sampling bounds it to ms).
// The in-run halts (level/respawn/resume) skip it entirely - the pair is
// already synced from its first start, and the FPM queue inflates the age
// under exactly the load where a false rejection would break a live duel,
// so we let the client resync as it goes rather than turn it away.
if (in_array($reason, Starts::SYNC_GATED_REASONS, true)
    && Util::nowMs() - $pts > Settings::int('start_sync_max_age_ms')) {
    Util::fail('stale pts: resync before requesting a start');
}

Presence::touch($id, Util::clientIp());
Util::bump('start');

$startPts = Starts::request($id, $peer, $epoch, $reason);
if ($startPts === null) {
    Util::fail('stale epoch: the pair has already moved on', 409);
}

// The pair cross-check (4.4, see Skew). Both peers prove their clock against
// THIS start, so the difference between their two proofs bounds how far apart
// their two anchors are - the one clock error the server can see and neither
// client can. A hint, never a rejection: the answer asks for a re-anchor and
// the start is issued either way. The check runs after the start is issued
// for exactly that reason.
$now = Util::nowMs();
$resync = Skew::note($id, $peer, $epoch, $now - $pts) || Skew::wanted($id);

// Additive since API 4.0: the pair's match id and the CALLER'S OWN match
// secret (never the peer's). A begin (first/rematch) minted a fresh match; an
// in-run halt carries the open one forward. The client uses these to attest
// item transfers to api/items.php. A client on an older API simply ignores
// both fields. mid is '' only for the degenerate case of no open match.
$match = Starts::matchInfo($id, $peer);

$q = Load::queueUs();

Util::jsonOut([
    'ok' => true,
    'start_pts' => $startPts,
    'epoch' => $epoch,
    'now' => $now,
    // Additive since 4.4. q_ms is what THIS request waited for a worker
    // before any PHP ran: a client reads it to know its own round trip was
    // queued and is therefore a poor clock sample. resync says the pair's two
    // proofs disagreed - re-anchor before the next start. Both are ignorable.
    'q_ms' => $q === null ? 0 : (int)round($q / 1000),
    'resync' => $resync,
    'mid' => $match['mid'],
    'secret' => $match['secret'],
]);
