# Server dossier: invariants, host facts, deploy traps, decisions

Central game server for FOK Snake. PRODUCTION - the operational hard
rules are in CLAUDE.md; this file holds the measurements, traps and
decisions that are not obvious from the code. Per-release narrative is
in git log and docs/API.md.

## Version rules

The API contract is MAJOR.MINOR and clients gate on MAJOR only; a MINOR
may be re-released with new optional fields, so clients feature-detect
and never version-gate an optional feature. Dropping an OPTIONAL field
is not a MAJOR break when the contract already says an absent field
means the client's own default; docs/API.md records such withdrawals.
FOK_SERVER_VERSION bumps every release: a contract move bumps the middle
digit and resets the last (1.4.18 -> 1.5.0), anything else bumps the
last; tags are bare numbers. Some releases shipped untagged, so match
the next number to the LIVE version.txt, never to the last git tag.

## Pacing

hello `pace` is {hold} only. Heartbeat 60 s, poll wait 5 s (up to 9 s
served) and the 100 ms request gap are contract constants in docs/API.md
- a number that never changes belongs in the contract, not on the wire.
Do not re-add pace_hello_ms / pace_gap_ms or a jitter field. Every
window a heartbeat keeps alive (online, duel, auto-accept, conn TTL,
signal TTL) is 120 s, checked through Util::since with FOK_BEAT_JITTER
(1 s) of grace. A smoke that changes signal_ttl puts the DEFS default
back.

ONE REQUEST AT A TIME (4.10): while a poll is parked a client sends
nothing else that starts PHP; a second only for the duel handshake, a
third never, t.txt exempt. Nothing is enforced and nothing should be
(project_fok_server_queue_wait.md).

THE PTS GATE is asymmetric. Ahead: pts_ahead_max_ms (200) - past half a
warning line, past all of it 400 plus an error line and the bogus alert.
Behind: start_sync_max_age_ms (1000) on a begin-play start only, 400
plus an error line. The trip already pays for a slightly fast clock
(nowMs is read after the network and the queue wait), so a reading still
ahead is an anchor off by more than the trip. The 400 exists ONLY
because a server log is invisible to the client. Log levels:
Alerts::note / warn / error; raise() takes the level for its own line.

tourney_after_step_ms (100) staggers the follow-up calls a pushed
tournament event provokes, per RECIPIENT (one transition pushes several
events to each seat), capped at the client's 1000 ms guard.

## Deploy

Pipeline: checks -> staging (own data dir + admin hash) -> remote smoke
-> live -> verify version. Never manual-deploy except emergencies.
Upload order is src/ THEN assets/ THEN pages: assets before pages, or a
mid-window fetch caches stale content under the new immutable ?v= URL
for a year; src/ first, or new endpoints run against the old schema. The
remote smoke is real request work through a keep-alive tunnel (grep the
deploy log for `tunnel` before re-theorising): ~70 s, most of it the
~130 ms round trip from the Azure runner. Levers left, neither taken: an
EU runner, or a host-only profile on staging.

api/version.txt is a STATIC file both deploy paths write
(tools/make-version.sh) before the tree is hashed, renamed in the api/
tier after src/ and assets/, so live answering the new number proves the
rest landed. It rides every upload, `deploy.ps1 -Only` included.

THE UPLOAD PLAN IS ONE LEVEL DEEP. tools/deploy.sh's `changed_in` emits
a changed file only when its path under the top-level directory has no
further slash, while the manifest is a full `find` - so a NESTED file is
hashed as landed and never uploaded, and live 404s while the deploy
reports success. Every asset sits flat in assets/ (the font included).
Test the plan with changed_in against a fake CHANGED list before nesting
anything.

Three traps:

1. A failing staging smoke SILENTLY PINS LIVE at the last green commit
   while staging shows the new version - always curl /api/version.txt
   on LIVE after a push and check the CI conclusion.
2. The staging smoke runs against a PERSISTENT staging DB; a test that
   depends on accumulated rows can fail there and nowhere else.
