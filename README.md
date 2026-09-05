# FOK-server

Central game server for FOK Snake (and future games). Runs as plain PHP on
shared hosting (Apache + PHP-FPM, SQLite), deployed to fok-server.poggensee.it.

Version 1.0.0 was the first stable release. The admin, relay and matchmaking
surfaces are considered production-stable.

Contract 4.2 is the current API line: 4.0 was the first MAJOR bump since
3.x - the server now owns item-instance ownership (see Item registry below),
and while the wire additions are backward-compatible, a client that carries
items must speak the registry to play online, which is what makes it a
major. 4.1 added tournament mode and 4.2 self-reported networks, both
additive.

## What it does

- Presence: clients send periodic heartbeats with their 32-bit player ID
  (8 lowercase hex chars, the public identity from FOK-snake), display
  name and measured latency. The server tracks id, IP, name, latency,
  first/last seen.
- Friendships: established THROUGH the server (request/accept handshake,
  removable); only an accepted, server-recorded friendship entitles a
  client to query a friend's status (online, latency, name) or send a
  game invite. A request to an unknown id is reported back ("exists":false)
  instead of recorded, so a mistyped code is caught early. Requests are
  throttled per id (at most one per second, and a short cooldown after a
  burst) on top of the unanswered-request spam ban. New requests and
  acceptances notify the peer via a reserved 'friend' signal in its
  mailbox. Quick match stays open to strangers (the match response carries
  the opponent's name). Players not seen for 180 days (configurable) are
  expired automatically: removed from the database, friendships cancelled,
  friends notified.
- Relay fallback (DEPRECATED - see docs/DEPRECATED-relay.md): when the P2P
  DataChannel cannot connect, duels relay
  their (input-level) messages through the server via relay.php long
  polls - degraded latency but works through any firewall; concurrent
  relayed duels are capped to protect the shared-hosting worker pool.
  This is NOT WebRTC relaying: there is no TURN server, and the server
  never carries an RTCPeerConnection. WebRTC is abandoned, and plain
  opaque messages go over HTTP instead. Still live; being phased out in
  favour of a persistent async hub off this host.
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
- Item registry (contract 4.0): the server owns item-instance OWNERSHIP.
  An item a player carries is a row in the server's item table, not a
  client-side flag, so a restored backup or an edited save cannot
  resurrect an item that was traded away. A transfer MOVES the single
  instance (compare-and-swap on its transfer counter) and is reported by
  a claim carrying HMAC attestation tags: the caller's own proves it was
  in the match, the peer's is evidence both sides observed the same
  ownership state at the same tick. A gain nobody witnessed is held for a
  grace period rather than granted; a tag that provably does not verify,
  or two claims contradicting each other about the same moment, freeze the
  instance and alert the operator. Mints and transfers are appended to a
  hash-chained, checkpoint-truncatable ledger for audit; ownership itself
  is always answered from the item row, never from the ledger. MINTING
  stays client-trusted (the coin economy is client-side), so this makes
  items conserved and auditable, not unforgeable - see the scope boundary
  in docs/API.md.
- Tournament mode (contract 4.1): 2-8 players in a lobby joined by a
  6-character code, a sparse first round, a seeded knockout and standings
  between them. The server orchestrates and settles only - schedule, roles,
  results, bracket - and never carries a byte of match traffic: every
  tournament match is an ordinary P2P duel between the two players its
  roles sheet names. Open lobbies are also ANNOUNCED to the host's own
  network, so people in one room find each other without typing a code.
  A player is remembered on one network per address family (IPv4 as the
  public address, IPv6 as the /64), because a dual-stack client picks a
  family per connection and the two devices in a room need not pick the
  same one; the family the server cannot observe is filled in from the
  addresses the client reports about itself (hello "nets"), which rank
  below what the server saw for itself. The code remains the capability
  and the way in from anywhere else.
- Connection tracking: per-client state of the current 1:1 connection -
  idle, inviting, invited, connecting or playing, with the peer and
  whether the pair runs p2p or relayed. Inferred from traffic the server
  relays anyway (invite handshake, ICE exchange, duel heartbeat, relay
  messages), so clients report nothing for it; the admin dashboard lists
  it for every online client.
- Admin interface at /admin/: a one-screen dashboard - game statistics,
  players (registered users, top-100 management), connection state of
  every online client, matches, item registry (frozen instances, players
  by disputed-claim count, recent ledger entries and an on-demand chain
  verify), server performance and diagnostics - live gauges including
  what requests waited for a free PHP worker, what each script costs in
  worker time, CPU and queries read over everything kept or the last hour
  or the last minute, each script's last 24 hours graphed, host
  capabilities and per-hour load, and a button that clears the traffic
  history without touching players, scores or the item registry - alerts
  and logs (alert feed, server log), debug reports - plus a settings view
  behind the gear with server properties (PTS anchor, versions), the runtime
  configuration (incl. JSON export/import) and database backup (SQLite
  online backup, download) and restore (upload). Cards refresh on a
  global interval (default 30 s, control in the top bar); Connections has
  its own (default 1 s), since a connection changes in seconds.
- Monitoring and alerting: inline checks (no daemons on shared hosting)
  raise de-duplicated alerts for excessive traffic, system overload, too
  many connections, client spam (flooding, oversized or repeatedly invalid
  messages), abuse guards that escalated and admin lockouts. An alert is
  reserved for something that needs a look; everything else worth reading
  back - a first rate-limit trip, an admin login, every state-changing
  admin action - is a plain log line instead, so an unseen alert always
  means something (the rule is documented in src/Alerts.php). Every alert
  is a log line too. Alerts are local-only in the admin UI for now;
  delivery backends (Telegram/SMS/Email) are a marked TODO there.
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
                      debug flag (server instruction + client report),
                      local tournament lobbies, self-reported networks
        net.php       what network the server sees the caller on - a field
                      diagnostic, reads and writes nothing
        poll.php      fast signal poll, 204 when idle (matchmaking window)
        friend.php    friendship handshake: request/accept/remove/list
        match.php     quick-match queue (pair with anyone waiting)
        start.php     server-issued absolute start PTS per pair, for every
                      halt of the run (first/level/respawn/resume/rematch);
                      also hands each peer the match id + its own secret
        items.php     item registry: list/mint/seed/claim - server-owned
                      item ownership, transfers attested by both peers
        relay.php     in-duel message relay (P2P fallback), long-polled
        scores.php    GET top 100 / POST submit score
        signal.php    POST matchmaking/WebRTC signaling message
        backup.php    GET/POST client config backup and restore, token-secured
        stats.php     GET/POST per-player gameplay stats (self-reported,
                      monotonic: games, levels, deaths, duels, playtime)
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
    test/live-protocol.sh
                      post-deploy wire check: two throwaway clients drive
                      the real handshake against a DEPLOYED server (live by
                      default, LIVE_BASE= for staging). Not part of CI.
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
   wait=N) holds one worker for the whole hold, and this host serves
   about 20 at once (measured against live: 20 parallel 6 s holds are
   absorbed with no queueing at all, 22 queue exactly one). No PHP
   setting changes that. Thousands of IDLE clients on the 30 s heartbeat
   are cheap (~170 short req/s at 5000 clients); thousands matchmaking at
   once are not - that is ~1 held worker each, and the reason
   FOK_POLL_WAIT_MAX and relay_max_duels exist. Those cap one hold and
   one feature; `hold_max_workers` (default 12, see Holds) caps their
   SUM, because the per-feature caps were each sized against the whole
   pool and nothing stopped them adding up to it. A poll that cannot get
   a slot answers 204 at once instead of queueing - it still reads its
   mailbox and still delivers anything pending, it just does not wait -
   so the workers the budget holds back stay available to the requests
   that are actually doing work.
