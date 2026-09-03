# Tournament mode - server contract (API 4.0 -> 4.1)

This is Part II of the tournament feature: everything FOK-server must
provide. Part I (the client plan, phase order, spectator/relay-tree design)
lives in FOK-snake/docs/TOURNAMENT-PLAN.md. The split of duties:

- The SERVER orchestrates and settles: lobbies, the schedule, per-match
  role sheets, results, standings, the bracket. It is the only authority
  on all of them; clients render what it says.
- The server NEVER carries game traffic. All match and spectator bytes are
  P2P (relay.php stays deprecated and untouched; see
  docs/DEPRECATED-relay.md).

The change is purely additive: API 4.0 -> 4.1, old clients unaffected.
Everything below follows existing house idioms by name so it drops into
place: the items.php action switch, the Items::claim result ladder, the
Signals reserved-type pattern, Settings::DEFS, Db::retry + BEGIN IMMEDIATE.

## Tournament lifecycle

state: 'open' (lobby, joinable) -> 'running' (schedule dealt, matches in
flight) -> 'done' (podium) or 'abandoned' (host left an open lobby, or the
lobby outlived tournament_join_ttl). 2..10 players; the creator (host) owns
the LOBBY only - once running, the bracket belongs to everyone and a
departing host merely forfeits like anyone else.

Match format: round 1 is a SPARSE schedule at 2 hearts (see below), then
the best 50% advance to a seeded single-elimination knockout at 2 hearts,
whose last node - the final - is a normal 3-heart duel. Matches run ONE at
a time, tournament-wide.

Item stakes are a creator choice at creation, default OFF, fixed for the
tournament's lifetime and echoed in every roles sheet. The server does not
enforce stakes beyond echoing the flag: the existing items.php claim ladder
already refuses anything without a valid mid, and clients with stakes off
never open item claims for the match.

## Schema (v25)

New `if ($v < 25)` block in Db.php, SCHEMA_VERSION 24 -> 25:

    CREATE TABLE IF NOT EXISTS tournaments (
        tid TEXT PRIMARY KEY,           -- 32-hex, random
        host TEXT NOT NULL,             -- 8-hex player id
        code TEXT NOT NULL,             -- 6-char join code (alphabet below)
        state TEXT NOT NULL,            -- open|running|done|abandoned
        round INTEGER NOT NULL DEFAULT 0,
        seed TEXT NOT NULL,             -- 32-hex, random, fixed at create
        stakes INTEGER NOT NULL DEFAULT 0,
        data TEXT NOT NULL,             -- JSON: schedule/standings/bracket
        created INTEGER NOT NULL,
        updated INTEGER NOT NULL
    );
    CREATE INDEX IF NOT EXISTS tournaments_state ON tournaments (state, updated);

    CREATE TABLE IF NOT EXISTS tournament_players (
        tid TEXT NOT NULL,
        id TEXT NOT NULL,               -- 8-hex player id
        seat INTEGER NOT NULL DEFAULT -1,  -- assigned at start, -1 in lobby
        forfeited INTEGER NOT NULL DEFAULT 0,
        joined INTEGER NOT NULL,
        PRIMARY KEY (tid, id)
    );

    CREATE INDEX IF NOT EXISTS idx_players_ip_seen ON players (ip, last_seen);

The players index is REQUIRED: the hello announce filter (below) queries by
ip + last_seen on every hello that asks, and must stay flat-cost.

Concurrency model: ONE writer row per tournament. Every transition reads
`data`, mutates in PHP, writes the whole row back under Db::retry with
BEGIN IMMEDIATE - the same shape that killed shared-host write contention
elsewhere. Transitions are a few per minute; this never becomes hot.

`data` JSON layout (server-internal; clients only ever see the event
projections below, so this can evolve freely):

    { "seats":   {"<id>": <seat>, ...},
      "schedule": [ {"nid":"r1.1","a":<seat>,"b":<seat>}, ... ],
      "results": {"<nid>": {"state":"settled|confirmed|frozen|void",
                             "winner":<seat|null>, "draw":bool,
                             "score":[a,b], "reports":{"<id>":{...}},
                             "mid":"<32-hex|null>", "dealt":<ts>} },
      "standings": [ {"seat":n,"pts":x,"diff":d}, ... ],
      "bracket":  [ {"nid":"ko1.1","a":<seat|null>,"b":<seat|null>}, ... ],
      "cursor":  "<nid of the match currently in flight>" }

