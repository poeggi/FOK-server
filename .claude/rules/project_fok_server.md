# Server dossier: invariants, host facts, deploy traps, decisions

Central game server for FOK Snake. PRODUCTION - the operational hard
rules (deploy == push to main, ASCII-only, credentials) are in CLAUDE.md;
this file holds the measurements, traps and decisions that are not
obvious from the code. Per-release narrative lives in git log and
docs/API.md.

## Version rules

The API CONTRACT is a MAJOR.MINOR string and clients gate on MAJOR only;
a MINOR may be RE-RELEASED with new optional fields, so clients must
FEATURE-DETECT and never version-gate an optional feature. Dropping an
OPTIONAL field is likewise not a MAJOR break when the contract already
requires an absent field to mean the client's own default; docs/API.md
records such withdrawals. FOK_SERVER_VERSION (implementation) is
independent and bumps every release: a release that MOVES THE CONTRACT
bumps the middle digit and resets the last one (1.4.18 -> 1.5.0), any
other one bumps the last digit, tagged as a bare number
(feedback_fok_server_version_bump.md).
Some releases shipped untagged, so match the next release number to the
LIVE version.php, never to the last git tag. docs/API.md is THE client
contract and must stay in step.

## Pacing

hello `pace` is {hold} only. Heartbeat 60 s (API 4.5; a client beats 30 s
against an older server), poll wait 9 s and the 100 ms request gap are
contract constants in docs/API.md (Pacing) - a number
that never changes belongs in the contract, not on the wire; LESS
mechanism, not more. Do not re-add pace_hello_ms / pace_gap_ms (removed;
stale settings rows for them are harmless, Settings::all() iterates DEFS)
or a spread/jitter field (pace.spread_ms was withdrawn: per-session
jitter only pays at a client count this host will not see). Every window
a heartbeat keeps alive - online, duel, auto-accept, conn TTL, signal TTL
- is 120 s and is checked through Util::since with FOK_BEAT_JITTER (1 s)
of grace, so nothing reads as gone before 121 s. A smoke that changes
signal_ttl puts back the DEFS default, or it pins staging.
tourney_after_step_ms (100) staggers the follow-up calls a pushed
tournament event provokes, per RECIPIENT, capped at the client's 1000 ms
guard. Per recipient, not per event: one transition pushes several events
to each seat, and a budget spread over the EVENTS once put the eight
roles callbacks inside ~130 ms - the measured peak of a tournament.

## Deploy

Pipeline: checks -> staging (own data dir + admin hash, FOK_ENV-detected)
-> remote smoke -> live -> verify version. Never manual-deploy except
emergencies. Upload order is src/ THEN assets/ THEN pages: assets before
pages, or a mid-window fetch caches stale content under the new immutable
?v= URL for a year; src/ first, or new endpoints run against the old
schema (both failure modes happened live). The remote smoke dominates
push-to-live time and is real request work through a keep-alive tunnel,
not handshake waste (grep the deploy log for `tunnel` before
re-theorising).

Two traps that cost whole sessions:

1. A failing staging smoke SILENTLY PINS LIVE at the last green commit
   while staging shows the new version - always curl /api/version.php on
   LIVE after a push and check the CI conclusion; never infer "deployed"
   from a green pre-commit hook.
2. The staging smoke runs against a PERSISTENT staging DB, so tests
   depending on accumulated rows can fail there and nowhere else.

## Host facts (shared hosting, PHP fpm-fcgi)

opcache YES, fastcgi_finish_request YES, APCu YES (verified shared across
worker pids, so it is a real IPC bus). Do not re-probe by hand -
src/Caps.php assesses once per FOK_SERVER_VERSION and the admin
Performance tab can force a re-check. Capacity MEASURED, not guessed:
~20-21 concurrent PHP requests (probe with concurrent poll.php?wait=N
holds - the one endpoint that writes nothing - and ramp until the wall
clock doubles; from one IP it is a lower bound). The clock source
api/t.txt is a STATIC file stamped by Apache mod_headers %t, precisely so
it never queues for a worker. Output is BUFFERED (~64KB) so
SSE/streaming push is not viable - project_fok_server_streaming.md.