3. TWO PUSHES INSIDE alert_cooldown FAIL THE SECOND ONE'S SMOKE. Six
   admin assertions need Alerts::raise to write a row, and its dedup
   gate is `apcu_add(alert:<type>, ttl = alert_cooldown)` - deliberately
   not the alerts table, so clearing alerts does not reset it and
   staging keeps it across a deploy. Not flaky: wait out the window and
   re-run the failed job (POST actions/runs/<id>/rerun-failed-jobs). The
   window is 60 s since 1.13.2 (900 cost two releases). It is a SETTING;
   an install that wrote the key from the config card keeps its own.

## Host facts (shared hosting, PHP fpm-fcgi)

opcache YES, fastcgi_finish_request YES, APCu YES and shared across
worker pids (a real IPC bus). Do not re-probe by hand - src/Caps.php
assesses once per FOK_SERVER_VERSION; the admin Performance tab can
force a re-check. Capacity MEASURED: ~20-21 concurrent PHP requests
(probe with concurrent poll.php?wait=N holds, the one endpoint that
writes nothing, and ramp until the wall clock doubles; from one IP a
lower bound). api/t.txt is a STATIC file stamped by mod_headers %t so it
never queues for a worker. Output is BUFFERED (~64KB), so no SSE -
project_fok_server_streaming.md.

The FTP login is JAILED IN THE DOCROOT: `..` is the docroot itself, so
nothing beside it can be listed, uploaded or renamed over FTP. Anything
above the docroot is reached only by PHP, which runs as the account owner
and owns every file - the one-time maintenance script in CLAUDE.md is the
tool (a rename of a directory took under a millisecond). The docroot's
parent is the MAIN DOMAIN'S docroot, so a sibling directory is
web-reachable there, and the app itself answers under a second origin
(www.<main domain>/fok-server/). That is why the data dirs live INSIDE
the docroot behind their own .htaccess (1.13.3): a location the code can
shield beats one it cannot see.

## Ephemeral state lives in APCu

SQLite has ONE writer and that writer, not CPU, is the ceiling - so:

- The signal MAILBOX is APCu-only: per-recipient seq/ack window;
  delivery CLAIMS each message with apcu_delete so two overlapping polls
  can never both be handed one. The undelivered-invite receipt is a
  separate watch entry, deleted only AFTER the expiry check.
- PRESENCE (Presence.php): one entry per player, every request a beat,
  poll.php included. The row sees a SESSION: one upsert when a beat
  finds no live entry, one write-back by the rate-gated fold (the
  deferred tail of Util::bumpNow) once the entry is older than every
  window that reads it; a rename is written through. The duel heartbeat
  stays a row write (item claims read duels.last_seen). The networks
  ride in the entry (player_nets is gone, schema 41).
- Request COUNTERS are write-behind (Counters.php): apcu_inc per minute,
  a closed minute folded into the durable table in one upsert. NO
  "already flushed" marker (it retires the minute and drops every later
  count): the fold is idempotent via claim() and rate-gated. A YmdHi
  stamp used as an array key comes back as an INT; cast it.
- Tournament state (TourneyStore.php): apcu_add is the atomic
  test-and-set for the host claim, join code and per-tournament lock.
- NO SQLITE FALLBACK for moved state, by design: a host without usable
  APCu answers 503 plus a perf alert. Caches (Settings, Caps, the event
  layer) fall through.
- Settings::int and Caps::get memoize PER REQUEST, which is what makes
  the poll.php hold loop touch zero database - do not "optimize" that
  away.

apcu_inc does NOT refresh the TTL of a key it increments while
apcu_store resets it on every write, so a counter pair written the two
ways expires UNEVENLY - CLAUDE.md has the eviction-vs-expiry alert rule.

## SQLite invariants, do not relearn

- `DELETE ... RETURNING` IS A WRITE and holds the lock until the
  statement finishes. Never prepare one above a long-poll hold loop;
  prepare per drain, fetchAll, closeCursor() at once (the Signals::expire
  shape). Never re-execute a handle left dirty by a failed attempt:
  SQLITE_MISUSE (21).
- Wrap writes in Db::retry; a transient SQLITE_BUSY must be RETHROWN,
  never swallowed as a domain error.
- PDO binds an integer parameter as TEXT, and against an EXPRESSION (not
  a column) SQLite ranks every integer below every string - CAST(? AS
  INTEGER). This silently disarmed the admin lockout once.
- Settings stores a row ONLY for an override; a changed DEFS default does
  not reach an install with a row, and a row set from the config card
  wins. Saving the default from the card deletes the row.
