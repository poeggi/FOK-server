<?php
declare(strict_types=1);

// Implementation version: bumps with every release.
const FOK_SERVER_VERSION = '1.3.4';
// Contract version, MAJOR.MINOR (see docs/API.md Versioning). The MAJOR
// bumps only on breaking changes (removed fields, changed semantics):
// clients gate on it and disable online play when the server's major is
// newer than the one they were built against. The MINOR bumps on additive,
// backward-compatible changes (a new optional signal type or field); a
// client on the same major stays compatible and may read the minor to
// detect optional features. A string, so major/minor split on the dot.
// v2: friendship-gated status and invites, ms hello.now, friend
// notifications, relay fallback.
// v3: start.php requires epoch + reason + pts. A start is now issued for
// EVERY halt of the run (see Starts::REASONS), peers name the one they
// mean instead of racing for it, and an unsynced client is turned away
// rather than let into a desynced game. The staleness half of that gate
// applies only where play BEGINS (first/rematch, see Starts::
// SYNC_GATED_REASONS); the in-run halts let the client resync as it goes.
// v3.1: additive 'peer-net' direct-connection hint (see docs/API.md). The
// major stays 3, so v3 clients interoperate and simply ignore it.
// v3.2: additive relay piggyback. POST /api/relay.php accepts an optional
// "pull": true; when set, the response also drains and returns the poster's
// own pending inbound as "messages":[...] (same shape as the GET), so
// delivery no longer depends on the held GET alone. A client MUST set it
// only if it reads those messages back - they are drained, so an unread
// response loses them. Relayed messages also gain an additive "age" (ms since
// the server received the message) in both replies, to tell a mailbox delay
// apart from an FPM queue delay. Major stays 3; v3.1 clients send no "pull",
// get the old {"ok":true}, and ignore "age".
// v3.3: additive relay leave signal. A held GET /api/relay.php returns
// {"ok":true,"gone":true} once the pairing is torn down (a bye/decline
// marked the conn ended), so a relayed peer learns the other side left at
// once instead of waiting out its own liveness timeout - the relay's answer
// to a P2P DataChannel close. Major stays 3; a client that does not read
// "gone" simply keeps timing out as before.
// v3.4: additive per-player stats. New GET/POST /api/stats.php lets a client
// save cumulative gameplay counters (games, levels cleared, furthest level,
// deaths, duels, playtime) and read them back to restore progress on another
// device (see docs/API.md). Self-reported, stored monotonically. The same 3.4
// line also adds two optional, additive score fields: "completed" (the run
// cleared the final level) and "platform" (the device category it was played
// on). Major stays 3; a client that uses none of these is unaffected, and one
// that adopts 3.4 picks them all up.
// v3.5: additive friend-request feedback. POST /api/friend.php action
// "request" now returns an "exists" boolean; an unknown peer id answers
// {"ok":true,"exists":false} without recording anything, so a mistyped id is
// caught rather than left as a dead pending row. The same action gains a
// per-id request throttle (at most one per second, then a cooldown after a
// burst), answering 429 with "retry_after". Major stays 3; a client that
// ignores "exists" and never trips the throttle behaves exactly as on 3.4.
// v4.0: the item registry. The server now owns item-instance OWNERSHIP -
// ownership is a row in the items table, moved only by a compare-and-swap
// transfer through the new POST /api/items.php (list/mint/seed/claim), logged
// to a hash-chained ledger, and start.php now also returns the pair's match id
// and the caller's own match secret so a client can attest transfers (see
// docs/API.md). The wire additions are backward-compatible - an older client
// ignores the new start.php fields and never calls items.php - but this is a
// MAJOR bump, not a minor one: for a client that DOES carry items, ownership
// is no longer a private boolean it may assert freely, so a client that goes
// online with items but does not speak the registry can find its claims
// unconfirmed or its instances frozen. Clients gate online item play on the
// major matching. Minting stays client-trusted (see the scope boundary in
// docs/API.md): 4.0 makes items conserved and auditable, not unforgeable.
// v4.1: additive tournament mode. New POST /api/tournament.php runs a lobby,
// a sparse first round, a seeded knockout and the standings for 2-8 players
// (see docs/API.md), announcing every transition as a server-generated
// 'tourney' signal; hello.php gains an optional "tourneys" request flag that
// returns the open lobbies hosted on the caller's own address, plus an
// additive "friends_playing" list. The client-sendable signal type 'watch'
// is added for spectator feed requests. The server orchestrates only: a
// tournament match is an ordinary P2P duel between the two players its roles
// sheet names, start.php is still the sole mid/secret authority, and no match
// or spectator traffic passes through the server. Major stays 4 - a 4.0
// client never calls tournament.php, never sets "tourneys" and simply does
// not offer tournaments.
// v4.2: additive self-reported networks. hello.php gains an optional "nets"
// list - the caller's OWN public addresses, as the client discovered them
// (a STUN reflexive candidate names one per family). The server observes
// only the family a given request arrived over and cannot ask a browser for
// the other, so on a dual-stack line the second network is unobservable
// here; this is how it becomes known, and it is what lets the tournament
// announce put a v6 host and a v4 joiner in the same room. It is a CLAIM:
// it never displaces a network the server saw for itself (see
// Presence::seenOn). Major stays 4 - a client that sends nothing is matched
// exactly as before, on the families we happen to see it on.
// v4.3: additive tournament rounds. A tournament now advances the LEVEL the
// game is played at as the field narrows - round 1 at level 1, each round
// after it one deeper, capped at the game's last level - so the size of the
// lobby decides how far the final gets. The level rides on the roles sheet
// and on every node as "lvl", beside the hearts a client already reads. The
// server also stops BETWEEN rounds now: it sends a 'round' event with the
// scoreboard, who is through and what the next stage is, and waits for the
// host to POST the new "continue" action (the projection carries the same
// board as "break"). A break clears itself after tournament_break_ttl_ms, so
// a host that closed its browser cannot wedge the tournament. Major stays 4:
// a 4.2 client ignores "lvl" and plays every round at level 1 as before, and
// its ordinary state() polling carries it through a break without ever
// pressing continue - it simply sees the next roles sheet a little later.
const FOK_API_VERSION = '4.3';