## Ephemeral state lives in APCu

SQLite has ONE writer for the whole database and that writer, not CPU, is
the ceiling - so:

- The signal MAILBOX is APCu-only: per-recipient seq/ack window; delivery
  CLAIMS each message with apcu_delete so two overlapping polls can never
  both be handed one. The undelivered-invite receipt is a separate watch
  entry that must be deleted only AFTER the expiry check - deleting it
  for a message dropped as expired destroys the evidence.
- PRESENCE itself (Presence.php): one entry per player, and every request
  a beat, poll.php included. The row sees a SESSION: one upsert when a
  beat finds no live entry, one write-back by the rate-gated fold (the
  deferred tail of Util::bumpNow) once the entry is older than every
  window that reads it; a rename is written through, identity not being
  presence. The duel heartbeat stays a row write (item claims
  read duels.last_seen). player_nets is gone (schema 41); the networks
  ride in the entry.
- Presence::counts cache.
- Request COUNTERS are write-behind (Counters.php): apcu_inc per minute,
  a closed minute folded into the durable table in one upsert. Gotchas:
  a per-minute "already flushed" marker permanently retires that minute
  and silently drops every later count, so there is NO marker - the fold
  is idempotent via claim() (only the caller whose apcu_delete wins may
  write) and the scan is rate-gated instead. A YmdHi stamp used as an
  array key comes back as an INT and must be cast back.
- Tournament state (TourneyStore.php): apcu_add is the atomic
  test-and-set for the host claim, join code and per-tournament lock
  (replaced ~76 writer-lock acquisitions per 8-player run).
- NO SQLITE FALLBACK BY DESIGN: a host without usable APCu answers 503
  plus a perf alert, because an untested fallback only moves the outage
  into the write lock.
- Settings::int and Caps::get memoize PER REQUEST, which is what makes
  the poll.php hold loop touch zero database - do not "optimize" that
  away.

apcu_inc does NOT refresh the TTL of a key it increments while apcu_store
resets it on every write, so a counter pair written the two ways expires
UNEVENLY - see CLAUDE.md for the eviction-vs-expiry alert rule.

## SQLite invariants, do not relearn

- `DELETE ... RETURNING` IS A WRITE and holds the write lock until the
  statement finishes. Never prepare one above a long-poll hold loop or
  keep the handle alive - that pins the single writer for the whole poll.
  Prepare per drain, fetchAll, closeCursor() immediately (the
  Signals::expire shape). Never re-execute a handle left dirty by a
  failed attempt: SQLITE_MISUSE (21).
- Wrap writes in Db::retry; a transient SQLITE_BUSY must be RETHROWN, not
  swallowed as a domain error (Debug::submit once reported "PIN taken"
  for one and cost a flaky CI run).
- PDO binds an integer parameter as TEXT, and when the other side of the
  comparison is an EXPRESSION (not a column) SQLite ranks every integer
  below every string - CAST(? AS INTEGER) is required. This silently
  disarmed the admin lockout once.
- Settings stores a row ONLY for an override: a changed DEFS default does
  not reach installs that ever wrote the key, and a row set from the
  admin config screen still wins.
- The all-digit-id integer-key trap and the microtime pairing order are
  in project_fok_server_db_plan.md.

## Item registry (since API 4.0)

Item instances have 32-hex uids and the SERVER owns them: ownership is
one row in `items`, which is what kills backup-restore save-scumming. A
transfer MOVES the row under a compare-and-swap and can never mint one,
so the population is conserved. The CAS tests owner and frozen alongside
seq, which is what makes the claim DECISION READS safe outside BEGIN
IMMEDIATE (a stale snapshot is told to re-read); only the contradiction
check runs INSIDE the lock, because that is the only look that can see a
claim committing alongside this one. Transfers are CLAIMS against a
match: start.php hands each peer the pair's `mid` and its OWN per-peer
attestation secret (minted inside Starts::request's BEGIN IMMEDIATE,
never exposed to admin, never logged), and a claim carries
tag = substr(hash_hmac('sha256', "$mid|$tick|$ws_digest",
hex2bin($secretHex)), 0, 16) - the two client traps are hex-DECODING the
32-hex secret to 16 raw bytes and an UNPADDED decimal tick.

