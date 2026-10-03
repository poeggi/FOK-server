# Database: SQLite, the APCu state, and what lives where

## The model

WAL: readers never block. Every blocking problem is writer-vs-writer,
so the only levers are how OFTEN the single writer is taken and how LONG
it is held. That writer, not CPU, is the ceiling. Confirm a contention
change with the `.db` gauge and the worst list on the real host
(monitoring.md). The local smoke is single-threaded and proves nothing
about contention.

Must stay in SQLite: players, friends, scores, items, ledger, matches
(secrets), vault, ident, settings and caps as source of truth, alerts
rows, counters hour/total buckets, duels and starts (item claims read
duels.last_seen and starts.mid).

## Ephemeral state lives in APCu

- The signal MAILBOX: per-recipient seq/ack window. Delivery CLAIMS each
  message with apcu_delete, so two overlapping polls never both get one.
  The undelivered-invite receipt is a separate watch entry, deleted only
  AFTER the expiry check.
- PRESENCE (Presence.php): one entry per player, every request a beat,
  poll.php included. The row sees a SESSION: one upsert when a beat finds
  no live entry, one write-back by the rate-gated fold (the deferred tail
  of Util::bumpNow) once the entry is older than every window that reads
  it. A rename is written through. The networks ride in the entry. The
  duel heartbeat stays a row write.
- Request COUNTERS are write-behind (Counters.php): apcu_inc per minute,
  a closed minute folded into the table in one upsert. There is no
  "already flushed" marker (it would retire the minute and drop every
  later count): the fold is idempotent via claim() and rate-gated. A
  YmdHi stamp used as an array key comes back as an INT; cast it.
- The admin Live tile's two windows are ROLLING: the running minute is
  PEEKED out of APCu (Counters::peek, never claimed) on top of the closed
  minutes, which SQLite aggregates in one GROUP BY (SUM, MAX for peaks),
  the oldest minute pro rata. The graphs end on the running bucket,
  marked, never projected.
- Counters::max() keeps its guarded write: CAS up to MAX_TRIES (8), then
  a GUARDED apcu_store; a bare store would lower the slot. A minute never
  folded expires at BUCKET_TTL (900 s) and leaves the graph: the
  remaining explanation for a worst-table-vs-graph mismatch.
- Tournament state (TourneyStore.php): apcu_add is the atomic
  test-and-set for the host claim, join code and per-tournament lock.
- Connection tracking (ConnTrack) and the matchmaking queue
  (Matchmaking) are APCu too.
- Settings::int and Caps::get memoize PER REQUEST. That is what makes the
  poll.php hold loop touch zero database; do not optimize it away.

Fallback: CACHES (Settings, Caps, the event layer) fall through to SQLite
when APCu is unusable. MOVED STATE (mailbox, presence, connection
tracking, matchmaking, tournaments) has NO SQLite fallback, by design: a
host without usable APCu answers 503 plus a perf alert. The APCu guard
inside Settings and Caps is raw (`function_exists('apcu_fetch') &&
apcu_enabled()`), never Caps::apcu(), which calls Caps::get().
Everywhere else use Caps::apcu().

Matchmaking pairs without a transaction: a total order on (arrival
microtime, id). A seeker only attempts a peer that arrived EARLIER, so
exactly one side of a pair attempts it, plus a 2 s self-held busy marker
so a third seeker cannot stack a second match onto someone mid-attempt.

## APCu namespacing

One FPM pool can serve live and staging, and then they share one APCu
segment (the tell: an Azure 20.x address on hello.php is a CI runner).
Build a key on FOK_APCU_NS if it comes from or goes back into the
database, OR if it is JUDGED against a store that is: the tournament
store is, because its sweep decides by per-environment presence. The
bare-prefix stores (fok:hold:, fok:sg:, fok:pid:, fok:flight:) hold no
database-derived state and stay bare, as Config.php says. The live
prefix never changes; a namespacing fix only moves staging keys.

## SQLite invariants

- `DELETE ... RETURNING` IS A WRITE and holds the lock until the
  statement finishes. Never prepare one above a long-poll hold loop;
  prepare per drain, fetchAll, closeCursor() at once (the
  Signals::expire shape). Never re-execute a handle left dirty by a
  failed attempt: SQLITE_MISUSE (21).
- Wrap writes in Db::retry (3 tries on SQLITE_BUSY/LOCKED). The closure
  is idempotent or re-reads its inputs. An open read cursor before a
  write turns BUSY into an instant non-retryable error: fetch and
  closeCursor() inside the closure before writing. A BUSY that survives
  the retries propagates as 500; never swallow it as a domain error.
- PDO binds an integer parameter as TEXT, and against an EXPRESSION (not
  a column) SQLite ranks every integer below every string: use
  CAST(? AS INTEGER). Without it the admin lockout silently disarms.