- The all-digit-id integer-key trap and the microtime pairing order are
  in project_fok_server_db_plan.md.

## Item registry (since API 4.0)

Item instances have 32-hex uids and the SERVER owns them: ownership is
one row in `items`, which is what kills backup-restore save-scumming. A
transfer MOVES the row under a compare-and-swap and can never mint one,
so the population is conserved. The CAS tests owner and frozen alongside
seq, which makes the claim DECISION READS safe outside BEGIN IMMEDIATE;
only the contradiction check runs INSIDE the lock. Transfers are CLAIMS
against a match: start.php hands each peer the pair's `mid` and its OWN
attestation secret (minted inside Starts::request's BEGIN IMMEDIATE,
never exposed to admin, never logged); a claim carries tag =
substr(hash_hmac('sha256', "$mid|$tick|$ws_digest", hex2bin($secretHex)),
0, 16) - the client traps are hex-DECODING the secret to 16 raw bytes
and an UNPADDED decimal tick.

Claim ladder: a LOSS settles at once; an unwitnessed gain is `held`
until the peer attests or claim_grace_ms passes; a forged tag
(tag_invalid) or opposite claims for one mid/uid/tick (contradiction)
FREEZES the instance and raises a fraud alert - the ONLY two freeze
paths; the first verdict stands (WHERE frozen = 0), frozen_at /
frozen_why record which (schema 39). Freezing is terminal until an
operator clears it.

A verdict is an EVENT, not a property of the instance: since schema 42
it goes to `item_disputes`, written in the SAME transaction as the
freeze and the tally. The admin card lists players with UNREVIEWED
findings (claims_disputed > claims_disputed_seen); marking reviewed moves
only the seen mark, the tally never goes backwards; releasing an
instance stays a separate popup. A claim whose uid is registered to
somebody else is a STALE WARDROBE (restored backup or unsynced loss):
Alerts::note, nothing moves, never frozen; the client drops it at its
next list.

`matches.closed` is written by NOTHING (since 1.6.1); whether a match is
live is DERIVED from the duel heartbeat (Items::MATCH_LIVE_DUEL), because
a bye travels over the DataChannel and never arrives. `ledger` is
audit-ONLY. LIMIT: minting is client-trusted, so items are conserved and
auditable, NOT unforgeable - never describe or extend the registry as
anti-forgery.

## Friend presence deltas (since API 4.6)

A cursor replaced the id-list polling that was 92 percent of hello
traffic: `friends_since` on hello, `fs` on poll, answered as
`friends_delta` + `friends_at` + `friends_more`.

THE INVARIANT: the steady state costs APCu only - one apcu_fetch (watch
stamp and due stamp together); a change adds one bulk fetch of the
caller's friends. SQLite twice only: a cold friend-list cache reads the
friends table, a cursor-0 read looks up names for friends with no entry
left. Never the duels table; nothing scans the keyspace.

- Every fact lives in the PRESENCE ENTRY: `chg` (ms of the last
  transition) and `duel` (last duel beat, s). Playing is derived per
  player from a window; both peers stamp their own entry.
- TRANSITIONS are the only pushes: coming online, a rename, the caller's
  own not-playing -> playing edge. Each stamps `chg` and bumps the
  accepted friends' watch keys. Going offline and leaving a duel push
  nothing; they are derived at read time.
- Keys, per environment: `fl:<id>` accepted-friend ids (invalidated at
  every friends-table write), `fw:<id>` the last push aimed at the
  caller, `fd:<id>` the earliest future lapse. cursor >= fw and now < fd
  means nothing changed.
- A held poll checks the same two keys beside the mailbox. It is NOT a
  mailbox message: a 200 woken by a delta carries an empty `signals`.
- The cap (`friends_delta_max`, 64) never splits a stamp tie - a page
  runs to the end of the tie, or a friend is stranded in the wrong state.

## Duel announcement (since API 4.7)

A duel is stated like being online - an edge in, an edge out, a window
that expires when neither arrives - and the edge IN is start.php (both
peers call it where play begins), not the heartbeat.

- The announcement runs AFTER Starts::request issued a start; a 409
  means the caller is not in the pair's current run.
- A tournament match calls start.php like any duel, so it is announced
  by the same line.
- `duel_end` on hello is the other edge: a bye goes peer-to-peer once
  the DataChannel is open, so the end must be STATED. A hello may carry
  duel_end and duel_with together - the end is applied first.
