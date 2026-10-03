# Game protocol: pacing, duels, friends, items, tournaments

## Pacing

- hello `pace` is {hold} only. Heartbeat 60 s, poll wait 5 s (up to 9 s
  served) and the 100 ms request gap are contract constants in
  docs/API.md.
- Every window a heartbeat keeps alive (online, duel, auto-accept, conn
  TTL, signal TTL) is 120 s, checked through Util::since with
  FOK_BEAT_JITTER (1 s) of grace. A smoke that changes signal_ttl puts
  the DEFS default back.
- ONE REQUEST AT A TIME: while a poll is parked a client sends nothing
  else that starts PHP; a second only for the duel handshake, a third
  never, t.txt exempt. Nothing is enforced and nothing should be
  (monitoring.md).
- tourney_after_step_ms (100) staggers the follow-up calls a pushed
  tournament event provokes, per RECIPIENT (one transition pushes
  several events to each seat), capped at the client's 1000 ms guard.

## The pts gate

Asymmetric. Ahead: pts_ahead_max_ms (200); past half of it a warning
line, past all of it 400 plus an error line and the bogus alert. Behind:
start_sync_max_age_ms (1000) on a begin-play start only, 400 plus an
error line. The trip already pays for a slightly fast clock (nowMs is
read after the network and the queue wait), so a reading still ahead is
an anchor off by more than the trip. The 400 exists ONLY because a
server log is invisible to the client.

## Friend presence deltas

A cursor: `friends_since` on hello, `fs` on poll, answered as
`friends_delta` + `friends_at` + `friends_more`.

THE INVARIANT: the steady state costs APCu only, one apcu_fetch (watch
and due stamp together); a change adds one bulk fetch of the caller's
friends. SQLite only twice: a cold friend-list cache reads the friends
table, and a cursor-0 read looks up names for friends with no entry
left. Never the duels table; nothing scans the keyspace.

- Every fact lives in the PRESENCE ENTRY: `chg` (ms of the last
  transition) and `duel` (last duel beat, s). Playing is derived per
  player from a window; both peers stamp their own entry.
- TRANSITIONS are the only pushes: coming online, a rename, the caller's
  own not-playing -> playing edge. Each stamps `chg` and bumps the
  accepted friends' watch keys. Going offline and leaving a duel push
  nothing; they are derived at read time.
- Keys, per environment: `fl:<id>` accepted-friend ids (dropped at every
  friends-table write), `fw:<id>` the last push aimed at the caller,
  `fd:<id>` the earliest future lapse. cursor >= fw and now < fd means
  nothing changed.
- A held poll checks the same two keys beside the mailbox. It is no
  mailbox message: a 200 woken by a delta carries an empty `signals`.
- The cap (`friends_delta_max`, 64) never splits a stamp tie: a page
  runs to the end of the tie, or a friend is stranded in the wrong state.

## Duels

A duel is stated like being online: an edge in, an edge out, and a
window that expires when neither arrives.

- The edge IN is start.php (both peers call it where play begins), not
  the heartbeat. The announcement runs AFTER Starts::request issued a
  start; a 409 means the caller is not in the pair's current run. A
  tournament match calls start.php like any duel.
- The edge OUT is `duel_end` on hello: a bye goes peer-to-peer once the
  DataChannel is open, so the end must be stated. A hello may carry
  duel_end and duel_with together; the end is applied first.
- TWO CLOCKS, deliberately apart: FOK_DUEL_SEEN_WINDOW (90 s, the
  presence entry) is the spectate offer; FOK_DUEL_WINDOW (120 s, the
  duels row) is what an item claim's deadline is measured from.
- `duel_private` (hello and start.php) is COUNTED, never ATTRIBUTED, and
  stated on every request that holds the duel up. The fan-out keys on
  what a friend can see (playing && !private), so a privacy toggle
  mid-match is an ordinary transition.
- Whether a friend is playing is read from the presence ENTRIES, never
  the duels table (that would leak a private duel). The duels table is
  read by the item registry and housekeeping and by nothing else.

## Starts

- Every start begins play (REASONS is first/rematch only) and mints a
  fresh match. A rematch is epoch 0 like a first start, so (epoch,
  reason) plus PAIR_WINDOW_MS (5 s) on the read tells a leftover line
  from a live one. The row is kept KEEP_MS (5 min) as housekeeping's
  horizon; past the window nothing reads it.
- The match's mid and the caller's secret ride the start answer
  (Starts::request): the minting peer has both secrets from the mint,
  the settled peer reads its own off the match by the row's mid.
- Starts::forget reads before it deletes: a DELETE that matches nothing
  still takes the writer, and most duel setups have no leftover row.