- Settings stores a row ONLY for an override: Settings::set deletes the
  row when the value equals the DEFS default. A changed DEFS default
  does not reach an install with a row, and a row set from the config
  card wins. Several keys go through Settings::setMany: one transaction,
  one cache drop. The admin smoke's config_export -> config_import
  roundtrip writes every key, so it saves the tournament player cap back
  to its default afterwards, which removes that row.
- `counters` carries a PARTIAL index over the minute rows, `WHERE
  length(bucket) = 12`. A twelve-digit stamp sorts in among the
  ten-digit hours of its own day, so without it the hourly prune walks
  the month of hour rows under the writer. A query reaches the index
  only through that exact term; a GLOB alone walks the primary key. The
  fold writes hour and minute rows, sums and peaks, in ONE upsert whose
  ON CONFLICT picks SUM or MAX per row by the metric's name.
- A bulk DELETE holds the writer for its whole walk. Delete in slices of
  5000 rows (`rowid IN (SELECT rowid ... LIMIT n)`), as clear_stats
  does, for anything removing more than a few thousand rows.
- Reads stay out of a write lock: the hourly gauge sample reads its
  levels before Db::tryWrite and writes inside it.
- The schema ladder is the only migration mechanism: append
  `if ($v < N)` at the end of Db::migrate, never edit a step, bump the
  constant, drop a removed table from Db::COUNTED. Db::schemaV1() is the
  historical base schema and stays untouched (it creates mm_queue,
  correctly).
- Persistent PDO stays off.
- Admin restore is verify -> snapshot -> page copy (Backup::restore)
  through SQLite's backup API, never a file swap: admin/api.php holds a
  Db::get() across the request. An upload must pass quick_check, have a
  players table and carry a schema this release can run. Afterwards the
  per-environment APCu stores and the request's deferred tail go; the
  bare-prefix stores stay.

## Two PHP traps

- PHP turns an all-digit string array key into an INT, and about one
  player id in 43 is all digits. Every map built from an APCUIterator
  (ConnTrack::entries, Matchmaking::queue) or keyed by a SQL id column
  (ConnTrack::players) hands such a key back as an int; passing it to a
  `string` parameter is a TypeError under strict_types. Any new
  iteration over those maps casts the key. A unit block with ids
  11111111 / 22222222 and real player rows locks it (listDuels drops an
  entry with no players row).
- Arrival is a MICROTIME. The two sides of a quick-match pair arrive
  milliseconds apart; whole seconds would tie them and hand the offerer
  role to the lower id, while the contract says the player who waited
  longer offers. `since` is cast to whole seconds only where reported.
  The unit assertion uses ids that sort AGAINST arrival order
  (deadbeef / cafe0001). `===` between an int and a float is always
  false, and the unit helpers seed `since` as an int.

## Decided, do not add back

Indexes on friends or matches (too small to matter; scores has
idx_scores_player_created because nothing prunes it and the submit rate
check walks it); a Holds::inUse cache; a Ledger append/checkpoint
rework; friend_req, admin_fails or the mint quota in APCu; debug
reports to files; removing flushDue; pruning unseen alerts (an unseen
alert must not vanish); duels in APCu.

Left alone on purpose: Caps::assess keeps its three probes (cached, so
once per worker per version); AdminData calls Counters::flushDue() three
times per tick, but only the first writes; the counters reads are
non-sargable on a two-hour table, which is cosmetic.

## Parked until n:db_skip > 0 on the admin worst-access list

- `starts` to APCu, no fallback. The row is two things: a disposable
  epoch line (epoch, start_pts, reason) and a durable `mid`, which
  `matches` already holds. The hard part: the mint stays in SQLite, so
  splitting the epoch line off breaks the one transaction that gives
  both peers the same mid and start_pts. It needs an apcu_add lock plus
  whole-entry read-modify-write (the TourneyStore host-claim shape). The
  race is beyond a single-threaded smoke, and the failure is two peers
  on different mids: a lost item. Own release. Not a way out: the epoch
  on the match row and no `starts`. A rematch is epoch 0 like a first
  start, so the reset at a pairing BEGIN can move off SQL but never go.
- Two database files. On Linux, BEGIN IMMEDIATE takes the write lock on
  EVERY attached database, a bare single-statement write locks only its
  file, and one transaction over two WAL files commits non-atomically.
  So: two connections; the game handle never ATTACHes ops (pin it with a
  unit assertion on PRAGMA database_list); Db::ops() opens lazily. Ops
  takes counters, alerts, admin_fails, debug; settings and caps stay
  game-side. Couplings to break: Items::mint's hourly quota in
  `counters` inside the items transaction (give it its own game-side
  table); Util::hourly task 1 raising an alert inside a tryWrite;
  Housekeeping::sweep's five DELETEs under one tryWrite. The invariant
  it creates: nothing may need one atomic commit across both files.