// Never leak stack traces or paths to clients; errors go to the server log.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// The staging environment is a full copy of public/ in the staging/
// subdirectory of the live docroot; it runs against its own data dir.
define('FOK_DOCROOT', dirname(__DIR__));
define('FOK_ENV', basename(FOK_DOCROOT) === 'staging' ? 'staging' : 'live');

// Data lives ABOVE the (live) docroot so it is never web-accessible.
// FOK_DATA_DIR env var overrides the location (used by the test suite).
define('FOK_DATA_DIR', getenv('FOK_DATA_DIR') ?: (FOK_ENV === 'staging'
    ? dirname(FOK_DOCROOT, 2) . '/fok-server-data-staging'
    : dirname(FOK_DOCROOT) . '/fok-server-data'));
define('FOK_DB_FILE', FOK_DATA_DIR . '/fok.db');
define('FOK_ADMIN_HASH_FILE', FOK_DATA_DIR . '/admin.hash');
define('FOK_BACKUP_DIR', FOK_DATA_DIR . '/backups');

// Errors and warnings are pinned to a file in the data dir. That dir sits
// ABOVE the docroot, so the log is never web-served; and the host's default
// error-log destination is not reachable over our deploy (FTP) access, which
// stops at the docroot - so this pin is what lets the admin Logs tab read it.
define('FOK_ERROR_LOG', FOK_DATA_DIR . '/php-error.log');
ini_set('error_log', FOK_ERROR_LOG);
// The Logs tab reads at most this much of the log's tail (newest entry
// first), so an unrotated log is never loaded whole.
const FOK_LOG_TAIL_BYTES = 131072;