- TWO CLOCKS, deliberately apart: FOK_DUEL_SEEN_WINDOW (90 s, the
  presence entry) is the spectate offer; FOK_DUEL_WINDOW (120 s, the
  duels row) is what an item claim's deadline is measured from. Do not
  merge them.
- `duel_private` (hello and start.php) is COUNTED, never ATTRIBUTED, and
  is stated on every request that holds the duel up. The fan-out keys on
  what a friend can see (playing && !private), so a privacy toggle
  mid-match is an ordinary transition.
- Presence::playingOf reads the ENTRIES, never the duels table (that
  would leak a private duel). The duels table is read by the item
  registry and the housekeeping and by NOTHING else.
- EVERY start begins play (REASONS is first/rematch only) and mints a
  fresh match. A rematch is epoch 0 like a first start, so the epoch's
  ordering cannot tell a leftover line from a live one - (epoch, reason)
  plus PAIR_WINDOW_MS (5 s) on the read does. The row is kept KEEP_MS
  (5 min) because matchInfo reads its mid with no window.
- start.php pays one duels upsert on a latency-sensitive endpoint; the
  local smoke proves nothing about it - watch for INSERT INTO duels in
  the admin worst-access list.

## Tournament mode (since API 4.1)

THE INVARIANT: the server orchestrates and settles ONLY - schedule,
roles, results, standings, bracket - and NEVER carries a byte of match or
spectator traffic; a tournament match is an ordinary P2P duel and THAT
PAIR calls start.php, which keeps start.php the sole mid/secret
authority.

Shape: public/api/tournament.php is ONE POST with an action switch;
Tournament.php holds the state machine, Bracket.php the pure seating /
schedule / tie-break math (no DB, no clock). 'tourney' is a RESERVED
server-generated signal type; 'watch' is client-sendable. DEADLINES ARE
SETTLED LAZILY on the next request that touches the tournament (no
cron), so the client must poll state whenever a tournament runs and it
is not in a match. Tournament::sweep ends a tournament none of whose
seats has been seen for tournament_idle_ttl (180 s); it rides the
deferred tail of any client request (gated by tournament_sweep_secs) and
is excluded on admin scripts - reading the dashboard must not end a
tournament. The test is PRESENCE, never activity.

Result ladder: a reported LOSS settles at once, a lone win/draw is held
~tournament_result_ms, a contradiction FREEZES the node. TWO WAYS A NODE
NOBODY PLAYED ENDS, deliberately disjoint: tournament_walkover_ms
(180 s) hands the node to whoever stayed, only where the other seat
reads OFFLINE; tournament_deadlock_ms (150 s) re-deals once and voids on
the second lapse, ONLY where both seats read online and no match was
ever observed between them (Presence::duelSeenSince reads the duels ROW,
since endDuel clears the entry, as the LAST gate so only a node about to
be settled pays for it). A VOID carries `draw` TRUE plus `why` 'gone' or
'unplayed'.

A round is played at level = min(start + round - 1,
tournament_max_level = 10), start = the create's `lvl` (default 1,
clamped); the cap MUST NOT exceed MAX_LEVELS in the client's
js/assets.js. Between rounds advance() stops on a gate the host clears
with `continue`, self-clearing on a TTL. The HOST leaving ABANDONS the
tournament; a guest's `leave` is a forfeit. ANNOUNCE IS BY NETWORK PER
FAMILY (the presence entry, matched within tournament_announce_window
180 s) because on a dual-stack LAN the host and joiner never share an
address; hello's `nets` is a CLAIM that never displaces a live
observation. test/live-protocol.sh learns its own address from
www4/www6.poggensee.it/ip.

## Testing lessons

The LOCAL SMOKE IS SINGLE-THREADED and CANNOT reproduce SQLite writer
contention: a green run is NOT evidence that a locking fix works - say
so. Never pin a test to a real deadline (walkover_ms=1 was flaky 1 in 3
and skipped a deploy). Bare `wait` in smoke.sh waits on the backgrounded
php -S forever - wait on explicit curl PIDs. Utility .hidden must be
!important; h2 header checks must be case-insensitive. Read the whole
output of a rig, not a grep for FAIL ("unbound variable" does not start
with FAIL).

