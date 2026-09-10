<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Presence.php';
require_once __DIR__ . '/../src/Settings.php';
require_once __DIR__ . '/../src/Starts.php';
require_once __DIR__ . '/../src/ConnTrack.php';

/**
 * Server-issued start of play.
 * POST {"id": "8-hex", "peer": "8-hex", "epoch": <n>, "reason": "first",
 *       "pts": <ms>, "duel_private": <bool>}
 *   -> {"ok":true, "start_pts": <ms>, "epoch": <n>, "now": <ms>,
 *       "q_ms": <ms>,                          (since API 4.4)
 *       "mid": "32-hex", "secret": "32-hex"}   (mid/secret since API 4.0)
 *
 * BOTH peers call this where play BEGINS - the first start and a rematch,
 * which are the only two reasons there are - and each receives the
 * identical absolute start PTS. They NAME the start with a shared epoch
 * and reason, so the answer does not depend on when either one asks.
 *
 * The halts WITHIN a run - next level, respawn, resume from pause - are
 * settled peer-to-peer over the DataChannel and never reach the server.
 *
 * This is also where a duel is ANNOUNCED. Both peers call it at the
 * moment play begins, which is what makes the announcement early and
 * independent of either client's beat; the heartbeat's duel_with only
 * refreshes it afterwards (see Presence::touchDuel, and docs/API.md
 * under Announcing a duel).
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
Util::noteCaller($id);

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

// The same flag the heartbeat carries, read the same way: absent is
// public, because the client states it on every request that holds the
// duel up rather than latching it once (see docs/API.md).
$duelPrivate = $body['duel_private'] ?? false;
if (!is_bool($duelPrivate)) {
    Util::fail('invalid duel_private');
}

// The sync gate. checkPts rejects a PTS further ahead of the server than
// pts_ahead_max_ms (logged as bogus; half of that is a warning line and
// nothing more), and pts is required: a start is a moment on the shared
// clock, so a client that cannot place itself on it, or places itself in
// the future, gets no start.
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
// It applies to every start, because every start begins play and a pair
// must enter its run aligned.
$age = Util::nowMs() - $pts;
$maxAge = Settings::int('start_sync_max_age_ms');
if ($age > $maxAge) {
    Alerts::error('stale-pts', "Stale sync proof from $id (" . Util::clientIp()
        . ") - $age ms old, max $maxAge");
    Util::fail('stale pts: resync before requesting a start');
}

Presence::touch($id, Util::clientIp());
Util::bump('start');

$startPts = Starts::request($id, $peer, $epoch, $reason);

// AFTER the start is issued, never before: a request that fails validation
// is not a duel, and announcing one would offer friends a feed of a match
// nobody is playing. Both peers announce their own side, so a friend of
// either sees it from here rather than up to a beat later.
Presence::touchDuel($id, $peer, $duelPrivate);
ConnTrack::playing($id, $peer);

$now = Util::nowMs();

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
    // queued and is therefore a poor clock sample. It is ignorable.
    'q_ms' => $q === null ? 0 : (int)round($q / 1000),
    'mid' => $match['mid'],
    'secret' => $match['secret'],
]);