Claim ladder: a LOSS settles at once (nobody lies to lose one); an
unwitnessed gain is `held` until the peer attests or claim_grace_ms
passes; a forged tag (tag_invalid) or opposite claims for one
mid/uid/tick (contradiction) FREEZES the instance and raises a fraud
alert. Those are the ONLY two paths that may freeze; the first verdict
stands (the freeze UPDATE carries WHERE frozen = 0) and
frozen_at/frozen_why record which (since schema 39). Freezing is terminal
until an operator clears it.

A verdict is an EVENT, not a property of the instance it froze: resolving one
clears frozen_why and may drop the row outright, so the instance can never be
the record of what was found on it, and the per-player tally could say a
finding existed while nothing could say which. Since schema 42 the finding
goes to `item_disputes` (uid, player, why, mid, tick, created, seen), written
inside the SAME transaction as the freeze and the tally, so a verdict cannot
exist without a record of it. The admin card lists players whose findings are
UNREVIEWED (claims_disputed > claims_disputed_seen), the count opens them, and
marking them reviewed moves only the seen mark - the tally is the forensic
record and never goes backwards. Releasing an instance stays a separate
decision in its own popup: "I have read this" and "here is my verdict" must
not be one button. A finding from before schema 42 has no row and never will,
so the popup reports the difference rather than showing an empty table. A claim whose uid exists but is registered
to somebody else is a STALE WARDROBE (a restored config backup or an
unsynced duel loss): Alerts::note, never a raised item_counterfeit;
nothing moves, the instance is never frozen, and the client drops it at
its next list. A restore can never freeze an item.

`matches.closed` is written by NOTHING (since 1.6.1) and the column is left
in the schema only because dropping one rebuilds a table on the money path.
Whether a match is still being played is DERIVED from the duel heartbeat -
Items::MATCH_LIVE_DUEL, shared by the sweep, the housekeeping card and
Items::openMatches - because the server never reliably learns that a match
ended: a bye travels over the open DataChannel and never arrives. A stored
flag therefore only ever marked the endings that happened to pass through
signaling, and the admin tile read high for every one that did not. The `ledger` table is
audit-ONLY, never read to decide ownership. LIMIT: minting is still
client-trusted (the coin economy is client-side), so items are conserved
and auditable, NOT unforgeable - do not describe or extend the registry
as anti-forgery; moving generation server-side is the open TODO.

## Friend presence deltas (since API 4.6)

The four friend-facing screens used to tick hello every 5 s with the whole
id list and read the whole status table back - about 92 percent of all
hello traffic, each one a friendship lookup plus a presence lookup plus
the touch. 4.6 replaces it with a cursor: `friends_since` on hello, `fs`
on poll, no ids on the wire, and the caller's ACCEPTED friends answered as
`friends_delta` + `friends_at` + `friends_more`.

THE INVARIANT: the steady state costs APCu only. Nothing changed is ONE
apcu_fetch (the watch stamp and the due stamp together); a read that finds
something changed adds one bulk fetch of the caller's friends. SQLite is
touched twice and only twice: a cold friend-list cache reads the friends
table, and a cursor-0 read looks up names for friends with no entry left.
Never the duels table, never on a steady-state poll, and nothing scans the
keyspace.

- Every fact lives in the PRESENCE ENTRY: `chg` (ms of the last
  transition) and `duel` (the last duel beat, seconds). Playing is
  therefore derived per player from a window, not read off the duels row -
  which is why both peers stamp their own entry and neither writes the
  other's.