// A player counts as online while its last heartbeat is within this window.
const FOK_ONLINE_WINDOW = 60;
// How long the QR-screen auto-accept flag a hello may set stays valid, so a
// scanned invite is accepted without a manual tap (see Presence). A protocol/
// UX constant, not an operator knob.
const FOK_AUTO_ACCEPT_WINDOW = 60;
// Presence counters are cached this long: every hello returns them, so
// they must never be counted per request (see Presence::counts).
const FOK_COUNTS_TTL = 5;
// A duel counts as running while either peer refreshed it within this window.
const FOK_DUEL_WINDOW = 60;
// A tracked connection state (see ConnTrack) goes stale after this long
// without a signaling or duel event: the client reads as idle again.
const FOK_CONN_TTL = 60;
// While a pair is negotiating, ICE trickles many same-state 'connecting'
// signals (a candidate each, plus offer/answer) in a burst. Each one would
// rewrite both conn rows only to refresh their `updated` stamp - the state
// and mode do not change - dragging the single SQLite writer onto the
// signaling hot path. So a redundant 'connecting' refresh (same peer, same
// state, no mode change) is skipped while the row is fresher than this,
// trading a write for a lock-free read. Kept well under FOK_CONN_TTL so an
// actively negotiating duel still refreshes long before it reads as idle.
// The relay path throttles its own conn writes the same way; see
// FOK_RELAY_TRACK_THROTTLE.
const FOK_CONN_TRACK_THROTTLE = 10;
// The admin dashboard keeps a client on its Duels / Connections cards this
// long AFTER its liveness lapses (a duel went quiet, a client dropped), so
// a just-ended entry does not blink out the instant it stops refreshing.
const FOK_DUEL_LINGER = 10;
// DEPRECATED: relay fallback (see docs/DEPRECATED-relay.md). FOK_RELAY_WINDOW,
// FOK_RELAY_TRACK_THROTTLE and FOK_POLL_CHECK_USEC_APCU below go with it.
// How long a pair holds its relay slot after its last message through the
// hub. A relaying duel refreshes this many times a second, so the window
// only has to outlast a pause (level transition, backgrounded tab) - keep
// it well above the ~30 s hello cadence or a live duel loses its slot.
const FOK_RELAY_WINDOW = 90;
// A relayed pair's conn row is only a liveness marker for the admin cards
// and the duel cap, both read over FOK_RELAY_WINDOW. Rewriting it per
// message would drag the single SQLite writer back onto the hot path APCu
// exists to clear, so it is refreshed at most this often per pair - well
// under the window it feeds.
const FOK_RELAY_TRACK_THROTTLE = 10;
// Undelivered signaling messages expire after this many seconds. A
// connection attempt that dies this way is reported back to its sender
// (see Signals::expire), so an invite never just evaporates.
const FOK_SIGNAL_TTL = 30;
const FOK_SIGNAL_MAX_PAYLOAD = 16384;
// A client's config backup: an opaque blob (its whole config; see
// api/backup.php and docs/API.md), capped per player.
const FOK_STATS_MAX = 65536;
// Debug datasets (see debug/submit.php): a log + snapshot bundle under a
// 4-digit PIN. Capped hard; the short retention keeps the small PIN space usable.
const FOK_DEBUG_MAX = 8388608;        // 8 MB per dataset
const FOK_DEBUG_TTL = 86400;          // kept 1 day, then purged
// Replay material of a score submission (seed + tick-stamped inputs).
const FOK_MAX_INPUTS = 262144;
// Hard ceiling on a client request body, derived from the biggest
// legitimate one: a score submission with its replay material, plus the
// other fields. In-game messages are one MTU (1280 B); only the
// end-of-game replay upload is anywhere near this.
const FOK_MAX_BODY = FOK_MAX_INPUTS + 16384;
// Chat messages are hard-capped much lower than SDP payloads.
const FOK_CHAT_MAX_LEN = 120;
// Max seconds a long poll (poll.php / relay.php) holds the request open.
// Coupled to the client (it sends wait=9) and the FPM worker model, and kept
// under the max_execution_time backstop (api/.user.ini) - a design constant,
// not a runtime knob.
const FOK_POLL_WAIT_MAX = 9;
// Long-poll mailbox check interval. The hold duration cap is FOK_POLL_WAIT_MAX;
// it must stay small enough that concurrent handshakes cannot exhaust the
// shared-hosting FPM worker pool.
const FOK_POLL_CHECK_USEC = 20000;
// The relay hold loop checks with two shared-memory reads (sub-microsecond),
// not a database query - the hub has no other transport - so it polls far
// tighter than the signal mailbox and delivers a message in about a
// millisecond instead of a full poll interval. This is as close to a push as
// a pollable store gets; a true wakeup with no poll term needs the persistent
// hub (see relay.php).
const FOK_POLL_CHECK_USEC_APCU = 2000;

