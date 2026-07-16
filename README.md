# FOK-server

Central game server for FOK Snake (and future games). Runs as plain PHP on
shared hosting (Apache + PHP-FPM, SQLite), deployed to fok-server.poggensee.it.

## What it does

- Presence: clients send periodic heartbeats with their 32-bit player ID
  (8 lowercase hex chars, the public identity from FOK-snake), display
  name and measured latency. The server tracks id, IP, name, latency,
  first/last seen.
- Friendships: established THROUGH the server (request/accept handshake,
  removable); only an accepted, server-recorded friendship entitles a
  client to query a friend's status (online, latency, name) or send a
  game invite. Quick match stays open to strangers.
- Global highscores: top 100 list. Submissions carry the deterministic
  replay material (seed + tick-stamped inputs) verbatim, so scores can later
  be sanity-checked by re-simulation to prevent spoofing (validated flag).
- 1:1 matchmaking hub: players invite each other by ID (with friend
  online-status checks) or quick-match with anyone waiting; the server
  relays matchmaking and WebRTC signaling messages (SDP/ICE) through a
  store-and-forward mailbox. The actual game traffic runs peer-to-peer over
  a WebRTC DataChannel for low latency; the server never touches it.
- Admin interface at /admin/: a one-screen dashboard (statistics: online,
  playing 1:1, registered users with id and ip, per-hour load; alert feed;
  top-100 management) plus a settings view behind the gear button with the
  runtime configuration (incl. JSON export/import) and database backup
  (SQLite online backup, download) and restore (upload).
- Monitoring and alerting: inline checks (no daemons on shared hosting)
  raise de-duplicated alerts for excessive traffic, system overload, too
  many connections, client spam (flooding, oversized or repeatedly invalid
  messages) and admin login failures/lockouts. Alerts are local-only in
  the admin UI for now; delivery backends (Telegram/SMS/Email) are a
  marked TODO in src/Alerts.php.
- Runtime configuration: thresholds and abuse caps (admin lockout, mailbox
  cap, score throttle, chat length, alert limits) are editable in the
  admin config card and take effect immediately; code constants are only
  the defaults.

## Layout

    public/           mirrors the webroot 1:1
      index.php       landing page with the global top 100
      api/            JSON endpoints for game clients (CORS-allowlisted)
        version.php   server + API contract version, environment
        time.php      millisecond clock sync (shared PTS base for games)
        hello.php     heartbeat: presence, counters, signals, friends online
        poll.php      fast signal poll, 204 when idle (matchmaking window)
        friend.php    friendship handshake: request/accept/remove/list
        match.php     quick-match queue (pair with anyone waiting)
        start.php     server-issued absolute level-start PTS per pair
        scores.php    GET top 100 / POST submit score
        signal.php    POST matchmaking/WebRTC signaling message
      admin/          session-protected admin UI + JSON API
      assets/         CSS/JS; admin dashboard is modular (see MODULES in
                      assets/admin.js - add a module object to extend it)
      src/            PHP classes, blocked from the web via .htaccess
    docs/API.md       the client-facing API contract (read this first when
                      writing a game client)
    test/checks.sh    all quality checks: PHP lint, ASCII-only guard,
                      secret-leak guard, strict_types guard, unit tests
                      (test/unit.php), HTTP smoke test (test/smoke.sh)
    tools/deploy.sh   FTPS upload of public/ (used by the CI/CD pipeline)
    tools/deploy.ps1  manual FTPS upload (emergency fallback)

CI (.github/workflows/ci.yml) runs test/checks.sh on every push and PR.
Run the same checks before every commit via the hook (once per clone):

    git config core.hooksPath .githooks

Runtime data (SQLite db, admin credential hash, backups) lives in
../fok-server-data/ ABOVE the docroot, created by the server at first run.
It is never web-accessible and never part of this repo.

## Local development and tests

Requires the php CLI (with sqlite3). Everything honors the FOK_DATA_DIR
env var, so nothing touches real data:

    bash test/checks.sh                 all checks, same as CI
    php test/unit.php                   unit tests only
    bash test/smoke.sh                  boots php -S and tests over HTTP

    FOK_DATA_DIR=/tmp/fok php -S localhost:8000 -t public   run it locally

Note: php -S does not read .htaccess, so the src/ web block only exists
on Apache; keep secrets out of src/ regardless.

## Staging and deploy