- The duel row is written INSIDE the start transaction for the peer that
  mints (Presence::duelRow) and by its own upsert for the peer answered
  off the row; start.php's `minted` flag tells them apart. The local
  smoke proves nothing about contention there: watch for INSERT INTO
  duels in the admin worst-access list.

## Item registry

Instances have 32-hex uids and the SERVER owns them: ownership is one
row in `items`, which kills backup-restore save-scumming. A transfer
MOVES the row under a compare-and-swap and never mints, so the
population is conserved. The CAS tests owner and frozen alongside seq,
so the claim DECISION READS are safe outside BEGIN IMMEDIATE; only the
contradiction check runs inside the lock.

Transfers are CLAIMS against a match. start.php hands each peer the
pair's `mid` and its OWN attestation secret (minted inside
Starts::request's BEGIN IMMEDIATE, never exposed to admin, never
logged). A claim carries tag = substr(hash_hmac('sha256',
"$mid|$tick|$ws_digest", hex2bin($secretHex)), 0, 16). The client traps:
hex-DECODE the secret to 16 raw bytes, and an UNPADDED decimal tick.

- A LOSS settles at once. An unwitnessed gain is `held` until the peer
  attests or claim_grace_ms passes.
- A forged tag (tag_invalid) or opposite claims for one mid/uid/tick
  (contradiction) FREEZE the instance and raise a fraud alert: the ONLY
  two freeze paths. The first verdict stands (WHERE frozen = 0);
  frozen_at / frozen_why record which. Freezing is terminal until an
  operator clears it.
- A verdict is an EVENT, not a property of the instance: it goes to
  `item_disputes` in the same transaction as the freeze and the tally.
  The admin card lists players with UNREVIEWED findings
  (claims_disputed > claims_disputed_seen). Marking reviewed moves only
  the seen mark; the tally never goes backwards. Releasing an instance
  is a separate popup.
- A claim whose uid is registered to somebody else is a STALE WARDROBE
  (restored backup or unsynced loss): Alerts::note, nothing moves, never
  frozen; the client drops it at its next list.
- `matches.closed` is written by nothing. Whether a match is live is
  DERIVED from the duel heartbeat (Items::MATCH_LIVE_DUEL), because a bye
  never reaches the server. `ledger` is audit-only.
- LIMIT: minting is client-trusted. Items are conserved and auditable,
  NOT unforgeable; never describe or extend the registry as
  anti-forgery.

## Tournaments

THE INVARIANT: the server orchestrates and settles ONLY (schedule,
roles, results, standings, bracket) and never carries a byte of match or
spectator traffic. A tournament match is an ordinary P2P duel and that
pair calls start.php, which stays the sole mid/secret authority.

- public/api/tournament.php is ONE POST with an action switch.
  Tournament.php holds the state machine, Bracket.php the pure seating,
  schedule and tie-break math (no DB, no clock). `tourney` is a RESERVED
  server-generated signal type; `watch` is client-sendable.
- DEADLINES SETTLE LAZILY on the next request that touches the
  tournament (no cron), so the client polls state whenever a tournament
  runs and it is not in a match. Tournament::sweep ends a tournament
  none of whose seats was seen for tournament_idle_ttl (180 s). It rides
  the deferred tail of any client request (gated by
  tournament_sweep_secs) and never an admin script: reading the
  dashboard must not end a tournament. The test is PRESENCE, never
  activity.
- Results: a reported LOSS settles at once, a lone win/draw is held
  ~tournament_result_ms, a contradiction FREEZES the node.
- Two ways a node nobody played ends, deliberately disjoint.
  tournament_walkover_ms (60 s) hands it to whoever stayed, only where
  the other seat is GONE: unheard from for tournament_gone_secs (60 s,
  half the online window; Tournament::gone judges by silence and both
  rules use it). tournament_deadlock_ms (150 s) re-deals once and voids
  on the second lapse, only where neither seat is gone and no match was
  ever observed between them. That last test, Presence::duelSeenSince,
  reads the duels ROW (endDuel clears the entry) and runs as the LAST
  gate, so only a node about to be settled pays for it. A VOID carries
  `draw` TRUE plus `why` 'gone' or 'unplayed'.
- A round is played at level min(start + round - 1,
  tournament_max_level = 10); start is the create's `lvl` (default 1,
  clamped). The cap MUST NOT exceed MAX_LEVELS in the client's
  js/assets.js.
- Between rounds advance() stops on a gate the host clears with
  `continue`, self-clearing on a TTL. The HOST leaving ABANDONS the
  tournament; a guest's `leave` is a forfeit.
- ANNOUNCE IS BY NETWORK PER FAMILY (the presence entry, matched within
  tournament_announce_window, 180 s): on a dual-stack LAN the host and
  joiner never share an address. hello's `nets` is a CLAIM that never
  displaces a live observation.
