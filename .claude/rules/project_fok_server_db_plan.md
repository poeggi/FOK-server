# DB contention work: design decisions and the do-not-add-back list

The DB-contention plan is fully executed (1.4.5/1.4.6/1.4.7, schema
36/37/38): lock scope + Settings/Caps APCu cache + ipcount + alert dedup;
the `conn` table dropped and connection tracking moved to APCu; `mm_queue`
dropped and matchmaking moved to APCu. All under the hood - the API
contract did not change.

## Design decisions worth keeping

- Why any of it works: WAL means readers never block. Every blocking
  problem is writer-vs-writer, so the only two levers are how OFTEN the
  single writer is taken and how LONG it is held.
- Fallback rule: caches (Settings, Caps) fall through to SQLite when APCu
  is unusable. MOVED STATE (ipcount, connection tracking, the matchmaking
  queue) has NO SQLite fallback, by design, exactly like Signals and
  RelayStore. Without shared memory a seeker simply keeps waiting and a
  tracked connection reads as absent.
- `Db::retry(callable, tries = 3)` re-runs its closure on
  SQLITE_BUSY/LOCKED, so the closure must be idempotent or re-read its
  inputs. An OPEN READ CURSOR before a write turns BUSY into an instant
  non-retryable error - fetch and closeCursor() inside the closure before
  writing. A BUSY that survives the retries propagates as 500; never
  swallow it as a domain error.
- Pairing without a transaction: the BEGIN IMMEDIATE that used to
  serialise seekers is replaced by a total order on (arrival microtime,
  id): a seeker only ever attempts a peer that arrived EARLIER, so
  exactly one side of any pair attempts it, plus a 2 s self-held busy
  marker so a third seeker cannot stack a second match onto someone
  mid-attempt.
- Guard placement: the APCu guard inside Settings and Caps must be raw
  (`function_exists('apcu_fetch') && apcu_enabled()`), never
  `Caps::apcu()` - that calls `Caps::get()`. Everywhere else use
  `Caps::apcu()`.
- Schema ladder is the only migration mechanism: append `if ($v < N)` at
  the end of `Db::migrate`, never edit an existing step, bump the
  constant, drop a removed table from `Db::COUNTED`. `Db::schemaV1()`
  keeps the historical base schema and is left untouched - it still
  creates `mm_queue`, which is correct.

## Decided NOT to do - do not add these back

Indexes on scores/friends/matches (the tables are far too small to
matter); a `Holds::inUse` cache (an APCUIterator over a handful of keys
is microseconds); Ledger append/checkpoint rework; friend_req /
admin_fails / mint quota to APCu; debug reports to files; removing
`flushDue`; pruning unseen alerts; duels or starts to APCu - item-claim
integrity reads `duels.last_seen` and `starts.mid`, so they must stay in
SQLite.

## Two rollout traps (fixed in 1.4.7; the lessons stand)

1. PHP turns an all-digit string array key into an INTEGER key. Player
   ids are 8 hex characters, so roughly one id in 43 is all digits. Every
   map built from an APCUIterator (ConnTrack::entries,
   Matchmaking::queue) and every map keyed by a SQL id column
   (ConnTrack::players) hands such a key back as an int, and passing it
   on to a `string` parameter is a TypeError under
   `declare(strict_types=1)`. Locked down by a unit block using ids
   11111111 / 22222222 with real player rows (listDuels drops an entry
   with no players row, so the test must seed them). ANY NEW iteration
   over those maps must cast the key.
2. Whole-second ordering inverted the offerer role. The two sides of a
   quick-match pair arrive milliseconds apart, so ordering the queue on a
   whole-second `since` tied them every time and the role fell to
   whichever id sorted lower - the contract is that the player who waited
   LONGER offers. Arrival is a microtime; `since` is cast to whole
   seconds only where it is reported. A unit assertion using ids that
   sort AGAINST arrival order locks it (11111111 / 22222222 happen to
   sort in arrival order and miss it; deadbeef / cafe0001 do not).
   Related trap: `===` between an int and a float is ALWAYS false, which
   matters because the unit helpers seed `since` as an int.

## Known: staging tournament cap drift

test/live-protocol.sh asserts the deployed tournament player cap is 8
(FOK_TOURNAMENT_MAX_PLAYERS). Live passes; staging answers 10. It is
stored-settings drift, not a code fault: the smoke's `config_export` ->
`config_import` roundtrip (test/smoke/06_admin.sh) writes every current
value back as an explicit settings row, which pins an old default and
shadows a new one forever. CI never runs live-protocol.sh against
staging. Fixing it means deleting the `tournament_max_players` row from
the staging DB (needs admin credentials).