- TRANSITIONS are the only pushes: coming online, a rename, and a player's
  own not-playing -> playing edge. Each stamps `chg` and FANS OUT to the
  accepted friends, bumping their watch key. Going offline and leaving a
  duel push nothing - they are the absence of a beat and are derived at
  read time from the same windows every other reader uses.
- Three APCu key families, all per environment: `fl:<id>` the caller's
  accepted-friend ids (invalidated at every friends-table write, never
  aged into a stale roster), `fw:<id>` the last push aimed at that caller,
  `fd:<id>` the earliest future moment a window lapse could change
  something for them. Fast path: cursor >= fw and now < fd means nothing
  changed.
- A held poll checks the same two keys beside the mailbox, so a transition
  wakes every subscriber within the poll's check interval. It is NOT a
  mailbox message: `signals` stays exactly what it was, and a 200 woken by
  a delta carries an empty one.
- The cap (`friends_delta_max`, 64) never splits a stamp tie - a page runs
  past the cap to the end of the tie instead. Splitting one would strand a
  friend in the wrong state forever, because the client would advance its
  cursor past the rows it never got.
- `friends` on hello still answers exactly as in 4.5 when `friends_since`
  is absent. The deployed client sends ids; ignoring them outright would
  have broken every live player until they updated.

## Duel announcement (since API 4.7)

A duel is stated the way being online is - an edge in, an edge out, a window
that expires when neither arrives - and the edge IN is start.php, NOT the
heartbeat. Both peers already call start.php where play begins, so the duel
is on record from that moment and from two independent callers. Before this,
duel_with on hello was the only caller of Presence::touchDuel, so a friend's
WATCH row was up to a beat late, never appeared at all for a match shorter
than the gap to the next beat, and then stood for the rest of the online
window after the match ended.

- The announcement runs AFTER Starts::request has issued a start. A 409 means
  the caller is not in the pair's current run, and announcing a duel for it
  would offer a feed of a match nobody is playing.
- A tournament match calls start.php itself like any other duel, so it is
  announced by the same line and no tournament code knows about any of this.
- `duel_end` on hello is the other edge, naming the peer left. Once the
  DataChannel is open a bye goes peer-to-peer and the server never sees it,
  so the end must be STATED; absence cannot mean it, because a client closed
  mid-match sends nothing ever again. A hello may carry duel_end and
  duel_with together - the end is applied first, so a rematch announced in
  one request lands on the new pairing.
- TWO CLOCKS, deliberately apart. FOK_DUEL_SEEN_WINDOW (90 s, the presence
  entry) is the spectate offer and bounds a client that crashed;
  FOK_DUEL_WINDOW (120 s, the duels row) is unchanged because an item claim's
  deadline is measured from it and a claim legitimately arrives after the
  last tick. Wrong about the offer is cosmetic and the next beat repairs it;
  wrong about the other costs somebody an item. Do not merge them.
- `duel_private` (hello and start.php alike) is COUNTED, never ATTRIBUTED: in
  the playing figure, holding the duel window, on the operator's dashboard,
  and absent from a delta's playing. A property of
  the duel, not a latch - stated on every request that holds the duel up, so
  omitting it makes the duel public again from that request on.
- The transition fan-out keys on what a FRIEND can see (playing && !private),
  which is what makes a privacy toggle mid-match an ordinary transition
  instead of a case of its own, and stops a private duel waking every
  friend's held poll for a state that reads identical.
- Presence::playingOf reads the ENTRIES, not the duels table: it and the
  delta are two ways of asking one question, and answering it off the table
  would let a client ask the older way and learn about a private duel. The
  playing figure counts entries in the pass that already counts online,
  private ones included. So the duels table is now read by the item registry
  and the housekeeping and by NOTHING else, and touchDuel's upsert no longer
  reads a row back.
- EVERY start begins play (REASONS is first/rematch and nothing else), so
  every one mints a fresh match. A rematch names epoch 0 exactly as a first
  start does, so the epoch's ORDERING can no longer tell a leftover line
  from a live one - what does is the pair of (epoch, reason) plus a
  PAIR_WINDOW_MS (5 s) freshness window on the read. Before 1.6.0 the
  staleness test relied on in-run halts advancing the epoch, which the
  client stopped sending in 2026-09; a relay rematch inside KEEP_MS was
  therefore being handed the PREVIOUS match's start_pts. The row is still
  kept KEEP_MS (5 min) because matchInfo reads its mid with no window and a
  claim attests against that match long after the duel goes quiet.
