# Queue-wait gauge: how to read it, and the three levers

The admin "Queue mean | worst" gauge measures Apache-accepted ->
PHP-started (`Load::queueUs`, X-Request-Start vs REQUEST_TIME_FLOAT),
i.e. the handoff to FPM: the wait for a free PHP worker, plus anything
else that delays the handoff on a machine we share. No server code runs
in that window.

Single isolated worst rows in the tens of ms on a sub-ms mean are NOISE -
a quiet pool, not a problem. Saturation on this gauge starts in the
hundreds of ms. The trigger for any new admission work: a run (or real
traffic) that puts the MEAN into tens of ms with SEVERAL DIFFERENT
clients in the worst list. One client, one row, is not evidence. A high
Deep value at a burst moment usually means one client stacked its own
requests - the last request in is not the cause.

NOTHING IS ENFORCED ON hello AND NOTHING SHOULD BE: the wait is over
before hello.php runs, refusing a heartbeat is how a player reads as
offline (Presence::touch / ConnTrack), and delaying one converts a queue
wait into an occupied worker - the very resource being protected.

The three levers, already reasoned through - do not re-derive:

1. Fewer requests in flight per client. SPENT (gap_ms plus the roster
   folded onto hello, so the background gate is one request).
2. Shorter worker occupancy (FOK_POLL_WAIT_MAX). The only lever with room
   left, but it buys queue time by raising request count - the trade
   settled when Holds.php admission control shipped. Reopen only with the
   trigger above in hand.
3. More workers. NOT ours - shared-host pool (~20). Same wall that killed
   SSE.

Reading caveats:

- The floor is ~1 ms, not 0, and it is very stable. That is what the
  handoff costs. Measured against live on 2026-09-08 by reading `q_ms`
  off hello.php from outside: thirty-odd requests over HTTP/2 and over
  HTTP/1.1, on fresh and on reused connections, six different clients at
  once, twelve streams multiplexed on one connection - every one of them
  1 ms. A row in the tens of ms is therefore a REAL stall, not an
  artefact of how the window is drawn.
- The request BODY is NOT in the window, however much it looks like it
  should be. php-fpm stamps REQUEST_TIME_FLOAT when the FastCGI params
  record arrives; the body streams in after that. A body held back
  600 ms and a genuine 100-continue round trip both still report 1 ms.
  So a client's round trip cannot explain a reading and neither can a
  slow uplink - do not re-propose either. Nothing in hello.php can cause
  its own queue wait.
- Nor can a client's own siblings: twelve of one client's requests in
  flight on a single HTTP/2 connection all read 1 ms.
- What is left for a tens-of-ms row is the machine we share, and it is
  NARROWED, not known. Six and twelve in flight leave our own pool
  nowhere near its ~20 ceiling, so it is not a worker of ours. The two
  candidates left are a cold filesystem lookup (Apache walks the
  directory and parses .htaccess on every request, and a dentry miss on
  shared storage costs tens of ms) and a wait for a CPU slice (one CFS
  period is ~24-48 ms). Both are binary, which is why the readings are
  1 ms or ~30 ms with nothing between, and neither can be reproduced
  from outside: hammering keeps the cache warm and the process
  runnable. To tell them apart, record sys_getloadavg per core on the
  row - high says the neighbours, near zero says storage. Not done;
  three rows a day against a 0.9 ms mean does not earn it yet.
- Deep is a floor, not a count - siblings that finish during the wait are
  not in it (see Util::noteCaller).
- The minute fold is invisible: Counters::hit() runs flushDue() in the
  deferred tail, after cost() has been computed, so the fold's own time
  never shows in the per-script .ms column yet occupies the worker.
- The worst table is 24 h and the graph is 60 min: a row older than an
  hour is simply out of the graph's window and nothing is wrong. A
  continuous MEAN plateau across a disputed minute proves the bucket
  existed.

## DB wait gauge (since 1.5.0)

Two different things, deliberately kept apart:

- WAIT is `BEGIN IMMEDIATE` and nothing else - the one statement whose
  whole job is taking the single writer, so its whole duration IS the
  wait. Friends, Items, Starts and Db::tryWrite are the paths that take
  it. A WAIT reading is QUANTIZED and reads only as one of
  ~1.1, ~3.2, ~8.4, ~18.4, ~33.6, ~53.7 ms - never between. That is
  SQLite's default busy handler sleeping 1, 2, 5, 10, 15, 20 ms, so the
  number says which sleep the lock came free in, and it OVERSTATES the
  real hold by up to the next step (measured on Linux against a holder
  held for a known time). An uncontended acquisition is microseconds and
  never reaches the list at all - Load::SLOW_FLOOR_US is 1 ms. So 3.1 ms
  is the mildest contention that CAN be recorded, one rung above the
  floor; only 8.4 and up say a writer was really in the way.
