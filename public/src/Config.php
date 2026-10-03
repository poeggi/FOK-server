<?php
declare(strict_types=1);

// Implementation version: bumps with every release.
const FOK_SERVER_VERSION = '2.0.1';
// Contract version, MAJOR.MINOR (see docs/API.md Versioning). The MAJOR
// bumps only on breaking changes (removed fields, changed semantics):
// clients gate on it and disable online play when the server's major is
// newer than the one they were built against. The MINOR bumps on additive,
// backward-compatible changes (a new optional signal type or field); a
// client on the same major stays compatible and feature-detects optional
// fields. A string, so major/minor split on the dot.
const FOK_API_VERSION = '5.0';

// Never leak stack traces or paths to clients; errors go to the server log.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// The staging environment is a full copy of public/ in the staging/
// subdirectory of the live docroot; it runs against its own data dir.
define('FOK_DOCROOT', dirname(__DIR__));
define('FOK_ENV', basename(FOK_DOCROOT) === 'staging' ? 'staging' : 'live');

// Prefix for every shared-memory key this release writes. Staging and live
// are separate docroots against separate databases, but they can be served
// by ONE FPM pool - and then they share one APCu segment. Anything cached
// from the database (settings, capabilities) must therefore be namespaced by
// environment, or the staging smoke lowering a setting would lower it for
// live clients too. Keys that hold no database-derived state (the mailbox,
// the hold slots) predate this and are left on the bare prefix: two
// environments sharing them would only ever confuse their own test clients.
define('FOK_APCU_NS', FOK_ENV === 'staging' ? 'fok:stg:' : 'fok:');

// Data lives INSIDE the live docroot, in a directory of its own that a
// .htaccess (written by Db::get, the same line src/.htaccess carries)
// keeps Apache from serving: fok-server-data/ for live, and beside it
// fok-server-data-staging/ for staging, whose docroot is the staging/
// subdirectory. The deploy never touches either.
// FOK_DATA_DIR env var overrides the location (used by the test suite).
define('FOK_DATA_DIR', getenv('FOK_DATA_DIR') ?: (FOK_ENV === 'staging'
    ? dirname(FOK_DOCROOT) . '/fok-server-data-staging'
    : FOK_DOCROOT . '/fok-server-data'));
define('FOK_DB_FILE', FOK_DATA_DIR . '/fok.db');
define('FOK_ADMIN_HASH_FILE', FOK_DATA_DIR . '/admin.hash');
define('FOK_BACKUP_DIR', FOK_DATA_DIR . '/backups');
// The Cloudflare TURN key (see Turn): key_id and key_token, uploaded by
// hand (tools/put-turn.ps1), never deployed, never in the repo. Absent =
// no TURN offered.
define('FOK_TURN_FILE', FOK_DATA_DIR . '/turn.json');
// The admin session store. PHP's garbage collection sweeps whatever
// directory a script's save_path names, with THAT script's lifetime; in
// the host's shared default directory every other script on the account
// sweeps ours at the host's default idle window (24 minutes). A directory
// of our own is swept by nobody else (Auth::startSession).
define('FOK_SESSION_DIR', FOK_DATA_DIR . '/sessions');

// Errors and warnings are pinned to a file in the data dir, which is never
// web-served; and the host's default error-log destination is not reachable
// over our deploy (FTP) access, which stops at the docroot - so this pin is
// what lets the admin Logs tab read it.
define('FOK_ERROR_LOG', FOK_DATA_DIR . '/php-error.log');
ini_set('error_log', FOK_ERROR_LOG);
// The Logs tab reads at most this much of the log's tail (newest entry
// first), so an unrotated log is never loaded whole.
const FOK_LOG_TAIL_BYTES = 131072;