- Cost added to start.php: one duels upsert on a latency-sensitive endpoint
  that already takes the writer for Starts::request. The local smoke is
  single-threaded and proves nothing about it - watch for INSERT INTO duels
  in the admin worst-access list.

## Tournament mode (since API 4.1)

THE INVARIANT, do not erode: the server orchestrates and settles ONLY -
schedule, roles, results, standings, bracket - and NEVER carries a byte
of match or spectator traffic; a tournament match is an ordinary P2P duel
and THAT PAIR calls start.php itself, which keeps start.php the sole
mid/secret authority. relay.php stays deprecated and untouched
(project_fok_relay_apcu.md).

Shape: public/api/tournament.php is ONE POST with an action switch
(create/join/leave/start/state/result/standdown/orphan/continue);
Tournament.php holds the state machine, Bracket.php the pure
seating/schedule/tie-break math (no DB, no clock, so unit-testable).
'tourney' is a RESERVED server-generated signal type drained through
hello/poll (a forged one would rewrite a bracket on someone else's
screen); 'watch' IS client-sendable. DEADLINES ARE SETTLED LAZILY on the
next request that touches the tournament - there is no cron - so a client
that stops asking can hang a walkover forever; the client must poll state
whenever a tournament is running and it is not in a match. Result ladder:
a reported LOSS settles at once, a lone win/draw is held
~tournament_result_ms, a contradiction FREEZES the node. A round is
played at level = min(round, tournament_max_level=10) and that cap MUST
NOT exceed MAX_LEVELS in the client's js/assets.js; `stage` rides as a
TOKEN the client words itself. Between rounds advance() stops on a gate
the host clears with `continue`, and the break clears itself on a TTL so
a host who closed the browser cannot wedge it. The HOST leaving a running
tournament ABANDONS it for everyone; a guest's identical `leave` is a
forfeit - the server is the only thing that tells them apart. ANNOUNCE IS
BY NETWORK PER FAMILY (the presence entry, one network per family,
matched within tournament_announce_window 180 s), because on a dual-stack
LAN the host
and joiner never share an address; hello's optional "nets" is a CLAIM,
never evidence (a claim never displaces a live observation and cannot be
rewritten faster than 60 s). GET /api/net.php is the field check for "my
phone cannot see the lobby on my PC".

## Testing lessons

The LOCAL SMOKE IS SINGLE-THREADED and therefore CANNOT reproduce SQLite
writer contention: a green unit+smoke run is NOT evidence that a locking
or concurrency fix works - say so plainly. Contention fixes have twice
shipped green and only real 2-client traffic showed the truth; one was
strictly worse than what it replaced. Never pin a test to a real deadline
(walkover_ms=1 measured against the ms a node was dealt was flaky 1 run
in 3 and silently skipped a deploy). Bare `wait` in smoke.sh waits on the
backgrounded php -S forever - wait on explicit curl PIDs; the test server
uses a random port. Utility .hidden must be !important
(equal-specificity .dashboard display:grid overrides it) and h2 checks
must be case-insensitive (HTTP/2 lowercases header names).

## Decided, do not re-open

- relay_max_payload STAYS 2048: the 1280 MTU rule is a UDP/DataChannel
  concern; relay.php is HTTP over TCP, and a base64 packet of 1280
  binary bytes is 1708 chars, so 1280 would reject a maximum-size
  packet. The relay payload ENCODING is the CLIENT's choice and stays
  out of docs/API.md.
- NEVER CHAIN TWO confirm() DIALOGS: a browser may suppress the second
  from the same gesture, and a suppressed confirm() returns CANCEL, so
  the click vanishes with no request and no error. Arm-then-confirm in
  the card instead, with the armed state outside the DOM.