2. **SQLite has one writer.** Every hello writes. Sustained contention
   shows up as latency, then 500s (busy_timeout is 5 s), so a long poll
   does not touch the database at all while it waits - the mailbox and
   the relay hub it is watching are both in shared memory (see below).
3. **Relayed duels**, the most expensive client: a long poll each plus
   ~30 messages/s. relay_max_duels (default 4, i.e. 8 held workers) is
   the honest "busy". It is deliberately a small share of the pool: the
   relay is deprecated and is the P2P fallback, so it may not price out
   the traffic it exists beside.

Whether that ceiling is actually being reached is measured rather than
inferred. Apache stamps the moment it RECEIVED the request into a request
header (mod_headers, `%t`, in microseconds) and PHP subtracts it from
REQUEST_TIME_FLOAT, the moment an FPM worker picked the request up: the
difference is the time that request spent QUEUED for a worker
(Load::queueUs). It is the one saturation signal PHP cannot produce for
itself, because while a request is waiting there is no PHP running to
notice. It is recorded for every request from Util.php module scope, not
per endpoint - the queue is a property of the pool rather than of any
script, and the endpoints that book no counters of their own (poll.php
above all, which holds a worker for nine seconds and deliberately counts
nothing) are exactly the ones whose wait matters most. The Server
performance card shows it as mean and worst per window: close to zero is
healthy, tens of milliseconds mean requests are waiting, hundreds mean it
is saturated. Waiting is not the same as the pool being short of workers:
measured on live, every wait above a millisecond was served by a worker
that was already WARM, which puts the contention above the FPM pool - at
the connection and stream-scheduling layer, where the cost is paid per
REQUEST rather than per byte. That is why 4.4 spends its effort on making
clients send FEWER SIMULTANEOUS requests (`ices`, `pace`, `after_ms`)
rather than smaller ones. `RequestHeader set` overwrites, so a client
cannot forge its own wait, and an absent header is ordinary rather than
an error - the built-in PHP server used by the smoke tests ignores
.htaccess entirely, and the measurement then simply records nothing.

