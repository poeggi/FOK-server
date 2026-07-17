<?php
declare(strict_types=1);

// Implementation version: bumps with every release.
const FOK_SERVER_VERSION = '0.16.8';
// Contract version: bumps ONLY on breaking API changes (removed fields,
// changed semantics). Additive changes do not bump it. Clients pin this.
// v2: friendship-gated status and invites, ms hello.now, friend
// notifications, relay fallback.
// v3: start.php requires epoch + reason + pts. A start is now issued for
// EVERY halt of the run (see Starts::REASONS), peers name the one they
// mean instead of racing for it, and an unsynced client is turned away
// rather than let into a desynced game. The staleness half of that gate
// applies only where play BEGINS (first/rematch, see Starts::
// SYNC_GATED_REASONS); the in-run halts let the client resync as it goes.
const FOK_API_VERSION = 3;

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

// A player counts as online while its last heartbeat is within this window.
const FOK_ONLINE_WINDOW = 60;
// Presence counters are cached this long: every hello returns them, so
// they must never be counted per request (see Presence::counts).
const FOK_COUNTS_TTL = 5;
// A duel counts as running while either peer refreshed it within this window.
const FOK_DUEL_WINDOW = 60;
// A tracked connection state (see ConnTrack) goes stale after this long
// without a signaling or duel event: the client reads as idle again.
const FOK_CONN_TTL = 60;
// How long a pair holds its relay slot after its last message through the
// hub. A relaying duel refreshes this many times a second, so the window
// only has to outlast a pause (level transition, backgrounded tab) - keep
// it well above the ~30 s hello cadence or a live duel loses its slot.
const FOK_RELAY_WINDOW = 90;
// Undelivered signaling messages expire after this many seconds. A
// connection attempt that dies this way is reported back to its sender
// (see Signals::expire), so an invite never just evaporates.
const FOK_SIGNAL_TTL = 30;
const FOK_SIGNAL_MAX_PAYLOAD = 16384;
// Replay material of a score submission (seed + tick-stamped inputs).
const FOK_MAX_INPUTS = 262144;
// Hard ceiling on a client request body, derived from the biggest
// legitimate one: a score submission with its replay material, plus the
// other fields. In-game messages are one MTU (1280 B); only the
// end-of-game replay upload is anywhere near this.
const FOK_MAX_BODY = FOK_MAX_INPUTS + 16384;
// Chat messages are hard-capped much lower than SDP payloads.
const FOK_CHAT_MAX_LEN = 120;
// Long-poll mailbox check interval. The hold duration cap is the
// poll_wait_max setting; it must stay small enough that concurrent
// handshakes cannot exhaust the shared-hosting FPM worker pool.
const FOK_POLL_CHECK_USEC = 150000;

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
const FOK_MAX_FRIENDS = 64;

// Game clients are served from these origins (CORS allowlist).
const FOK_ALLOWED_ORIGINS = [
    'https://poeggi.github.io',
    'http://localhost:8000',
    'http://127.0.0.1:8000',
];

const FOK_ADMIN_MAX_FAILS = 5;
const FOK_ADMIN_LOCK_SECONDS = 300;
