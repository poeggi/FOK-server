<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Presence.php';
require_once __DIR__ . '/../src/Settings.php';
require_once __DIR__ . '/../src/Starts.php';

/**
 * Server-issued start of play.
 * POST {"id": "8-hex", "peer": "8-hex", "epoch": <n>, "reason": "level",
 *       "pts": <ms>}
 *   -> {"ok":true, "start_pts": <ms>, "epoch": <n>, "now": <ms>}
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

Util::jsonOut([
    'ok' => true,
    'start_pts' => $startPts,
    'epoch' => $epoch,
    'now' => Util::nowMs(),
]);