## The round-1 sparse schedule (NORMATIVE)

Everyone plays at most 4 matches; with 10 players that is 20 matches, not
the 45 of a dense round-robin.

Let N = player count at start (2..10), k = min(N-1, 4).

1. SEATING. Order the joined ids by (joined ASC, id ASC), then Fisher-Yates
   shuffle them into seats 0..N-1 with the deterministic PRNG below. The
   shuffle decorrelates the sparse pairings from join order - friends who
   join together must not automatically meet.
2. EDGES.
   - N <= 4: every pair, canonical order = lexicographic by (lo, hi).
   - N >= 5: the circulant edges at offsets 1 and 2 on the seat circle:
     for d in (1, 2), for i in 0..N-1, the edge {i, (i+d) mod N}
     normalized to (lo, hi), listed in exactly that generation order.
     Every seat has degree 4; the total is 2N matches. (N = 5 this IS the
     full round-robin - the two rules agree at the boundary.)
3. ORDERING (rest spread). last = none; while edges remain: take the FIRST
   remaining edge sharing no seat with `last` (if none qualifies, the first
   remaining edge); append it; last = it. Deterministic, and no player
   plays twice in a row for any N >= 4.
4. NODE IDS. Round-1 nodes are 'r1.1' .. 'r1.M' in schedule order.

Match totals by N: 2->1, 3->3, 4->6, 5->10, 6->12, 7->14, 8->16, 9->18,
10->20. The lobby UI shows this number live; unit tests pin the table.

PRNG (for the shuffle and nothing else): the LCG
x' = (1664525 * x + 1013904223) mod 2^32, seeded with the first 8 hex
chars of `seed` as a uint32 (if 0, use 0x9e3779b9). draw(k) = next x mod k.
Fisher-Yates: for i = N-1 down to 1, j = draw(i+1), swap seats i and j.
Not cryptographic, and does not need to be: `seed` is fixed at create,
before anyone knows who joins.

## Points, standings, advancement (NORMATIVE)

- Win = 1 point, draw = 0.5 each, loss = 0. A walkover is a win for the
  present player; a void node (both absent) scores 0 for both.
- Score diff: for each player, the sum over their settled round-1 matches
  of (own final score - opponent final score), from the settling report's
  score pair. Walkover/void contribute 0.
- Advancers: A = max(2, ceil(N / 2)) - the best 50%, and a knockout stage
  always exists (N = 2 or 3 means round 1 then a final).
- Ranking (tie-break ladder, applied in order):
  1. points, descending;
  2. head-to-head - ONLY when the tied group is exactly two players AND
     their round-1 meeting exists and was decisive (the schedule is
     sparse: most pairs never met, and a draw separates nobody);
  3. score diff, descending;
  4. seeded coin toss: rank by sha256(seed + '|' + id) ascending hex.
     Deterministic, reproducible, and fixed before any result existed.

Standings recompute on every settled result; the advancer cut is taken
once ALL round-1 nodes are settled/void.

## The knockout bracket (NORMATIVE)

Seeds 1..A by round-1 rank. B = 2^ceil(log2(A)); seeds A+1..B are phantoms
(instant byes for their opponents). Placement is the standard recursive
fold: P(1) = [1]; P(2k) interleaves each s of P(k) with (2k+1-s). So
B = 8 gives positions [1,8,4,5,2,7,3,6]; adjacent pairs are the first-round
matches, winners pair by adjacency again. Example A = 5, B = 8: seeds
1,2,3 have byes, the only round-of-8 match is 4 vs 5; semis are 1 vs
winner(4,5) and 2 vs 3.

Node ids 'ko1.1'.., 'ko2.1'.., last node 'final'. Hearts: hm = 2 for every
KO node EXCEPT 'final', which is hm = 3. Bye nodes settle immediately with
no roles dealt. A KO draw re-deals the same node: same nid, fresh mid,
fresh roles signal - repeat until decisive.

