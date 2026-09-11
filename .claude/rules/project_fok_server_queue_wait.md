# Queue-wait gauge: how to read it, and the three levers

The admin "Queue mean | worst" gauge measures Apache-accepted ->
PHP-started (`Load::queueUs`, X-Request-Start vs REQUEST_TIME_FLOAT):
the handoff to FPM - the wait for a free worker plus anything else that
delays the handoff on a shared machine. No server code runs in it.

Single worst rows in the tens of ms on a sub-ms mean are NOISE.
Saturation starts in the hundreds. The trigger for any new admission
work: a MEAN in tens of ms with SEVERAL DIFFERENT clients in the worst
list. One client, one row, is not evidence.

NOTHING IS ENFORCED ON hello AND NOTHING SHOULD BE: the wait is over
before hello.php runs, refusing a heartbeat makes a player read as
offline, and delaying one converts a queue wait into an occupied worker
- the resource being protected.

The three levers, do not re-derive:

1. Fewer requests in flight per client. SPENT (gap_ms, the roster folded
   onto hello, one request at a time).
2. Shorter worker occupancy. The only lever with room, but it buys queue
   time with request count - settled when Holds.php shipped. The default
   hold is 5 s, the cap (FOK_POLL_WAIT_MAX) 9; reopen the cap only with
   the trigger above in hand.
3. More workers. NOT ours - shared-host pool (~20).

Reading rules, each measured against live:

- The floor is ~1 ms and very stable (2026-09-08: thirty-odd requests,
  h2 and h1.1, fresh and reused connections, six clients at once, twelve
  streams on one connection - every one 1 ms). A row in the tens of ms
  is a REAL stall.
- The request BODY is NOT in the window: php-fpm stamps
  REQUEST_TIME_FLOAT when the params record arrives, the body streams
  after. A body held 600 ms and a 100-continue both read 1 ms. Neither a
  client's round trip, nor a slow uplink, nor its own HTTP/2 siblings
  can explain a reading - do not re-propose them.
- A row of ~130 ms beside HELD POLLS is THE POOL GROWING BY ONE CHILD
  (2026-09-09: holds 0 and 2 read 1 ms; holds 4, 6, 9 read 129-134 on
  the first request at that level and 1 on the repeat). Which request
  pays is an accident of arrival order, so the SCRIPT on such a row says
  nothing about that script (how friend.php was once reported slow).
- OPEN: the fork does NOT cover every ~130 ms row - the operator's idle
  laptop produces them two minutes apart, depth 1, Worker REUSED. That
  is the PRIORITY TODO in CLAUDE.local.md; do not quote the fork
  paragraph as the answer to a reused row.
- The Worker column names the process incarnation (pid plus kernel
  start time out of /proc/self/stat); a bare pid comes round often
  enough that a fresh child would read as reused.
- A row in the TENS of ms is the machine we share, NARROWED, not known:
  a cold filesystem lookup (Apache parses .htaccess per request; a
  dentry miss on shared storage costs tens of ms) or a CPU slice wait (a
  CFS period is ~24-48 ms). Both binary, neither reproducible from
  outside. To tell them apart record sys_getloadavg per core on the row
  - not done, three rows a day does not earn it.
- Deep is a floor, not a count (Util::noteCaller). The minute fold runs
  in the deferred tail after cost() is computed, so it never shows in
  the .ms column yet occupies the worker. The worst table is 24 h, the
  graph 60 min.

## DB wait gauge (since 1.5.0)

- WAIT is `BEGIN IMMEDIATE` and nothing else (Friends, Items, Starts,
  Db::tryWrite take it). A reading is QUANTIZED to ~1.1, ~3.2, ~8.4,
  ~18.4, ~33.6, ~53.7 ms - SQLite's busy handler sleeping 1, 2, 5, 10,
  15, 20 ms - and OVERSTATES the hold by up to the next step.
  Uncontended is microseconds and never reaches the list
  (Load::SLOW_FLOOR_US 1 ms); only 8.4 and up say a writer was really in
  the way.
- TOOK is every other statement's duration; a bare write that waited out
  busy_timeout counts the wait as part of it and the split cannot be
  made - do not re-propose it.
- Both are booked in the deferred tail (Load::flush) in the queue
  gauge's shapes. The connection open is excluded (Load::openDone). The
  worst list keeps the slowest access OF EACH REQUEST.
- A COMMIT row reads `COMMIT <Class::method> <n>p`: the caller off the
  stack at BEGIN IMMEDIATE (Load::txCaller, skipping Db and LoadPDO),
  and the WAL's growth in frames (exact: one writer held it across the
  span). `ckpt` means the log SHRANK - the commit crossed
  wal_autocheckpoint.
- `n:db_skip` is the housekeeping declining to queue for the writer
  (Db::tryWrite) - the only reading that says contention was real.
- `PRAGMA wal_checkpoint(PASSIVE)` is the hourly drain (Db::drainWal, end
  of the hourly tail). Measured: the cost is mostly FIXED per checkpoint
  (606 pages 5.6 ms, 24544 pages 131 ms), so draining more often costs
  MORE; do not lower wal_autocheckpoint (1000) and do not raise it. A big
  log does not slow reads (the WAL index is a hash). If a busy hour
  crosses the threshold too often, gate the drain on the -wal file size.

## APCu namespacing rule (from the 1.4.8 shared-counter bug)

Counters::PREFIX and Presence::COUNTS_KEY build on FOK_APCU_NS because
they are counted out of a PER-ENVIRONMENT database - a bare prefix let
one FPM pool share them between live and staging (the tell: an Azure
20.x address on hello.php is a CI runner). Rule for anything new:
namespace it if it comes from or goes back into the database, OR if it
is JUDGED against a store that is (the tournament store since 1.7.1: the
sweep decides by per-environment presence, so on a shared prefix one CI
push ended every live tournament). The bare-prefix stores (fok:hold:,
fok:sg:, fok:rq:, fok:pid:, fok:flight:, fok:rr:, fok:skew:) hold no
database-derived state and stay, as Config.php explains. The live prefix
never changes; a namespacing fix only ever moves STAGING keys.

Counters::max() keeps its guarded write: CAS up to MAX_TRIES (8), then a
GUARDED apcu_store - a bare store would lower the slot. STILL OPEN: a
minute never folded expires at BUCKET_TTL (900 s) and leaves the graph -
the remaining explanation for a worst-table-vs-graph mismatch.