## Decided, do not re-open

- relay_max_payload STAYS 2048: relay.php is HTTP over TCP, and a base64
  packet of 1280 binary bytes is 1708 chars. The relay payload encoding
  is the client's choice and stays out of docs/API.md.
- NEVER CHAIN TWO confirm() DIALOGS: a browser may suppress the second
  and a suppressed confirm() returns CANCEL. Arm-then-confirm in the
  card, with the armed state outside the DOM.
- No admin surface for a frozen knockout node; no explanatory prose
  under the admin tournament tables.
- sys_getloadavg measures the WHOLE shared machine: the load alert is a
  "host is thrashing" signal, never our capacity gauge.
- Admin restore is verify -> snapshot -> page copy (Backup::restore)
  through SQLite's backup API, never a file swap (admin/api.php holds a
  Db::get() across the request). An upload must pass quick_check, have a
  players table and carry a schema this release can run. Afterwards the
  per-environment APCu stores and the request's deferred tail go; the
  bare-prefix stores stay. Persistent PDO stays off.

## Known dead weight

REMOVED in 1.6.0, all on contract minor 4.7 (the version says what the
contract PERMITS; dropping what nothing asks for does not move it):
hello's `friends` id list and its four status maps (with
Presence::playingOf and Friends::acceptedOf); start.php's in-run
reasons, `Skew.php`, `resync`, start_pair_skew_ms; the `chat` signal
type; stats.php, PStats and the pstats table (schema 43).

REMOVED in 1.9.1 (4.10): net.php; version.php (api/version.txt now);
the contract's demand that a client check the version.

LEFT, and why: the relay - the only way to play without WebRTC; removal
is a MAJOR contract change and the admin 'relaying' gauge decides it.
~36 of 59 settings are contract numbers read at one site: admin clutter
only, and constants would churn config export/import for nothing.

Used and fine: q_ms, pace.hold, nets, after_ms, the delta's latency,
backup.php, debug/submit.php, time.php (t.txt fallback, and the smoke's
up-probe and origin-allowlist assertions).

## Open - parked until n:db_skip > 0 on the admin worst-access list

- `starts` to APCu, no fallback. The row is TWO things: a disposable
  epoch line (epoch, start_pts, reason) and a durable `mid`, which
  `matches` already holds (mid, a, b, opened, matches_pair index) - so
  matchInfo could read the pair's newest match and the whole table could
  move to shared memory, taking the last SQL write off the signaling
  path (the reset at invite/invite-relay/offer). THE HARD PART: the mint
  stays in SQLite, so splitting the epoch line off breaks the one
  transaction that guarantees both peers the same mid and start_pts. It
  needs an apcu_add lock plus whole-entry read-modify-write (the
  TourneyStore host-claim shape); the race is what a single-threaded
  smoke cannot exercise, and the failure is two peers on different mids
  - a lost item. Own release, never folded in. NOT a way out: the epoch
  on the match row and no `starts` - a rematch is epoch 0 like a first
  start, so the reset at a pairing BEGIN is load-bearing; it can be
  moved off SQL, never removed.
- Split the database into two files (assessed 2026-09-08). Measured on
  Linux: BEGIN IMMEDIATE takes the write lock on EVERY ATTACHed
  database, a bare single-statement write locks only its file, and one
  transaction over two WAL files commits but not atomically. So: TWO
  CONNECTIONS, the game handle never ATTACHes ops (pin with a unit
  assertion on PRAGMA database_list), Db::ops() opened lazily. Ops takes
  counters, alerts, admin_fails, debug; settings and caps stay game-side
  (read on nearly every request, written almost never). Couplings to
  break: Items::mint's per-hour quota in `counters` inside the items
  transaction (give the quota its own game-side table); Util::hourly
  task 1 raising an alert inside a tryWrite; Housekeeping::sweep's five
  DELETEs under one tryWrite. Invariant it creates: nothing may ever
  need one atomic commit across the two files. Second argument: the
  counters churn (the minute-bucket and hour prunes) is what crosses
  wal_autocheckpoint, and it would leave the game file.
- Alert delivery backends (the TODO in src/Alerts.php): a dispatch step
  inside raise(); channel undecided.
- signal.php could allow-list message types and bind `from` to the
  sender - part of the anti-DoS review (CLAUDE.local.md).