## public/api/tournament.php

One POST endpoint with an action switch - the items.php idiom exactly:
405 on anything but POST, `invalid id` / `invalid action` 400s, every
response `{"ok":true,...}` or a Util::fail. Common request fields:
`id` (8-hex, required), `action` (required), `tid` (32-hex, required for
everything but create). Unknown tid = 404. Non-participant on a
participant-only action = 403 (same body shape as items.php denials).

### create

    { id, action:'create', stakes: bool }

Guards: per-id cooldown tournament_create_cooldown (429, retry_after);
at most one open-or-running tournament per host (409 `already hosting`).
Mints tid (32-hex random), seed (32-hex random), code: 6 chars from the
alphabet `23456789ABCDEFGHJKMNPQRSTUVWXYZ` (no 0/O/1/I/L ambiguity),
unique among open tournaments. Inserts the host into tournament_players.
Response: `{ ok, tid, code, stakes, max }` (max from settings).

### join

    { id, action:'join', tid?, code? }

Exactly one of tid/code (400 otherwise). Guards: state must be 'open'
(409 `already started` / 404 for done+abandoned), player count < max
(409 `full`), joining twice is idempotent (200, current lobby). On
success: insert row, fan out a 'lobby' event to all members. Response:
the full lobby projection (same shape as the 'lobby' event payload).

### leave

    { id, action:'leave', tid }

- open + host: state -> 'abandoned', 'lobby' event with reason to all.
- open + non-host: delete the row, 'lobby' event.
- running: set forfeited = 1. Every unsettled node involving the player
  becomes a walkover (settled, winner = the other side) - including the
  in-flight one; if the walkover cascades (all round-1 settled, or a KO
  opponent now unopposed) the usual transitions run. The tournament
  continues; it never dies with a participant.
- Idempotent: leaving twice, or leaving a done tournament, is a 200 no-op.

### start

    { id, action:'start', tid }

Host only (403). state 'open' (409), player count >= 2 (409 `need 2`).
Assigns seats, computes the full round-1 schedule (algorithm above),
stores it in data, state -> 'running', round -> 1, cursor -> 'r1.1',
deals the first roles sheet (below). Response: `{ ok }` (the caller
learns the schedule the same way everyone does - through events).

### result

    { id, action:'result', tid, nid, outcome:'win'|'loss'|'draw',
      score:[own, opp] }

Players of that node only (403 `not your match`) - spectators NEVER
report. nid must be the cursor node (409 `not current` otherwise, EXCEPT
an idempotent replay of an already-settled node, which answers its
current state). score entries are ints 0..100000 (400 outside).

The ladder - Items::claim shaped, verdict-first:

- 'loss' settles the node INSTANTLY (nobody lies to lose a match):
  state 'settled', winner = the other seat, the reporter's score pair
  (flipped to [a,b] seat order) recorded.
- 'win' alone parks the node 'held'. It settles when the opponent's
  matching 'loss' arrives (-> 'confirmed'), or when tournament_result_ms
  elapses (-> 'settled', evaluated lazily on the next touch of the
  tournament - shared hosting has no cron, same as the items grace).
- 'draw' + 'draw' -> 'confirmed' draw. A lone 'draw' holds like a lone
  win and settles as a draw at the deadline.
- CONTRADICTION (win vs win, draw vs win): state 'frozen', winner null,
  Alerts::raise per the existing alerts idiom, a 'freeze' event to all.
  Frozen nodes block the bracket until an admin clears them (admin card
  below). Round-1 freezes block only the advancer cut, not other matches.
- Idempotent replay: the same reporter re-posting a compatible report gets
  the node's current state back, never an error. Score mismatches alone
  (outcomes agree) never freeze; the settling report's scores win.

Response: `{ ok, nid, state:'held'|'settled'|'confirmed'|'frozen' }`.

On settle: recompute standings, 'result' event to all, advance cursor and
deal the next roles sheet - or run the round transition ('standings'
event + bracket build after r1; 'over' event + state 'done' after the
final).