// A player counts as online while its last heartbeat is within this window:
// twice the 60 s beat the contract asks for (docs/API.md, Pacing), so one
// missed beat never reads as offline.
const FOK_ONLINE_WINDOW = 120;
// A beat that lands the odd second late still counts. Every window below
// that a heartbeat keeps alive is checked against the window PLUS this
// second (Util::since), never against the window alone, so nothing reads
// as gone before 121 s.
const FOK_BEAT_JITTER = 1;
// How long the QR-screen auto-accept flag a hello may set stays valid, so a
// scanned invite is accepted without a manual tap (see Presence). Every
// hello re-arms it, so it is the online window: one missed beat does not
// close the screen. A protocol/UX constant, not an operator knob.
const FOK_AUTO_ACCEPT_WINDOW = 120;
// Presence counters are cached this long: every hello returns them, so
// they must never be counted per request (see Presence::counts).
const FOK_COUNTS_TTL = 5;
// A duel counts as running while either peer refreshed it within this
// window; the refresh is duel_with on the heartbeat, so it is the online
// window. This is the INTEGRITY clock: an item claim's window is measured
// from the duel row it stamps (see Items::matchDeadline), so shortening it
// shortens what an honest player gets after a real match ends.
const FOK_DUEL_WINDOW = 120;
// How long the SPECTATE OFFER outlives the last duel_with, on the presence
// entry rather than the duel row (see Presence::touchDuel). A separate,
// shorter clock on purpose: a friend is offered a WATCH row off this, and a
// spectate link to a match that is over dies on "no feeder", so the answer
// must go stale sooner than the duel does. One and a half beats - the client
// stops polling inside a duel, so the 60 s hello is the only refresh, and
// this is the smallest window a timely beat never falls outside of. Being
// wrong here is cosmetic and the next beat repairs it; being wrong on the
// window above costs somebody an item.
const FOK_DUEL_SEEN_WINDOW = 90;
// A tracked connection state (see ConnTrack) goes stale after this long
// without a signaling or duel event: the client reads as idle again. The
// duel event is the heartbeat, so this is the online window too.
const FOK_CONN_TTL = 120;
// The admin dashboard keeps a client on its Duels / Connections cards this
// long AFTER its liveness lapses (a duel went quiet, a client dropped), so
// a just-ended entry does not blink out the instant it stops refreshing.
const FOK_DUEL_LINGER = 10;
// Undelivered signaling messages expire after this many seconds: the
// online window, so a signal to a client that still counts as online is
// late at worst, never lost (an idle client drains only on its heartbeat).
// A connection attempt that dies this way is reported back to its sender
// (see Signals::expire), so an invite never just evaporates.
const FOK_SIGNAL_TTL = 120;
const FOK_SIGNAL_MAX_PAYLOAD = 16384;
// Max candidates in one batched 'ices' signal (see docs/API.md). The point
// of the type is that ONE request carries a side's whole trickle, and a side
// gathers under ten; the cap only stops a client turning a signal into a
// list nobody sent.
const FOK_ICES_MAX = 24;
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
// Max seconds a long poll (poll.php) holds the request open.
// The contract's default hold is 5 s (docs/API.md, Pacing) and a client may
// ask for up to this. Coupled to the FPM worker model, and kept under the
// max_execution_time backstop (api/.user.ini) - a design constant, not a
// runtime knob.
const FOK_POLL_WAIT_MAX = 9;
// Long-poll mailbox check interval. The hold duration cap is FOK_POLL_WAIT_MAX;
// it must stay small enough that concurrent handshakes cannot exhaust the
// shared-hosting FPM worker pool.
const FOK_POLL_CHECK_USEC = 20000;

// How many FPM workers may be held by long polls at once (admin-configurable,
// see Settings and Holds). The default follows the HOST, like the tournament
// size below: measured, this deployment serves ~20 concurrent PHP requests,
// and a held poll occupies one of them for up to FOK_POLL_WAIT_MAX doing
// nothing. Twelve leaves the rest of the pool to the requests that are
// actually working, so the holds - whose own caps are each sized against the
// pool separately - can never add up to all of it. Raise it with the pool; 0
// stops budgeting holds altogether.
const FOK_HOLD_MAX_WORKERS = 12;

// The client's own beat - the 60 s heartbeat, the 5 s poll wait and the
// 100 ms gap between its own requests - is stated in docs/API.md (Pacing)
// and is not a setting here: nothing about it follows load, and a number
// that never changes belongs in the contract, not on the wire. The one
// per-request decision, whether a client may hold a long poll, is Pace.

// The step a pushed event's follow-up calls are staggered by, per seat
// (after_ms, see Tournament::flush). A round board wakes every participant
// in the same instant and they all call back together; a step apart, a
// full room of eight is served inside 700 ms. Milliseconds and not
// seconds, on purpose: the point is to de-stack the burst, not to make the
// last seat wait for its data.
const FOK_TOURNEY_AFTER_STEP_MS = 100;

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
// A quick-match seeker drops out of the queue after this many quiet
// seconds. It is both the liveness predicate the peer-select applies - a
// seeker that stopped polling is never handed out as a match - and the TTL
// of the seeker's own entry, so a queue nobody is polling empties itself
// (see Matchmaking).
const FOK_MATCH_WINDOW = 10;
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

// Where the game itself lives. The event QRs point at it: a phone's
// camera opens the game, which IS the event page, so there is no landing
// page on this server to write. The first allowed origin is the same
// host, and this is the path under it.
const FOK_GAME_URL = 'https://poeggi.github.io/FOK-snake/';

// Game clients are served from these origins (CORS allowlist). The
// packaged app serves the game from the device itself, so its web view
// names no web host: capacitor://localhost is Capacitor's iOS origin,
// https://localhost its Android one, http://localhost an older Android web
// view. WKWebView refuses a custom handler for http/https, which is why
// the app cannot simply present the github.io origin on iOS. Loopback
// never leaves the machine, so the http:// entries protect nothing less.
const FOK_ALLOWED_ORIGINS = [
    'https://poeggi.github.io',
    'capacitor://localhost',
    'https://localhost',
    'http://localhost',
    'http://localhost:8000',
    'http://127.0.0.1:8000',
];

// The oldest client versions served without an `upgrade` word on hello
// (docs/API.md, The client's version). Off: a store build is replaced when
// its player updates it, and these are the only way the server can say a
// newer one exists. Settings, so a broken build is retired from the
// config card without a deploy.
const FOK_CLIENT_ADVISED_VERSION = '0';
const FOK_CLIENT_MIN_VERSION = '0';
// Words masked out of what a player writes for others to read - the name
// on hello, the name on a score (see Words). Lowercase, matched anywhere
// in the text, case-insensitive; each hit becomes asterisks. Empty until
// the operator fills it; a fuller list is the operator's and lives here.
const FOK_WORD_FILTER = [];

const FOK_ADMIN_MAX_FAILS = 5;
const FOK_ADMIN_LOCK_SECONDS = 300;
// How long an admin login lasts: on the server since the last request, in
// the browser since the last page load (Auth::refreshCookie). A constant
// rather than a setting because it is read before any database is open.
const FOK_ADMIN_SESSION_SECS = 30 * 86400;