The server is PRODUCTION: it must stay up, and update downtime is
minimized by never deploying untested code to the live webroot. The
staging environment is a full copy of the app in the staging/
subdirectory of the live webroot (https://.../staging/) with its OWN
database and admin hash (../fok-server-data-staging/); the code detects
this via its directory name (FOK_ENV) and marks the admin UI (STAGING).

Deployment is CI/CD: every push to main runs this pipeline in GitHub
Actions (.github/workflows/ci.yml), no exceptions and no manual steps:

    1. checks             full local test suite (test/checks.sh)
    2. deploy to staging  tools/deploy.sh staging (FTPS, secrets in Actions)
    3. smoke staging      test/smoke.sh against the staging URL
    4. deploy to live     only if staging passed
    5. verify live        version.php must report the pushed version + live

So "deploy" == "git push to main" (which the pre-commit hook already
gates locally). The remote smoke run uses random player IDs and removes
its test data afterwards. The live upload itself takes a few seconds
(plain FTPS file copy); with staging already verified, that window is
the only exposure. Deploys are serialized (concurrency group), so two
pushes never interleave uploads.

Manual fallback (emergencies only, same staging-first rule):
tools/deploy.ps1 -Staging, then tools/deploy.ps1; -Only api uploads one
subtree. Credentials: ~/.fok-server-deploy.json locally, FTP_*/FOK_ADMIN_*
secrets in GitHub Actions.

First-run bootstrap: the SQLite database creates and seeds itself on
the first request (a fresh top 100 starts with the classic
SNAKE PLISSKEN entry at 82 points). The admin credential hash does not:
write it once by uploading a short-lived PHP script that runs
password_hash("user:pass", PASSWORD_DEFAULT) into the data dir from
POSTed values, invoke it once over HTTPS, then delete it. Credentials
must never exist in the repo, in commits, or in plain text on the
server. Staging needs its own one-time hash bootstrap.

## Security notes

- Admin credentials exist only as a password_hash() of "user:pass" in
  fok-server-data/admin.hash on the server. Neither the credentials nor the
  hash are in this repo. Excessive failed logins block the source IP
  (default: 5 fails -> 300 s, configurable in the admin config card) and
  raise an alert.
- Deploy credentials live in ~/.fok-server-deploy.json locally, outside the
  repo.
- Player IDs are public identities (as designed in FOK-snake); a secret
  session token for submission authenticity is future work, as is the
  replay-based score validation.

## API sketch

    GET  /api/version.php
      -> {"ok":true,"server":"<x.y.z>","api":2,"env":"live"}
    GET  /api/time.php
      -> {"ok":true,"t":<server ms>}   clock sync for the shared PTS base
    POST /api/hello.php  {"id":"cafe0001", "name":"KAI"?, "duel_with":"deadbeef"?,
                          "latency":ms?, "friends":[...]?}
      -> {"ok":true,"now":ms,"online":n,"playing":n,"registered":n,
          "signals":[{"from":"...","type":"invite","payload":"..."},...],
          "friends_online":{...}?, "friends_latency":{...}?,
          "friends_name":{...}?}   (friends_* only real for accepted friends)
    POST /api/friend.php {"id","action":"request|accept|remove|list","peer"?}
      -> {"ok":true,"state":...} | {"ok":true,"friends":[...]}
    GET  /api/poll.php?id=cafe0001&wait=8
      -> 204 (nothing pending) | {"ok":true,"signals":[...]}
         (wait=N long-polls: answers ~150 ms after a signal arrives)
    POST /api/match.php  {"id":"cafe0001","action":"seek|cancel"}
      -> {"ok":true,"waiting":true} | {"ok":true,"matched":"...","role":"..."}
    POST /api/start.php  {"id":"cafe0001","peer":"deadbeef"}
      -> {"ok":true,"start_pts":ms,"now":ms}   identical for both peers
    GET  /api/scores.php?limit=10
      -> {"ok":true,"scores":[{"rank":1,"name":"...","score":...,...}]}
    POST /api/scores.php {"id","name","score","level","diff","color"?,"shopItems"?,"seed"?,"inputs"?}
      -> {"ok":true,"rank":n,"top":bool}
    POST /api/signal.php {"id","to","type":"invite|accept|decline|offer|answer|ice|bye|chat","payload"}
      -> {"ok":true}   (chat payloads capped at 120 bytes; matchmaking
                        payloads carry the player profile - see docs/API.md)

Signals are delivered through the recipient's next hello or poll.php poll.
Clients poll slowly (~30 s) when idle and fast (~1-2 s) while
matchmaking/signaling.
