# FOK-server

Central game server for FOK Snake (and future games). Runs as plain PHP on
shared hosting (Apache + PHP-FPM, SQLite), deployed to fok-server.poggensee.it.

## What it does

- Presence: clients send periodic heartbeats with their 32-bit player ID
  (8 lowercase hex chars, the public identity from FOK-snake). The server
  tracks id, IP, first/last seen.
- Global highscores: top 100 list. Submissions carry the deterministic
  replay material (seed + tick-stamped inputs) verbatim, so scores can later
  be sanity-checked by re-simulation to prevent spoofing (validated flag).
- 1:1 matchmaking hub: players invite each other by ID (with friend
  online-status checks) or quick-match with anyone waiting; the server
  relays matchmaking and WebRTC signaling messages (SDP/ICE) through a
  store-and-forward mailbox. The actual game traffic runs peer-to-peer over
  a WebRTC DataChannel for low latency; the server never touches it.
- Admin interface at /admin/: statistics (online, playing 1:1, registered
  users with id and ip, per-hour load), top-100 management, database backup
  (SQLite online backup, download) and restore (upload).

## Layout

    public/           mirrors the webroot 1:1
      index.php       landing page with the global top 100
      api/            JSON endpoints for game clients (CORS-allowlisted)
        hello.php     heartbeat: presence, counters, signals, friends online
        poll.php      fast signal poll, 204 when idle (matchmaking window)
        match.php     quick-match queue (pair with anyone waiting)
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
    tools/deploy.ps1  FTPS upload of public/

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

## Deploy and first-run bootstrap

    tools/deploy.ps1            upload all of public/ via FTPS
    tools/deploy.ps1 -Only api  upload one subtree

The SQLite database creates itself on the first request. The admin
credential hash does not: write it once by uploading a short-lived PHP
script that runs password_hash("user:pass", PASSWORD_DEFAULT) into
../fok-server-data/admin.hash from POSTed values, invoke it once over
HTTPS, then delete it from the server. Credentials must never exist in
the repo, in commits, or in plain text on the server.

## Security notes

- Admin credentials exist only as a password_hash() of "user:pass" in
  fok-server-data/admin.hash on the server. Neither the credentials nor the
  hash are in this repo. Failed logins are rate-limited per IP.
- Deploy credentials live in ~/.fok-server-deploy.json locally, outside the
  repo.
- Player IDs are public identities (as designed in FOK-snake); a secret
  session token for submission authenticity is future work, as is the
  replay-based score validation.

## API sketch

    POST /api/hello.php  {"id":"cafe0001", "duel_with":"deadbeef"?, "friends":[...]?}
      -> {"ok":true,"now":...,"online":n,"playing":n,"registered":n,
          "signals":[{"from":"...","type":"invite","payload":"..."},...],
          "friends_online":{...}?}
    GET  /api/poll.php?id=cafe0001
      -> 204 (nothing pending) | {"ok":true,"signals":[...]}
    POST /api/match.php  {"id":"cafe0001","action":"seek|cancel"}
      -> {"ok":true,"waiting":true} | {"ok":true,"matched":"...","role":"..."}
    GET  /api/scores.php?limit=10
      -> {"ok":true,"scores":[{"rank":1,"name":"...","score":...,...}]}
    POST /api/scores.php {"id","name","score","level","diff","color"?,"shopItems"?,"seed"?,"inputs"?}
      -> {"ok":true,"rank":n,"top":bool}
    POST /api/signal.php {"id","to","type":"invite|accept|decline|offer|answer|ice|bye|chat","payload"}
      -> {"ok":true}   (chat payloads capped at 120 bytes; matchmaking
                        payloads carry the player profile - see docs/API.md)

Signals are delivered through the recipient's next hello poll. Clients poll
slowly (~30 s) when idle and fast (~1-2 s) while matchmaking/signaling.
