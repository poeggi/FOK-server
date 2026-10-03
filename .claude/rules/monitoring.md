# Gauges and alerts: how to read them

## Queue wait

The admin "Queue mean | worst" gauge is Apache-accepted -> PHP-started
(Load::queueUs, X-Request-Start vs REQUEST_TIME_FLOAT): the wait for a
free worker plus anything else that delays the handoff on a shared
machine. No server code runs in it.

- The floor is ~1 ms and very stable: h2 and h1.1, fresh and reused
  connections, many clients at once, many streams on one connection.
- A single worst row in the tens of ms on a sub-ms mean is NOISE.
  Saturation starts in the hundreds. The trigger for any new admission
  work is a MEAN in tens of ms with SEVERAL DIFFERENT clients in the
  worst list. One client, one row, is not evidence.
- The request BODY is not in the window: php-fpm stamps
  REQUEST_TIME_FLOAT when the params record arrives. A body held 600 ms
  and a 100-continue both read 1 ms. Round trip, slow uplink and the
  client's own h2 siblings cannot explain a reading.
- A ~130 ms row beside HELD POLLS is the pool growing by one child: the
  first request at a new hold depth reads ~130, the repeat 1. Which
  request pays is arrival order, so the script on such a row says
  nothing about that script.
- UNEXPLAINED: ~130 ms rows marked Worker REUSED, depth 1, from an idle
  client about two minutes apart. The fork does not cover them (the
  priority TODO in CLAUDE.local.md). Never quote the fork for a reused
  row.
- The Worker column names the process incarnation: pid plus kernel
  start time from /proc/self/stat. A bare pid recurs often enough that a
  fresh child would read as reused.
- Other rows in the tens of ms are the shared machine, narrowed, not
  known: a cold filesystem lookup (Apache parses .htaccess per request;
  a dentry miss on shared storage costs tens of ms) or a CPU slice wait
  (a CFS period is ~24-48 ms). Telling them apart needs sys_getloadavg
  per core on the row, which is not recorded.
- Deep is a floor, not a count (Util::noteCaller). The minute fold runs
  in the deferred tail after cost() is computed: it never shows in the
  .ms column, yet occupies the worker. The worst table covers 24 h, the
  graph 60 min.

NOTHING IS ENFORCED ON hello AND NOTHING SHOULD BE. The wait is over
before hello.php runs. Refusing a heartbeat makes a player read as
offline; delaying one turns a queue wait into an occupied worker.

The three levers:

1. Fewer requests in flight per client: spent (the request gap, the
   roster on hello, one request at a time).
2. Shorter worker occupancy: buys queue time with request count. The
   default hold is 5 s, the cap FOK_POLL_WAIT_MAX 9; reopen it only with
   the trigger above in hand.
3. More workers: not ours (shared pool, ~20).

## DB wait

- WAIT is `BEGIN IMMEDIATE` and nothing else (Friends, Items, Starts,
  Db::tryWrite take it). A reading is QUANTIZED to ~1.1, 3.2, 8.4, 18.4,
  33.6, 53.7 ms (the busy handler sleeping 1, 2, 5, 10, 15, 20 ms) and
  overstates the hold by up to the next step. Uncontended is
  microseconds and never reaches the list (Load::SLOW_FLOOR_US, 1 ms).
  Only 8.4 and up says a writer was really in the way.
- TOOK is every other statement's duration. A bare write that waited
  out busy_timeout counts the wait in TOOK; the split cannot be made
  there (open TODO in CLAUDE.local.md).
- Both are booked in the deferred tail (Load::flush). The connection
  open is excluded (Load::openDone). The worst list keeps the slowest
  access of each request.
- A COMMIT row reads `COMMIT <Class::method> <n>p`: the caller at BEGIN
  IMMEDIATE (Load::txCaller, skipping Db and LoadPDO) and the WAL's
  growth in frames. `ckpt` means the log SHRANK: the commit crossed
  wal_autocheckpoint.
- `n:db_skip` is housekeeping declining to queue for the writer
  (Db::tryWrite): the only reading that says contention was real.
- The hourly drain is `PRAGMA wal_checkpoint(PASSIVE)` (Db::drainWal, end
  of the hourly tail). Its cost is mostly FIXED per checkpoint (606
  pages 5.6 ms, 24544 pages 131 ms), so draining more often costs MORE.
  Leave wal_autocheckpoint at 1000. A big log does not slow reads (the
  WAL index is a hash). If a busy hour crosses it too often, gate the
  drain on the -wal file size.

## Alerts

- Levels: Alerts::note / warn / error; raise() takes the level for its
  own line.
- Delivery backends are the TODO in src/Alerts.php: a dispatch step
  inside raise(), channel undecided.
- sys_getloadavg measures the WHOLE shared machine. The load alert says
  "host is thrashing", never our capacity.

## friend-cooldown-hard can be a client migration

A `friend-cooldown-hard` alert ("blocked for 3600s") can come from the
client's own friends reconciliation, not an abuser. It is an accepted
edge case: no fix without a new field report.

- A config restore (cloud or file) replaces the local friends list
  wholesale (up to 64 ids) without the synced markers.
- 3.5 s after every startup the client reconciles and sends
  `friend.php request` for every local id the server roster lacks, all
  at once.
- Friends::rateHit runs BEFORE the exists and ban checks. Defaults:
  interval 1 s, burst 10, cooldown 60 s, repeat window 600 s, hard
  3600 s. A too-fast request still advances the streak, so request 11
  of a pass trips; a second pass within 600 s escalates and raises.
- It needs 11+ local ids the server does not list: the player was away
  past player_ttl_days (365, Presence::forget drops both sides of every
  friendship), or the list holds dead ids, which answer `exists:false`,
  record nothing, and are re-requested at every launch.
- The roster then never converges: one request per second clears the
  gate, so each pass syncs one friend and buys a cooldown.

Telling it apart in admin: the player card shows friendships
accepted/pending and `friend_ban_until`. A migration is a named player
with same-size bursts an app launch or ~60 s apart. A prober walks fresh
ids and usually also trips `friend-spam`.
