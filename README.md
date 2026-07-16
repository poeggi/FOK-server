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
- 1:1 matchmaking hub: players invite each other by ID; the server relays
  matchmaking and WebRTC signaling messages (SDP/ICE) through a
  store-and-forward mailbox. The actual game traffic runs peer-to-peer over
  a WebRTC DataChannel for low latency; the server never touches it.
- Admin interface at /admin/: statistics (online, playing 1:1, registered
  users with id and ip, per-hour load), top-100 management, database backup
  (SQLite online backup, download) and restore (upload).

## Layout

    public/           mirrors the webroot 1:1
      index.php       landing page with the global top 100
      api/            JSON endpoints for game clients (CORS-allowlisted)
        hello.php     heartbeat + poll: presence, counters, pending signals
        scores.php    GET top 100 / POST submit score
        signal.php    POST matchmaking/WebRTC signaling message
      admin/          session-protected admin UI + JSON API
      assets/         CSS/JS; admin dashboard is modular (see MODULES in
                      assets/admin.js - add a module object to extend it)
      src/            PHP classes, blocked from the web via .htaccess
    tools/deploy.ps1  FTPS upload of public/

Runtime data (SQLite db, admin credential hash, backups) lives in
../fok-server-data/ ABOVE the docroot, created by the server at first run.
It is never web-accessible and never part of this repo.

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

    POST /api/hello.php  {"id":"cafe0001", "duel_with":"deadbeef"?}
      -> {"ok":true,"now":...,"online":n,"playing":n,"registered":n,
          "signals":[{"from":"...","type":"invite","payload":"..."},...]}
    GET  /api/scores.php
      -> {"ok":true,"scores":[{"rank":1,"name":"...","score":...,...}]}
    POST /api/scores.php {"id","name","score","level","diff","color"?,"shopItems"?,"seed"?,"inputs"?}
      -> {"ok":true,"rank":n,"top":bool}
    POST /api/signal.php {"id","to","type":"invite|accept|decline|offer|answer|ice|bye","payload"}
      -> {"ok":true}

Signals are delivered through the recipient's next hello poll. Clients poll
slowly (~30 s) when idle and fast (~1-2 s) while matchmaking/signaling.