- No admin surface for a frozen knockout node; no explanatory prose under
  the admin tournament tables.
- sys_getloadavg on shared hosting measures the WHOLE machine, so the
  load alert is a "host is thrashing" signal per core, never our
  capacity gauge.
- Admin restore is verify -> snapshot -> page copy (Backup::restore). The
  copy goes through SQLite's backup API, never a file swap, because
  admin/api.php holds a Db::get() across the whole request. An upload is
  refused unless it passes quick_check, has a players table and carries a
  schema this release can run (the ladder only goes forward). Afterwards
  the per-environment APCu stores (presence, conn, matchmaking, counter
  buffer) and the request's own deferred tail go, so nothing describing
  the replaced database writes into the restored one; the bare-prefix
  stores stay, being shared with the other environment. Persistent PDO
  stays off: Db::close() only drops the reference.

## Known dead weight

REMOVED in 1.6.0, all of it surface no client ever reached for, and all of
it a withdrawal on the SAME contract minor (4.7) - the version says what the
contract PERMITS, not what the server implements, so dropping what nothing
asks for does not move it:

- hello's `friends` id list and the `friends_online` / `friends_latency` /
  `friends_name` / `friends_playing` maps. The delta (4.6) is the only way
  to ask now. `Presence::playingOf` and `Friends::acceptedOf` went with it,
  having had no other caller.
- start.php's in-run reasons (level/respawn/resume), `Skew.php`, `resync`
  and start_pair_skew_ms - see Duel announcement for what replaced the
  epoch's staleness test.
- the `chat` signal type, chat_max_len and FOK_CHAT_MAX_LEN.
- stats.php, PStats and the pstats table (schema 43 drops it).

LEFT, and why: the relay - the client uses it behind its RELAY ONLY toggle,
so it is the only way to play without WebRTC; removal is a MAJOR contract
change and the admin 'relaying' gauge decides it, not a survey. And ~36 of
59 settings are contract numbers read at one site: no wire cost, admin
clutter only, and turning them into constants would churn the config
export/import path for nothing.

Used and fine: q_ms, pace.hold, nets (Presence::announceNet reads them),
after_ms, the delta's latency, backup.php, debug/submit.php, time.php
(t.txt fallback), net.php.

## Open

- `starts` to APCu, no fallback. The row is TWO things: a disposable epoch
  line (epoch, start_pts, reason - "dropping the row is always safe") and a
  durable `mid`, which is the handle to the match secrets a claim attests
  against. Only the second must survive, and it may be a denormalised copy -
  `matches` already holds (mid, a, b, opened) with the matches_pair index,
  so matchInfo could read the pair's newest match instead. That would leave
  `starts` holding nothing durable and let the whole table move to shared
  memory, taking the last SQL write off the signaling path (the reset at
  invite/invite-relay/offer). Eviction is benign there, which is what makes
  no-fallback the right shape: request() already treats a missing row as
  fresh.
  THE HARD PART, and why this is not done: the mint must stay in SQLite, so
  splitting the epoch line off breaks an atomicity that is one transaction
  today - the thing that guarantees both peers read the same mid and the
  same start_pts. It needs a lock key via apcu_add plus whole-entry
  read-modify-write, the shape TourneyStore already uses for the host claim.
  The race it introduces is exactly what a single-threaded local smoke
  cannot exercise, and the failure mode is two peers on different mids,
  which costs a player an item. Do it as its own release, never folded into
  another.
  NOT a way out: putting the epoch on the match row and deleting `starts`.
  A rematch is epoch 0 just like a first start (the in-run halts are P2P
  now), so without the reset at a pairing BEGIN the two are
  indistinguishable. The signaling reset is load-bearing; it can only be
  moved off SQL, never removed.

- tournament_create_cooldown is charged off the host's newest row with no
  state filter, so abandoning an open lobby locks the host out for the
  rest of the window (the floor is deliberate, the wording is not).
- signal.php could allow-list message types and bind `from` to the
  sender - low priority.