WALKOVER BY SILENCE (lazy, on any touch of the tournament): if the cursor
node has no settled result, was dealt more than tournament_walkover_ms
ago, AND a player of it has been offline past FOK_ONLINE_WINDOW, that
player forfeits the node (both offline -> 'void'). Online-but-silent
pairs are left alone - a long match is not a fault; the freeze/admin path
covers pathological cases.

### state

    { id, action:'state', tid }

Participants only (403). The full client-facing read-back for reload and
rejoin: lobby projection + schedule + standings + bracket + cursor + the
caller's current roles sheet if a match is in flight. This is the ONLY
projection that includes everything; events are deltas.

### standdown / orphan

    { id, action:'standdown', tid, nid }
    { id, action:'orphan',    tid, nid }

Relay-tree escalations from the client plan (Part I, Phase C): standdown =
"I am a primary and about to background"; orphan = "I am a secondary and
both my primaries are gone". Both re-deal ROLES ONLY - never a bracket or
result transition. The server recomputes the spectator role assignment for
the cursor node (excluding the standdown caller / the dead primaries,
which it marks unavailable for this node) and fans out a 'roles-patch'.
Rate-limit orphan per id (one per few seconds is plenty); both are 200
no-ops when nid is not the cursor node.

## Roles dealing

Whenever a playable node becomes current (start, settle-advance, KO
re-deal): players = the two seats' ids, feeder = players[0] - the offer
host invariant the client relies on; spectators = every other
non-forfeited participant currently online (Presence within
FOK_ONLINE_WINDOW), in seat order: the first two are primaries, the rest
secondaries. Then one 'roles' event per participant (the `you` field
differs; offline participants get theirs on their next drain).

The pair still calls start.php themselves like any duel - mid/secret
issuance, epoch lines and the items attestation chain stay EXACTLY as they
are today. Tournament nodes reference the mid the pair reports (recorded
into the node on settle for audit); the server never pre-mints matches.

## Signals

Two changes in Signals.php / signal.php:

1. Signals::TYPES gains 'watch': client-sendable, opaque payload,
   peer-to-peer (the spectate request/grant round-trip and the
   feed-req backup-feeder handshake from Part I). NOT in NEEDS_RECEIPT -
   a lost watch request is retried by the client, not receipted.
2. 'tourney' becomes a RESERVED server-generated type, exactly like
   'friend' and 'peer-net': documented in the Signals.php header comment,
   NEVER added to TYPES (so signal.php keeps refusing it from clients),
   minted only by tournament.php/hello.php code paths and delivered
   through the existing hello/poll drain. Old clients drop it in their
   default branch - that is the whole backward-compat story.

'tourney' event payloads (JSON; every one carries `event` and `tid`):

    lobby      { event, tid, state, code, host, stakes, max,
                 players:[{id,name}...], reason? }        (~250B)
    roles      { event, tid, round, match, of, nid, hm, stakes,
                 players:[idA,idB], feeder, primaries:[...],
                 secondaries:[...], names:{id:name}, you } (~350B)
    roles-patch{ event, tid, nid, primaries, secondaries } (~120B)
    standings  { event, tid, rows:[{seat,id,pts,diff,rank,adv}...],
                 advancers:[id...] }                       (after round 1)
    result     { event, tid, nid, winner, draw, score }    (every settle)
    freeze     { event, tid, nid }
    over       { event, tid, podium:[id1,id2,id3?] }

`match`/`of` are 1-based position and total for the ceremony screen
("MATCH 7 OF 20"). `you` is 'play' | 'spectate' | 'idle'. All events fit
comfortably inside existing signal payload caps; fan-out is <= 9
recipients a few times a minute - no mailbox_cap interplay.

## hello.php additions

1. Request flag `tourneys: true` (validate exactly like auto_accept; the
   client sends it only while tournament screens are open). Response
   field:

       tourneys: [ { tid, code, host, host_name, players, max, stakes } ]

   filtered to state 'open' AND host's Util::clientIp() equal to the
   caller's, host seen within FOK_ONLINE_WINDOW - that is the whole
   "announced on the local network" mechanism, served by
   idx_players_ip_seen. Join-by-code covers everyone else; the code is
   the capability.
