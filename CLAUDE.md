# FOK-server - notes for AI sessions

Read README.md for what this is; read docs/API.md before touching any
endpoint - it is the contract the FOK-snake client is built against.

## Hard rules

- ASCII only in every file. No smart quotes, em dashes, arrows or
  section signs (CI enforces this).
- Never put credentials anywhere in the repo: no FTP/deploy passwords,
  no admin credentials, no tokens, not even in commit messages or the
  admin hash itself (CI greps for leak patterns). Deploy credentials
  live in ~/.fok-server-deploy.json on the developer machine only.
- Every PHP file starts with declare(strict_types=1) (CI enforces).
- LF line endings (.gitattributes enforces); PowerShell scripts are the
  only CRLF exception.
- Runtime data (SQLite db, admin.hash, backups) lives ABOVE the docroot
  in ../fok-server-data/, never under public/.

## Architecture invariants

- Shared hosting: Apache + PHP-FPM only. No daemons, no WebSockets, no
  cron. Anything real-time is client-polled HTTP or peer-to-peer WebRTC.
  The server relays SDP/ICE signaling only and never carries an
  RTCPeerConnection (there is no TURN). Game traffic normally goes P2P
  and never touches the server; the ONE exception is the relay fallback
  (api/relay.php), where WebRTC is abandoned and opaque in-duel messages
  go over HTTP instead - capped, because it costs workers.
- There is no persistent state: PHP dies at the end of every request, so
  the database IS the state and "background" work piggybacks on the next
  request (TTL sweeps, expiry, monitoring). Cost per request must stay
  flat in the number of players (see README "Capacity and limits").
- That piggybacked work goes through Util::defer, which runs it after the
  response is flushed (fastcgi_finish_request). ONLY defer what the
  client never observes - monitoring, counters, sweeps. A client can send
  its next request the moment the response lands and that request may
  overtake the deferred work, so anything readable back must happen
  before the answer. Deferring does NOT free the worker: it buys latency
  and determinism, never capacity.
- The clock source (api/t.txt) is a STATIC file whose timestamp comes
  from mod_headers %t in public/.htaccess - deliberately not PHP, so it
  never queues for an FPM worker (that wait is invisible to PHP and would
  land in the client's clock offset). It must stay no-store, and its
  header must stay in Access-Control-Expose-Headers or browsers cannot
  read it. php -S ignores .htaccess, so only staging/live can verify it.
- Server-issued starts are keyed on (pair, epoch), never on the pair
  alone: both peers name the epoch so the answer cannot depend on when
  either asks. A pair-only key silently handed a late peer a different
  start. The epoch is scoped to one connection and resets on 'bye'.
- public/ mirrors the webroot 1:1; deploy is a dumb FTPS file copy
  (tools/deploy.sh in CI, tools/deploy.ps1 by hand). No build step, no
  composer, no dependencies.
- SQLite via PDO in WAL mode, schema auto-created in src/Db.php. The
  FOK_DATA_DIR env var overrides the data location (tests rely on it).
- Schema changes go through the migration ladder in Db::migrate (PRAGMA
  user_version): append a new "if ($v < N)" step with ALTER/CREATE
  statements that are safe on live data; never edit an existing step.
- Player IDs are 8 lowercase hex chars (32-bit), validated everywhere
  with Util::isValidId. They are public identities, not secrets.
- Score entries must keep field parity with the FOK-snake local top-10
  entry: name (max 15 = client MAX_NAME), score, level, diff, color,
  shopItems, date (DD.MM.YY). Submissions store seed + inputs verbatim
  for future replay validation; validated stays 0 until that exists.
- The admin dashboard is modular: one self-contained object per card in
  MODULES (public/assets/admin.js). Extend by appending a module, never
  by special-casing the framework code.
- New tunable values go into Settings::DEFS (src/Settings.php) with a
  label; they then appear in the admin config card automatically. Read
  them with Settings::int, never a bare constant.
- New monitored conditions call Alerts::raise(type, message); raising is
  de-duplicated per type within the alert_cooldown window. External
  delivery backends (Telegram/SMS/Email) are the marked TODO in
  src/Alerts.php - implement them as a dispatch step inside raise().

## When changing the API

Update all four together or CI/state drifts: the endpoint, docs/API.md,
README.md's sketch, and the tests (test/unit.php for logic,
test/smoke.sh for the HTTP behavior).

## Workflow

- The server is PRODUCTION and must stay up. Deploying == pushing to
  main: GitHub Actions runs checks, deploys staging, smokes it, then
  deploys live and verifies the reported version (see README "Staging
  and deploy"). Do NOT run manual deploys except in emergencies, and
  then always staging first (tools/deploy.ps1 -Staging + remote smoke
  before tools/deploy.ps1).
- Bump FOK_SERVER_VERSION with every release commit - the live-verify
  step compares it against what the deployed server reports. A change
  under public/assets/ ALWAYS needs a bump even if nothing else moved:
  asset URLs carry ?v=<version> and are cached immutably, so shipping a
  second file under a version that was already deployed leaves browsers
  on the old one for a year.
- bash test/checks.sh runs everything CI runs (needs php CLI; the
  pre-commit hook in .githooks/ does this automatically and skips
  gracefully when php is missing).
- The smoke test only covers php -S; .htaccess behavior (src/ blocking,
  HTTPS redirect) exists only on the real Apache, so staging verifies
  those too.
- One-time server maintenance (writing admin.hash, resetting the db)
  is done by uploading a temporary PHP script via FTPS, invoking it
  once over HTTPS, and deleting it immediately.
