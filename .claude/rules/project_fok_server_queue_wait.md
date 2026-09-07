# Queue-wait gauge: how to read it, and the three levers

The admin "Queue mean | worst" gauge measures Apache-accepted ->
PHP-started (`Load::queueUs`, X-Request-Start vs REQUEST_TIME_FLOAT),
i.e. the wait for a free PHP worker. No server code runs in that window.

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

- The metric cannot separate "waited for a free worker" from "waited
  behind my own previous response on the same HTTP/2 connection". Both
  land in the same window, so a lone worst row from one client is not
  proof the pool was short.
- Deep is a floor, not a count - siblings that finish during the wait are
  not in it (see Util::noteCaller).
- A POST's body still has to arrive after Apache stamps X-Request-Start,
  so one RTT on a domestic line (~20-35 ms) lands in this metric looking
  exactly like a busy pool. Nothing in hello.php can cause its own queue
  wait.
- The minute fold is invisible: Counters::hit() runs flushDue() in the
  deferred tail, after cost() has been computed, so the fold's own time
  never shows in the per-script .ms column yet occupies the worker.
- The worst table is 24 h and the graph is 60 min: a row older than an
  hour is simply out of the graph's window and nothing is wrong. A
  continuous MEAN plateau across a disputed minute proves the bucket
  existed.

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