A mean and a worst say how bad it got, not what was standing in the
queue, so the gauge's popup also lists the ten worst waits of the last 24
hours with what caused each one: the script, the player where the
endpoint takes an id in its query string, and the address otherwise
(Counters::worst, Util::queueWho). The list lives in shared memory beside
the counter buffer rather than in a table - it is a diagnostic, and the
moment worth re-reading it has already passed - so it is cleared by
Clear statistics along with the rest of the traffic history, and lost on
a restart. Waits under a millisecond are not recorded at all: there is
nothing in them to diagnose, and without a floor an idle server would
rewrite the whole list on nearly every request. Each row also says whether
the worker was one PHP had just started (Util::claimWorker): a fresh
worker pays for its own startup before it can answer, which lands in the
reading looking exactly like a busy pool, and "new" on every row means the
pool keeps going cold rather than running short of workers.

The admin dashboard's own requests are excluded from all of it. The
dashboard polls only while somebody is watching the very gauge these
numbers feed, so leaving them in has the observer measuring itself - on
the day the list was built it was the whole of the top ten. The test is
the script path (Util::isAdminScript), which is the one thing here a
client cannot choose. The dashboard also keeps its own request count
down rather than relying on being filtered out: one clock decides which
cards are due so that everything due goes out in a single batched
request, one-off reads join whatever batch is forming, and coming back to
a backgrounded tab wakes only the cards that keep themselves current
instead of all of them.

Counter history is kept for 30 days as hour buckets and 2 hours as minute
buckets, pruned by the same inline sweep as everything else, so the
per-script "Total" window means "everything the table still holds", not
"since the server was installed". A maximum is folded differently from a
total, since an hour's worst reading is the worst of its minutes and not
their sum (metric prefix "x:", see Counters).

