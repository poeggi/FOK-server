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
  game invite. New requests and acceptances notify the peer via a
  reserved 'friend' signal in its mailbox. Quick match stays open to
  strangers (the match response carries the opponent's name). Players
  not seen for 180 days (configurable) are expired automatically:
  removed from the database, friendships cancelled, friends notified.
- Relay fallback: when the P2P DataChannel cannot connect, duels relay
  their (input-level) messages through the server via relay.php long
  polls - degraded latency but works through any firewall; concurrent
  relayed duels are capped to protect the shared-hosting worker pool.
  This is NOT WebRTC relaying: there is no TURN server, and the server
  never carries an RTCPeerConnection. WebRTC is abandoned, and plain
  opaque messages go over HTTP instead.
- Global highscores: top 100 list. Submissions carry the deterministic
  replay material (seed + tick-stamped inputs) verbatim, so scores can later
  be sanity-checked by re-simulation to prevent spoofing (validated flag).
- 1:1 matchmaking hub: friends invite each other (gated by an accepted
  friendship) or quick-match with anyone waiting; the server relays
  matchmaking and WebRTC signaling (SDP/ICE) through a store-and-forward
  mailbox and issues the shared level-start time. A connection attempt
  either goes through or fails loudly: caps answer a distinct status
  (429/503), and an invite that expires before anyone picks it up sends
  its sender a failure receipt instead of evaporating behind an "ok". Game traffic normally
  runs peer-to-peer over a WebRTC DataChannel (server not involved); when
  P2P cannot connect it falls back to relaying through the server (see
  Relay fallback above).
- Connection tracking: per-client state of the current 1:1 connection -
  idle, inviting, invited, connecting or playing, with the peer and
  whether the pair runs p2p or relayed. Inferred from traffic the server
  relays anyway (invite handshake, ICE exchange, duel heartbeat, relay
  messages), so clients report nothing for it; the admin dashboard lists
  it for every online client.
- Admin interface at /admin/: a one-screen dashboard - statistics,
  connection state of every online client, server properties (PTS anchor,
  versions), alert feed, per-hour load, registered users, top-100
  management - plus a settings view behind the gear with the runtime
  configuration (incl. JSON export/import) and database backup (SQLite
  online backup, download) and restore (upload). Cards refresh on a
  global interval (default 30 s, control in the top bar); Connections has
  its own (default 1 s), since a connection changes in seconds.
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
        t.txt         clock source: Apache stamps the receive time into a
                      header, so sync never queues for a PHP worker
        time.php      millisecond clock sync, fallback for t.txt
        hello.php     heartbeat: presence, counters, signals, friends online,
                      debug flag (server instruction + client report)
        poll.php      fast signal poll, 204 when idle (matchmaking window)
        friend.php    friendship handshake: request/accept/remove/list
        match.php     quick-match queue (pair with anyone waiting)
        start.php     server-issued absolute start PTS per pair, for every
                      halt of the run (first/level/respawn/resume/rematch)
        relay.php     in-duel message relay (P2P fallback), long-polled
        scores.php    GET top 100 / POST submit score
        signal.php    POST matchmaking/WebRTC signaling message
        backup.php    GET/POST client config backup and restore, token-secured
        .user.ini     PHP limits for the API only (see Capacity below)
      debug/          client debug-report drop -> 4-digit PIN (1 day, 8 MB)
      admin/          session-protected admin UI + JSON API
      assets/         CSS/JS; admin dashboard is modular (see MODULES in
                      assets/admin.js - add a module object to extend it)
      src/            PHP classes, blocked from the web via .htaccess
    docs/API.md       the client-facing API contract (read this first when
                      writing a game client)
    test/checks.sh    all quality checks: PHP lint, ASCII-only guard,
                      secret-leak guard, strict_types guard, unit tests
                      (test/unit.php), HTTP smoke test (test/smoke.sh)
    test/load.php     capacity probe, not a pass-fail test (see Capacity)
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

## Capacity and limits

`php test/load.php [players] [duels]` times the database work of the hot
endpoints against a seeded throwaway database. It exists to enforce one
rule: **cost per request stays flat in the number of players**. Anything
that grows with the table is a bug - hence the cached presence counters
and an index behind every WHERE on a request path.

What limits this server, in order:

1. **PHP-FPM workers.** Every long poll (poll.php, relay.php with
   wait=N) holds one worker for the whole hold, and shared hosting gives
   a few dozen. No PHP setting changes that. Thousands of IDLE clients on
   the 30 s heartbeat are cheap (~170 short req/s at 5000 clients);
   thousands matchmaking at once are not - that is ~1 held worker each,
   and the reason poll_wait_max and relay_max_duels exist.
2. **SQLite has one writer.** Every hello writes. Sustained contention
   shows up as latency, then 500s (busy_timeout is 5 s), so the long
   polls peek lock-free and take the write lock only to drain.
3. **Relayed duels**, the most expensive client: a long poll each plus
   ~30 messages/s. relay_max_duels (default 3) is the honest "busy".

The server's own bookkeeping does not sit in the client's latency:
Util::defer runs the counters, the threshold sweep and the hourly player
expiry AFTER the response is flushed, so the request that happens to
trigger the sweep no longer makes someone wait for it. Measured on a
2000-player database, that moved ~815 us of the ~1330 us a hello spent in
the database (61 %) past the answer. It buys latency and predictability
only - the worker is held either way, so the ceiling above is unmoved.

For the writer itself, what counts is how often a request takes the lock,
not how long it waits. Both counters go in one multi-row upsert, so a
hello takes it twice (heartbeat + counters) instead of three times; the
heartbeat write is irreducible, since it IS the heartbeat. Dropping the
counters off the writer altogether would need shared memory between
workers, and this host has no APCu - the Properties card reports that,
along with opcache, whether the deferred flush is really available, and
what opening the database cost the request that drew the card.

`public/api/.user.ini` holds the only PHP settings we own (no FPM pool
access on shared hosting): body, memory and runtime caps for the game API
only - the admin keeps the defaults since its restore uploads a whole
database. The body cap is enforced in code as well (Util::jsonBody ->
413): .user.ini needs the host to honor user_ini.filename, so it is never
the only guard. opcache, realpath cache and the worker count are
host-level. If this outgrows shared hosting, fix workers first.

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
      -> {"ok":true,"server":"<x.y.z>","api":"3.1","env":"live"}
    GET  /api/t.txt
      -> header X-Fok-T: t=<server MICROseconds>   clock source, no PHP
    GET  /api/time.php
      -> {"ok":true,"t":<server ms>}   fallback clock source
    POST /api/hello.php  {"id":"cafe0001", "name":"KAI"?, "duel_with":"deadbeef"?,
                          "latency":ms?, "auto_accept":bool?, "debug":bool?,
                          "friends":[...]?}
      -> {"ok":true,"api":"3.1","now":ms,"debug":bool,"online":n,"playing":n,
          "registered":n,
          "signals":[{"from":"...","type":"invite","payload":"...","created":s},...],
          "friends_online":{...}?, "friends_latency":{...}?,
          "friends_name":{...}?}   (friends_* only real for accepted friends)
    POST /api/friend.php {"id","action":"request|accept|remove|list","peer"?}
      -> {"ok":true,"state":...} | {"ok":true,"friends":[...]}
         (request/accept notify the peer via a reserved 'friend' signal)
    POST /api/relay.php  {"id","peer","payload","pts"?} -> {"ok":true}
    GET  /api/relay.php?id=&peer=&wait=8
      -> {"ok":true,"messages":[...]} | 204   (P2P fallback relay)
    GET  /api/poll.php?id=cafe0001&wait=8
      -> 204 (nothing pending) | {"ok":true,"signals":[...]}
         (wait=N long-polls: answers ~150 ms after a signal arrives)
    POST /api/match.php  {"id":"cafe0001","action":"seek|cancel"}
      -> {"ok":true,"waiting":true}
       | {"ok":true,"matched":"...","role":"...","peer_name":"..."}
    POST /api/start.php  {"id":"cafe0001","peer":"deadbeef","epoch":n,
                          "reason":"first|level|respawn|resume|rematch",
                          "pts":ms}
      -> {"ok":true,"start_pts":ms,"epoch":n,"now":ms}
         identical for both peers; both name the same epoch, so the answer
         does not depend on when either asks. 409 if the caller is behind,
         400 if its pts is missing or in the future; a stale pts is
         refused only for a start that begins play (first/rematch).
    GET  /api/scores.php?limit=10
      -> {"ok":true,"scores":[{"rank":1,"name":"...","score":...,...}]}
    POST /api/scores.php {"id","score","level","diff","name"?,"color"?,
                          "shopItems"?,"seed"?,"inputs"?,"pts"?}
      -> {"ok":true,"rank":n,"top":bool}   (no name -> ANONYMOUS)
    POST /api/signal.php {"id","to","type":"invite|invite-relay|accept|accept-relay|decline|offer|answer|ice|bye|chat","payload"}
         (the -relay types set the no-P2P bit: honored when either side sends it)
      -> {"ok":true}   (chat payloads capped at 120 bytes; matchmaking
                        payloads carry the player profile - see docs/API.md)

Signals are delivered through the recipient's next hello or poll.php poll.
Clients poll slowly (~30 s) when idle and fast (~1-2 s) while
matchmaking/signaling. Two further signal types are server-generated and
rejected (400) if a client sends them, but every client must HANDLE them:
'friend' (a request/acceptance/expiry notification) and 'undelivered' (a
connection attempt expired before the peer collected it - the attempt is
dead). Caps answer a distinct status: 429 (mailbox full, relay backlog
full, or relay rate limit), 503 (relay busy), 413 (body over the cap).