- TOOK is every other statement's own duration. A bare write that waited
  out busy_timeout counts that wait as part of it: SQLite does not report
  the busy handler's sleep, so the split cannot be made there. Do not
  re-propose splitting it.

Both accumulate in the request and are booked once in the deferred tail
(Load::flush), in the same sum/count/`x:` shapes the queue gauge uses, so
the graphs and the worst list are the same code. The connection open is
deliberately excluded (Load::openDone): five PRAGMAs and a schema read
that every request pays identically would BE the mean.

The worst list keeps the slowest ACCESS OF EACH REQUEST, not every access
- naming one costs a shared-memory read and write, and a request issuing
twenty statements must not pay that twenty times.

A COMMIT row reads `COMMIT <Class::method> <n>p`, because every contended
path ends in a COMMIT and the bare word cannot say which one was slow. The
caller is read off the stack at the BEGIN IMMEDIATE (Load::txCaller), which
skips Db and LoadPDO, so a housekeeping commit names the TASK rather than
Db::tryWrite and no call site carries a hand-written label. `<n>p` is the
write-ahead log's growth over the transaction in frames (page + 24 bytes,
page size never set so it is SQLite's default 4096), measured by two stats
of the -wal file: exact, not sampled, because SQLite has one writer and the
transaction held it across the whole span. `ckpt` in place of the count
means the log SHRANK - the commit crossed wal_autocheckpoint and paid for
the checkpoint SQLite charges to whichever write crosses the line, which is
the usual explanation for a lone slow COMMIT on an otherwise flat graph.

`n:db_skip` under the table is the housekeeping declining to queue for the
writer (Db::tryWrite), not an error. It is the only reading that says
contention was real rather than theoretical.

A `PRAGMA wal_checkpoint(PASSIVE)` row is the hourly drain, not a fault.
SQLite folds the write-ahead log back into the database file once it passes
`wal_autocheckpoint` (1000 pages, left at the default) and charges the whole
cost - the page copy plus the one fsync `synchronous = NORMAL` defers to
exactly here - to whichever write crosses the line, which is routinely a
player's. `Db::drainWal` does it at the end of the hourly tail instead,
where the response has already gone out.

Measured, so do not re-derive: the cost is mostly FIXED per checkpoint, not
per page (606 pages 5.6 ms, 3028 pages 12.6 ms, 24544 pages 131 ms), so
draining more often costs MORE in total - `wal_autocheckpoint = 200` took
0.58 s where 1000 took 0.34 s on the same work. Do not lower it and do not
raise it either: with the drain in place it is a backstop, and a backstop is
set where its worst case is acceptable, not where it is rare. A big log does
NOT slow reads (2.0 us per point read at 0 and at 36k pages) - the WAL index
is a hash. Eight players in duels put roughly 1500 pages an hour into the
log, two thirds of it the duel heartbeat (2 pages, and hello carries one per
player per minute), so a busy hour still crosses the threshold about once;
if that ever matters, gate the drain on the `-wal` file size in the same
tail rather than raising the ceiling.

APCu namespacing rule (from the shared-counter-buffer bug fixed in
1.4.8): Counters::PREFIX and Presence::COUNTS_KEY build on FOK_APCU_NS
because both are counted out of a PER-ENVIRONMENT database - a bare
prefix let one FPM pool share them between live and staging, so the live
worst list could show staging's rows (the tell: an Azure 20.x address on
hello.php is a GitHub Actions runner, and CI only calls hello.php against
staging). The other bare-prefix stores (fok:hold:, fok:sg:, fok:rq:,
fok:t:, fok:pid:, fok:flight:, fok:rr:, fok:skew:) hold no
database-derived state and are left alone on purpose, as Config.php
explains. Rule for anything new: namespace it if it comes from or goes
back into the database. The live prefix string stays unchanged, so a
namespacing fix only ever moves STAGING keys.

Counters::max() must keep its guarded write: CAS up to MAX_TRIES (8),
then a GUARDED apcu_store - a bare store would lower the slot when a
bigger peak lands inside the window, which is worse than dropping the
reading. STILL OPEN: a minute whose APCu keys are never folded expires at
BUCKET_TTL (900 s) and leaves the graph entirely - the remaining
explanation if a worst-table-vs-graph mismatch shows up.