// How many FPM workers may be held by long polls at once (admin-configurable,
// see Settings and Holds). The default follows the HOST, like the tournament
// size below: measured, this deployment serves ~20 concurrent PHP requests,
// and a held poll occupies one of them for up to FOK_POLL_WAIT_MAX doing
// nothing. Twelve leaves the rest of the pool to the requests that are
// actually working, so the holds - whose own caps are each sized against the
// pool separately - can never add up to all of it. Raise it with the pool; 0
// stops budgeting holds altogether.
const FOK_HOLD_MAX_WORKERS = 12;

// Default players in one tournament (admin-configurable, see Settings).
// The default follows the HOST, not taste: measured, this deployment serves
// ~20 concurrent PHP requests, and every participant holds one worker for its
// handshake each time the roles change. Eight keeps a match boundary under
// half the pool, so several tournaments can overlap. Raise it only against a
// pool that has the workers to absorb the boundary.
const FOK_TOURNAMENT_MAX_PLAYERS = 8;

// The deepest level a tournament round may be played at. Must not exceed
// MAX_LEVELS in FOK-snake js/assets.js: above the game's last level there is
// no harder board to reach, only one the client does not have.
const FOK_TOURNAMENT_MAX_LEVEL = 10;

// Abuse caps (HTTP 429): pending signals per recipient, score submissions
// per player within the rate window.
const FOK_MAILBOX_CAP = 64;
const FOK_SCORE_RATE_MAX = 10;
const FOK_SCORE_RATE_WINDOW = 300;
const FOK_TOP_SCORES = 100;
// Must match MAX_NAME in FOK-snake js/assets.js.
const FOK_MAX_NAME_LEN = 15;
// A quick-match seeker drops out of the queue after this many quiet seconds.
const FOK_MATCH_WINDOW = 10;
// A stale seeker (one that stopped polling) is made unselectable by the
// liveness predicate on the peer-select, so deleting its row is no longer
// needed to pair correctly - it is pure GC of a tiny, self-limiting table.
// So the prune runs on only a sampled 1-in-N fraction of seeks (a seek must
// take the write lock to record its own poll either way; this keeps the
// DELETE off most of them). Sampling is load-adaptive: a busier queue prunes
// proportionally more often, which is exactly when the row churn warrants it.
const FOK_MATCH_PRUNE_SAMPLE = 20;
const FOK_MAX_FRIENDS = 64;

// How many self-reported addresses one hello may carry (see hello.php
// "nets"). A device has one public address per family, so two is the honest
// answer; the cap is four so a client that also sends a second global v6 -
// a temporary privacy address out of the same /64 - is not rejected for it.
const FOK_MAX_NETS = 4;

// The device categories a score may optionally be tagged with (see
// api/scores.php): canonical lowercase tokens - pc (desktop or laptop),
// mobile (phone or tablet), tv (smart TV), console (game console). Stored
// verbatim for display; an absent or unrecognized value becomes NULL
// (unknown), so a score is never lost to a platform the server does not list.
const FOK_SCORE_PLATFORMS = ['pc', 'mobile', 'tv', 'console'];

// Per-player self-reported gameplay stats (see PStats, api/stats.php): one row
// of cumulative counters per id, so a client can save its progress and restore
// it on another device. They are CLIENT-ASSERTED (no server authority), so
// stored MONOTONICALLY - a submitted value never lowers the stored one, so a
// stale or replaying device cannot roll the totals back - and hard-capped. A
// count field is bounded by FOK_PSTATS_COUNT_MAX, the furthest-level marker by
// the score level range (99), total playtime by FOK_PSTATS_SECONDS_MAX; an
// over-cap value is clamped, not rejected, so a client never gets stuck.
const FOK_PSTATS_COUNT_MAX = 1000000000;
const FOK_PSTATS_SECONDS_MAX = 4000000000;
// A given id's stats row is rewritten at most this often, to keep a chatty or
// abusive client off the single SQLite writer - submit at end of a run or
// session, never per frame. A submission inside the window is accepted and
// reflected back but persists on the next one (the client holds the running
// totals and resends), so at most one write per id per window reaches disk.
const FOK_PSTATS_WRITE_THROTTLE = 10;

// Game clients are served from these origins (CORS allowlist).
const FOK_ALLOWED_ORIGINS = [
    'https://poeggi.github.io',
    'http://localhost:8000',
    'http://127.0.0.1:8000',
];

const FOK_ADMIN_MAX_FAILS = 5;
const FOK_ADMIN_LOCK_SECONDS = 300;
