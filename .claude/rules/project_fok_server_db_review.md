# DB access review: outcome and the deliberately-left-alone list

The review's actionable findings are all shipped (see
project_fok_server_db_plan.md for the design decisions). One deliberate
non-fix on top: `PStats::submit`'s game-end write is wrapped in Db::retry
(a BUSY past busy_timeout was a 500 in front of the player), but its
read-modify-write race was left alone ON PURPOSE and must not be "fixed"
later - the merge only ever raises a value and the next submission
recomputes from the client's cumulative totals, so a lost increment heals
itself.

## Closed by decision - do not reopen without a new reason

- `Caps::assess` still has the three query()->fetchColumn() probes and
  the post-deploy herd, but the APCu cache turned it into once per worker
  per version instead of once per request. Largely defused.
- Unseen alerts are never pruned (Housekeeping deletes seen = 1 only).
  Deliberate - an unseen alert must not vanish - so it is a growth
  question, not a lock question.
- AdminData calls `Counters::flushDue()` three times per dashboard tick,
  but the first caller claims the closed minutes by delete and the other
  two write nothing. One write per tick in practice.
- The counters reads are non-sargable (bucket GLOB '[0-9]*' AND
  length(bucket) = 12). Reads, on a table holding two hours of minute
  rows. Cosmetic.

## Must stay in SQLite

players, friends, scores, items, ledger, matches (secrets), vault,
pstats, settings/caps as source of truth, alerts rows, counters
hour/total buckets.

## The underlying model

WAL means readers never block; every blocking problem is
writer-vs-writer, so the levers are how often the single writer is taken
and how long it is held. Confirm any contention change with the `.db`
gauge and the q_us worst list on the real host - the local smoke is
single-threaded and proves nothing about contention.