The server's own bookkeeping does not sit in the client's latency:
Util::defer runs the counters, the threshold sweep and the hourly player
expiry AFTER the response is flushed, so the request that happens to
trigger the sweep no longer makes someone wait for it. Measured on a
2000-player database, that moved ~815 us of the ~1330 us a hello spent in
the database (61 %) past the answer. It buys latency and predictability
only - the worker is held either way, so the ceiling above is unmoved.

For the writer itself, what counts is how often a request takes the lock,
not how long it waits, so nothing that dies within seconds is kept in the
database any more. The signal mailbox, the relay hub, the presence-counter
cache and the request counters all live in APCu shared memory: the mailbox
and the hub because a long poll asks them "anything for me?" every 20 ms -
every 2 ms for the hub, which no query could carry - the counters because
they took the lock once per request to add one to a number (they are now
accumulated in memory and folded into the counters table once a minute,
see Counters). What is left on a hello is the heartbeat write itself,
which is irreducible - it IS the heartbeat.

That makes shared memory load-bearing rather than an optimization, and it
is treated as such: the signal mailbox and the relay hub both live there
with NO database transport at all, and answer 503 with an alert on a host
without usable APCu - an untested fallback would only move the outage
into the write lock. Whether this host has usable APCu is assessed live
on the Properties card, which also reports opcache, whether the deferred
flush is really available, and what opening the database cost the request
that drew it.

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
  raise an alert; a single failed attempt is logged, not alerted.
- Admin audit trail: every state-changing admin action writes one log line
  naming what was done, to what, and from which IP - and only once it has
  actually succeeded. The two that replace live state wholesale (settings
  import, database restore) raise an alert on top.
- Deploy credentials live in ~/.fok-server-deploy.json locally, outside the
  repo.
- Player IDs are public identities (as designed in FOK-snake); a secret
  session token for submission authenticity is future work, as is the
  replay-based score validation.
- Item ownership is server-authoritative, but MINTING is not: a client
  asserts its own box opens and purchases and is only rate-limited, so the
  registry makes items conserved and auditable rather than unforgeable
  (full scope boundary in docs/API.md). The per-duel attestation secrets
  are minted server-side, each peer is told only its own, and they are
  never logged nor exposed through the admin API - the dashboard counts
  open matches but never reads a key.

