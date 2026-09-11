# FOK-server - notes for AI sessions

Repo: FOK-server (our own code; PRODUCTION - push to main == deploy).
Work ONLY on this repo; never touch another repo unless explicitly
instructed otherwise.

Read README.md for what this is; read docs/API.md before touching any
endpoint - it is the contract the FOK-snake client is built against.
The measurements and decisions behind the rules below are in
.claude/rules/project_fok_server.md.

## Hard rules

- ASCII only in every file (CI enforces).
- Never put credentials anywhere in the repo, not even in commit
  messages or the admin hash itself (CI greps for leak patterns).
  Deploy credentials live in ~/.fok-server-deploy.json on the developer
  machine only.
- Every PHP file starts with declare(strict_types=1) (CI enforces).
- LF line endings; PowerShell scripts are the only CRLF exception.
- Runtime data (SQLite db, admin.hash, backups) lives ABOVE the docroot
  in ../fok-server-data/, never under public/.

## Architecture invariants

- Shared hosting: Apache + PHP-FPM only. No daemons, no WebSockets, no
  cron. Real-time is client-polled HTTP or peer-to-peer WebRTC; the
  server relays SDP/ICE signaling only (no TURN). The ONE exception is
  the relay fallback (api/relay.php): DEPRECATED, still live, do not
  extend it (docs/DEPRECATED-relay.md has the delete manifest).
- No persistent state: the database IS the state, and "background" work
  piggybacks on the next request through Util::defer, which runs after
  the response is flushed. ONLY defer what the client never observes
  (monitoring, counters, sweeps) - the client's next request may
  overtake the deferred work. Deferring buys latency, never capacity.
  Cost per request must stay flat in the number of players.
- The clock source api/t.txt is a STATIC file stamped by mod_headers %t
  in public/.htaccess - never PHP, so it never queues for a worker. It
  protects the stamp, not the round trip: a client anchors its clock
  when the wire is quiet (API 4.4). It must stay no-store and its header
  in Access-Control-Expose-Headers. The same block stamps X-Request-Start
  into a REQUEST header, which PHP subtracts from REQUEST_TIME_FLOAT for
  the queue wait (Load::queueUs); it must stay "set" (overwrites, so a
  client cannot forge it) and its absence must stay ordinary, since
  php -S ignores .htaccess.
- The admin dashboard is EXCLUDED from the queue measurement
  (Util::noteQueue via isAdminScript) and must stay excluded: it polls
  only while somebody watches, so counting it makes the observer the
  measurement. It batches a tick into one request, and admin/api.php
  asks for the database WHERE IT IS USED, never at the top (a tick
  carrying only presence and duels opens no connection). Load::markStart
  stays at the top.
- apcu_inc does NOT refresh a key's TTL, apcu_store resets it on every
  write, so a counter pair written the two ways expires UNEVENLY.
  Ordinary expiry is a MISSING key, an eviction is a key present and
  wrong; alert only on the second (Signals::any).
- Server-issued starts are keyed on (pair, epoch), never the pair alone.
  The epoch resets where a pairing BEGINS (invite/invite-relay/offer in
  signal.php), NOT on 'bye' - a bye goes peer-to-peer once the
  DataChannel is open and the server never sees it. Dropping the row is
  always safe, missing one breaks a rematch.
- public/ mirrors the webroot 1:1; deploy is a dumb FTPS copy
  (tools/deploy.sh in CI, tools/deploy.ps1 by hand). No build step, no
  dependencies. Every asset sits FLAT in assets/ - the upload plan is
  one level deep.
- SQLite via PDO in WAL mode, schema auto-created in src/Db.php;
  FOK_DATA_DIR overrides the data location (tests rely on it). Schema
  changes append an "if ($v < N)" step to Db::migrate that is safe on
  live data; never edit an existing step.
- Player IDs are 8 lowercase hex chars, validated with Util::isValidId.
  Public identities, not secrets.
- Score entries keep field parity with the FOK-snake local top-10 entry:
  name (max 15), score, level, diff, color, shopItems, date (DD.MM.YY).
  Submissions store seed + inputs verbatim; validated stays 0 until
  replay validation exists.
- Item ownership (src/Items.php) is answered from the items row and ONLY
  from it: one row per instance, moved by compare-and-swap on its seq.
  The ledger is audit-only and truncated. MINTING stays client-trusted:
  the registry makes items conserved and auditable, not unforgeable.
  Per-match attestation secrets are minted inside the start transaction;
  each peer is told only its own, never logged nor returned by the admin
  API. Freezing is terminal until an operator clears it, and only the
  two provable-tampering paths reach it.
- The admin dashboard is modular: one object per card in MODULES
  (public/assets/admin.js). Extend by appending a module, never by
  special-casing the framework.
- New tunables go into Settings::DEFS with a label and read through
  Settings::int, never a bare constant.
- New monitored conditions call Alerts::raise(type, message),
  de-duplicated per type within alert_cooldown. External delivery is the
  marked TODO in src/Alerts.php.

## When changing the API

Update all four together: the endpoint, docs/API.md, README.md's sketch,
and the tests (test/unit.php for logic, test/smoke.sh for HTTP - a
runner sourcing test/smoke/*.sh; locally in order against one php -S,
against staging as THREE PARALLEL GROUPS that share nothing, then
09_sweep and 06_admin in sequence). A NEW part goes in a group whose ids
and settings it does not touch; what the tail reads from it goes through
the env hand-off in smoke.sh. Shared helpers live in lib.sh.

## Workflow

- Deploying == pushing to main: GitHub Actions runs checks, deploys
  staging, smokes it, deploys live and verifies the reported version. No
  manual deploys except emergencies, and then staging first
  (tools/deploy.ps1 -Staging + remote smoke before tools/deploy.ps1).
- Bump FOK_SERVER_VERSION with every release commit. ANY change under
  public/assets/ needs a bump: asset URLs carry ?v=<version> and are
  cached immutably.
- bash test/checks.sh runs everything CI runs (needs php CLI; the
  pre-commit hook in .githooks/ does it and skips without php).
- .htaccess behavior exists only on the real Apache; staging verifies it.
- One-time server maintenance: upload a temporary PHP script via FTPS,
  invoke it once over HTTPS, delete it immediately.