2. The friends block gains `friends_playing`: alongside friends_online
   etc., true for an ACCEPTED friend that is side a or b of a matches row
   with closed = 0 and opened within FOK_ONLINE_WINDOW. This is Part I
   Phase B's WATCH discovery; same anti-leak rule as the rest of the
   block (non-accepted ids read false).
3. Lobby reaping piggybacks here like other maintenance: an 'open'
   tournament with updated older than tournament_join_ttl flips to
   'abandoned' (lazy, no cron).

## Settings::DEFS additions

    'tournament_max_players'    => [10,     'Max players per tournament (2..10)'],
    'tournament_join_ttl'       => [900,    'Open tournament lobby lifetime (seconds)'],
    'tournament_result_ms'      => [15000,  'Hold a one-sided result before settling (ms)'],
    'tournament_walkover_ms'    => [180000, 'No result + player offline after (ms) = walkover'],
    'tournament_create_cooldown'=> [300,    'Per-id tournament create cooldown (seconds)'],

## Versioning, docs, admin, tests

- Config.php: FOK_API_VERSION '4.0' -> '4.1' + a version-history line.
  4.1 = tournament.php, the 'watch' type, the 'tourney' reserved type,
  hello tourneys/friends_playing. The client gates the TOURNAMENT menu
  row on api minor >= 1 at runtime.
- docs/API.md: new '## Spectating' (watch signal, friends_playing) and
  '## Tournament mode' sections mirroring the Item registry section's
  structure (endpoint, actions, ladder semantics, event schemas, the
  normative scheduler + tie-break + bracket rules above - the client team
  implements the bracket RENDERING from API.md alone).
- Admin: one MODULES card - open/running counts, frozen nodes with a
  clear action (pick winner / void, mirroring the items freeze handling),
  abandon button per tournament.
- unit.php sections:
  - scheduler determinism: fixed seed -> byte-identical seats + schedule;
    the match-count table (N=2..10 -> 1,3,6,10,10,12,14,16,18,20); degree
    <= 4 for every seat; N=5 circulant == full round-robin; rest-spread
    (no seat in consecutive matches for N >= 4).
  - promotion: A = max(2, ceil(N/2)); all four tie-breaks including the
    sparse-aware head-to-head (tied pair that never met falls through to
    diff) and the seeded coin toss; bracket fold placement + byes
    (A=5 -> only 4v5 plays in round of 8).
  - result ladder: loss-settles, win-held + confirm, held past
    tournament_result_ms settles lazily, draw+draw, contradiction
    freezes + alert, idempotent replay, spectator report 403, stale nid
    409, forfeit walkover cascade, silence walkover (offline) vs
    online-but-silent (untouched).
  - announce: same-IP filter, join_ttl reaping, code alphabet/uniqueness.
- test/smoke/07_tournament.sh between 05_items and 06_admin, ID1/ID2
  only, idempotent: create -> join -> start -> both report -> state
  read-back -> leave; plus one 403 (outsider state) and one 429 (create
  cooldown) probe.

## Failure matrix (the server's rows)

| Failure | Server behavior |
| --- | --- |
| Primary backgrounding (standdown) | roles-patch re-deal, nothing else |
| Secondary orphaned (both primaries gone) | promote replacements, roles-patch |
| Player offline + silent past walkover window | lazy walkover on next touch |
| Both players gone | node void, bracket continues |
| Contradictory results | node frozen + alert; admin clears |
| Host leaves open lobby | abandoned, lobby event |
| Any participant leaves mid-run | forfeit -> walkover cascade |
| Lobby never starts | join_ttl reap to abandoned |

## What the server must NOT do

- Carry a byte of match or spectator traffic (relay.php stays deprecated;
  nothing here extends it).
- Accept 'tourney' from a client, a result from a spectator, or a
  client-computed schedule/standings/bracket in any form.
- Pre-mint match handles: start.php remains the only mid/secret authority.
- Run timers: every deadline above is evaluated lazily on the next touch.