## API sketch

    GET  /api/version.php
      -> {"ok":true,"server":"<x.y.z>","api":"4.2","env":"live"}
    GET  /api/t.txt
      -> header X-Fok-T: t=<server MICROseconds>   clock source, no PHP
    GET  /api/time.php
      -> {"ok":true,"t":<server ms>}   fallback clock source
    POST /api/hello.php  {"id":"cafe0001", "name":"KAI"?, "duel_with":"deadbeef"?,
                          "latency":ms?, "auto_accept":bool?, "debug":bool?,
                          "friends":[...]?, "tourneys":bool?, "nets":[ip,...]?}
      -> {"ok":true,"api":"4.2","now":ms,"debug":bool,"online":n,"playing":n,
          "registered":n,
          "signals":[{"from":"...","type":"invite","payload":"...","created":s},...],
          "friends_online":{...}?, "friends_latency":{...}?,
          "friends_name":{...}?, "tourneys":[...]?}
         (friends_* only real for accepted friends; tourneys lists the open
          lobbies hosted on one of the caller's own networks)
    GET  /api/net.php
      -> {"ok":true,"ip":"...","family":4|6|0,"net":"..."}
         (what network the server sees YOU on - open it on two devices to
          settle whether they share one; reads and writes nothing)
    POST /api/friend.php {"id","action":"request|accept|remove|list","peer"?}
      -> {"ok":true,"state":...} | {"ok":true,"friends":[...]}
         (request/accept notify the peer via a reserved 'friend' signal)
    POST /api/relay.php  {"id","peer","payload","pts"?} -> {"ok":true}
    GET  /api/relay.php?id=&peer=&wait=8
      -> {"ok":true,"messages":[...]} | 204   (P2P fallback relay)
    GET  /api/poll.php?id=cafe0001&wait=8
      -> 204 (nothing pending) | {"ok":true,"signals":[...]}
         (wait=N long-polls: answers ~20 ms after a signal arrives)
    POST /api/match.php  {"id":"cafe0001","action":"seek|cancel"}
      -> {"ok":true,"waiting":true}
       | {"ok":true,"matched":"...","role":"...","peer_name":"..."}
    POST /api/start.php  {"id":"cafe0001","peer":"deadbeef","epoch":n,
                          "reason":"first|level|respawn|resume|rematch",
                          "pts":ms}
      -> {"ok":true,"start_pts":ms,"epoch":n,"now":ms,
          "mid":"<32-hex>","secret":"<32-hex>"}
         identical for both peers; both name the same epoch, so the answer
         does not depend on when either asks. 409 if the caller is behind,
         400 if its pts is missing or in the future; a stale pts is
         refused only for a start that begins play (first/rematch).
         mid + secret (4.0, additive) are the pair's match id and the
         CALLER'S OWN attestation secret, for item claims below; one match
         spans the whole duel and each side gets only its own secret.
    POST /api/items.php  {"id":"cafe0001","action":"list|mint|seed|claim",...}
      -> list  {"ok":true,"items":[{"uid":"<32-hex>","item_id":"crown",
                                    "seq":n},...]}
      -> mint  {"id","action":"mint","item_id":"crown","origin":"box|shop"}
               -> {"ok":true,"uid":"<32-hex>","seq":0}   429 over the cap
      -> seed  {"id","action":"seed","items":["crown",...]}   one-time
               -> {"ok":true,"items":[{"uid":"...","item_id":"..."},...]}
      -> claim {"id","action":"claim","mid","uid","from","to","tick","seq",
                "ws_digest","my_tag","peer_tag"?}
               -> {"ok":true,"seq":n,"state":"confirmed|settled|held"}
         The claim is the ONLY path that moves ownership: it moves the one
         instance by compare-and-swap on seq. A loss (from == caller) or a
         valid peer_tag settles at once; an unwitnessed gain is "held" for
         a grace period. A shape-valid tag that does not verify, or two
         claims contradicting each other about the same (mid,uid,tick),
         freeze the instance and alert the operator. Idempotent: replaying
         a settled claim changes nothing. See docs/API.md.
    GET  /api/scores.php?limit=10
      -> {"ok":true,"scores":[{"rank":1,"name":"...","score":...,...}]}
    POST /api/scores.php {"id","score","level","diff","name"?,"color"?,
                          "shopItems"?,"seed"?,"inputs"?,"completed"?,
                          "platform"?,"pts"?}
      -> {"ok":true,"rank":n,"top":bool}   (no name -> ANONYMOUS; completed =
         cleared the final level; platform = pc|mobile|tv|console, optional)
    POST /api/signal.php {"id","to","type":"invite|invite-relay|accept|accept-relay|decline|offer|answer|ice|ices|bye|chat","payload"}
         (the -relay types set the no-P2P bit: honored when either side sends it;
          'ices' (4.4) carries a JSON ARRAY of candidates - one request instead
          of the trickle burst, see docs/API.md)
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

## License

FOK-server is free software: you can redistribute it and/or modify it under
the terms of the GNU Affero General Public License as published by the Free
Software Foundation, either version 3 of the License, or (at your option) any
later version. It is distributed WITHOUT ANY WARRANTY, without even the implied
warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See LICENSE
for the full text.

Copyright (C) 2026 Kai Poggensee

This is network server software, so AGPL section 13 applies directly: anyone
who runs a modified version and lets others interact with it over a network
must offer those users the source of that version.
