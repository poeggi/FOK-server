<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Alerts.php';
require_once __DIR__ . '/Caps.php';

/**
 * The relay hub's message store, with two transports behind one interface.
 *
 * A relayed message is ephemeral by definition - worthless once delivered,
 * and dropped after relay_ttl either way - so a durable, transactional,
 * single-writer B-tree is the wrong medium for it. SQLite allows exactly ONE
 * writer for the whole database, which means two unrelated duels serialise
 * against each other and against every heartbeat and counter in the server.
 *
 * APCu is shared memory across the FPM workers of this pool: no global
 * writer, no transactions, no fsync, and an expiry the cache enforces
 * itself, so the TTL sweep disappears too. When it is usable the relay stops
 * touching the database at all.
 *
 * The database transport is kept as the fallback and stays correct - it is
 * simply slower and contends with everything else. Which one is live is
 * decided ONCE per request from the stored capability assessment (see Caps);
 * nothing here probes the host per message.
 *
 * Exactly-once holds in both: the DB drains with DELETE ... RETURNING, and
 * APCu claims each message with apcu_delete(), which only one racing poll
 * can win. Ordering is the server-assigned seq in both.
 */
final class RelayStore
{
    private const PREFIX = 'fok:rq:';
    private static ?bool $apcu = null;

    /** Which transport is live. Decided once per request, never probed. */
    public static function usingApcu(): bool
    {
        if (self::$apcu !== null) {
            return self::$apcu;
        }
        $wanted = Settings::int('relay_apcu') === 1;
        if (!$wanted) {
            return self::$apcu = false;
        }
        if (!Caps::apcu()) {
            // Asked for (relay_apcu defaults ON) but the host cannot offer it:
            // warn where an operator will see it - the admin Alerts tab AND the
            // server log. Both de-duplicate to the alert_cooldown window (the
            // log line is gated on raise() reporting a fresh alert), so a
            // persistent fallback warns steadily without flooding either.
            $msg = 'Relay fell back to the database: APCu was requested '
                . '(relay_apcu=1) but is not usable on this host. Expect SQLite write '
                . 'contention and dropped messages under relayed play.';
            if (Alerts::raise('perf', $msg)) {
                error_log('FOK relay: ' . $msg);
            }
            return self::$apcu = false;
        }
        // APCu is usable, so the relay runs on shared memory - the default. The
        // host's APCu is trusted to be shared across the pool's workers; if it
        // were per-worker instead, cross-worker messages would be lost with no
        // runtime signal (there is no proof of sharing, by design). Force the
        // database transport with relay_apcu=0 if a host is ever suspected of
        // per-worker APCu.
        return self::$apcu = true;
    }

    /**
     * Should the pair's conn liveness row be refreshed for THIS message?
     * On the APCu transport the relay never touches the database except that
     * marker (ConnTrack::relaying), which the admin cards and the duel cap
     * read over FOK_RELAY_WINDOW. Writing it per message would put the single
     * SQLite writer back on the hot path APCu exists to clear, so on APCu it
     * is throttled to once per pair per FOK_RELAY_TRACK_THROTTLE, held in the
     * cache itself. On the database transport there is no cheap throttle store
     * and the message write already took the writer, so the row is written
     * every time (returns true) - identical to the old behaviour.
     */
    public static function shouldTrackRelay(string $from, string $to, int $now): bool
    {
        if (!self::usingApcu()) {
            return true;
        }
        $key = self::PREFIX . "track:$from:$to";
        if (apcu_fetch($key) !== false) {
            return false;   // refreshed within the throttle window
        }
        apcu_store($key, $now, FOK_RELAY_TRACK_THROTTLE);
        return true;
    }

    /**
     * The cheap per-pair "this duel already holds a relay slot" marker, in the
     * pair's own APCu namespace. Present means admitted: the relay POST can
     * skip the real concurrent-duel cap check (a conn read, plus a COUNT for a
     * new pair) on every message and just forward. Absent means the question
     * must be asked for real - a new pair, a relay-window of silence, or an
     * evicted marker (see relay.php). On the database transport there is no
     * such marker, so this is always false and the POST gates on ConnTrack
     * every message, exactly as before.
     */
    public static function admitted(string $a, string $b): bool
    {
        return self::usingApcu() && apcu_fetch(self::admitKey($a, $b)) !== false;
    }

    /**
     * Mark the pair admitted, or refresh the marker's life. Called on every
     * relayed message so it lives as long as the duel does, while its TTL
     * (FOK_RELAY_WINDOW, the same window the slot is counted over) frees the
     * slot once the traffic stops. A no-op on the database transport.
     */
    public static function markAdmitted(string $a, string $b): void
    {
        if (self::usingApcu()) {
            apcu_store(self::admitKey($a, $b), 1, FOK_RELAY_WINDOW);
        }
    }

    // ---- keys (APCu) -------------------------------------------------
    // One stream per DIRECTION: to:from. A pair has two, and each has a
    // single sender and a single receiver.
    private static function seqKey(string $to, string $from): string
    {
        return self::PREFIX . "$to:$from:seq";
    }

    private static function ackKey(string $to, string $from): string
    {
        return self::PREFIX . "$to:$from:ack";
    }

    private static function msgPrefix(string $to, string $from): string
    {
        return self::PREFIX . "$to:$from:m:";
    }

    /** The pair's admission marker (see admitted): ONE per pair, unordered, so
     *  both directions of the duel share it. */
    private static function admitKey(string $a, string $b): string
    {
        [$x, $y] = $a < $b ? [$a, $b] : [$b, $a];
        return self::PREFIX . "admit:$x:$y";
    }

    /** Server clock in milliseconds - the granularity a message is stamped at. */
    private static function nowMs(): int
    {
        return (int)round(microtime(true) * 1000);
    }

    /**
     * Enqueue one message from $from to $to. Returns false only when the APCu
     * store is full and the message did NOT go in: a game input is never
     * silently dropped - the caller turns a false into a retryable failure so
     * the sender resends. The database transport enqueues durably and always
     * returns true (a hard failure throws through Db::retry).
     *
     * The message is stamped in ms (created is stored ms, exposed as whole
     * seconds on delivery) so drain can report its age - see docs/API.md.
     */
    public static function push(string $from, string $to, string $payload): bool
    {
        $ttl = Settings::int('relay_ttl');
        $ms = self::nowMs();
        if (self::usingApcu()) {
            // The sequence outlives the messages on purpose: it must keep
            // increasing while a duel runs, and the messages under it expire
            // on their own after relay_ttl.
            $seq = apcu_inc(self::seqKey($to, $from), 1, $ok, 86400);
            $stored = apcu_store(
                self::msgPrefix($to, $from) . sprintf('%012d', $seq),
                ['p' => $payload, 'c' => $ms],
                $ttl
            );
            if ($stored !== true) {
                // Shared memory is full. The seq was already incremented; that
                // gap is harmless (drain sorts by key and acks to the high
                // water mark, so it self-heals). What must NOT happen is
                // acking a message that never enqueued, so report the refusal.
                Alerts::raise('perf', 'Relay APCu store failed: shared memory is full. '
                    . 'A relayed message was refused and the sender will retry; raise '
                    . 'the APCu size or lower the relay limits.');
                return false;
            }
            return true;
        }
        $db = Db::get();
        [$a, $b] = $from < $to ? [$from, $to] : [$to, $from];
        Db::retry(static fn() => $db->prepare(
            'INSERT INTO relay (pair, from_id, to_id, payload, created) VALUES (?, ?, ?, ?, ?)'
        )->execute(["$a:$b", $from, $to, $payload, $ms]));
        return true;
    }

    /**
     * Cheap "anything for me?" for the hold loop - it runs every
     * FOK_POLL_CHECK_USEC, so it must stay O(1) and never take a lock.
     */
    public static function hasAny(string $to, string $from): bool
    {
        if (self::usingApcu()) {
            // Two shared-memory reads, no scan: the sequence is the high
            // water mark and the ack is how far this receiver has got.
            $seq = (int)apcu_fetch(self::seqKey($to, $from));
            $ack = (int)apcu_fetch(self::ackKey($to, $from));
            if ($seq < $ack) {
                // seq below ack is impossible in normal running: the counter
                // was evicted (and re-seeded low) under shared-memory pressure
                // while the ack survived, which this cheap gate would read as
                // "nothing pending" forever - stranding any live messages.
                // Fall back to the authoritative scan: if a key is stranded,
                // say so and let drain() deliver it and realign the ack (it
                // detects the same desync); if none survives, realign here so
                // the gate stops re-scanning on every poll.
                Alerts::raise('perf', 'Relay seq/ack desync: APCu evicted a relay '
                    . 'counter under memory pressure. Raise the APCu size if this recurs.');
                if (self::anyKey($to, $from)) {
                    return true;
                }
                apcu_store(self::ackKey($to, $from), $seq, 86400);
                return false;
            }
            return $seq > $ack;
        }
        $st = Db::get()->prepare('SELECT 1 FROM relay WHERE to_id = ? AND from_id = ? LIMIT 1');
        $st->execute([$to, $from]);
        $any = $st->fetchColumn() !== false;
        $st->closeCursor();   // before the drain writes (see Db)
        return $any;
    }

    /**
     * Is any message key present for this direction, ignoring the counters?
     * The authoritative answer behind the cheap seq/ack gate when the counters
     * cannot be trusted (seq < ack, see hasAny). Costs a keyspace scan, hence
     * only on that rare desync - the normal gate is two O(1) reads.
     */
    private static function anyKey(string $to, string $from): bool
    {
        foreach (new APCUIterator('/^' . preg_quote(self::msgPrefix($to, $from), '/') . '/') as $e) {
            return true;
        }
        return false;
    }

    /**
     * Take everything pending for $to from $from, oldest first, exactly once.
     * created is whole seconds (the wire contract); age is ms the message
     * spent on the server before this delivery (see docs/API.md).
     * @return array<int, array{seq:int, payload:string, created:int, age:int}>
     */
    public static function drain(string $to, string $from): array
    {
        // Stored created is ms; the TTL is seconds, the wire 'created' seconds.
        $nowMs = self::nowMs();
        $cut = $nowMs - Settings::int('relay_ttl') * 1000;
        $out = [];
        if (self::usingApcu()) {
            $prefix = self::msgPrefix($to, $from);
            // Deliver the window (ack, hi]. The ack is how far this receiver has
            // already taken; hi is the high water mark, read BEFORE draining so
            // everything at or below it is accounted for afterwards - delivered
            // or expired - and acking to it also clears messages that died
            // untaken instead of leaving hasAny() true forever. Each message is
            // addressed by its seq (the key IS the zero-padded seq), so this
            // fetches only the handful actually pending - bounded by
            // relay_pending_cap, which the POST enforces - instead of scanning
            // the whole shared-memory keyspace on every single delivery.
            $lo = (int)apcu_fetch(self::ackKey($to, $from));
            $hi = (int)apcu_fetch(self::seqKey($to, $from));
            if ($hi < $lo) {
                // An evicted, re-seeded counter (see hasAny): the addressed
                // window is meaningless, so fall back to the authoritative scan.
                return self::drainScan($to, $from, $prefix, $cut, $nowMs);
            }
            $ackTo = $hi;
            for ($seq = $lo + 1; $seq <= $hi; $seq++) {
                $k = $prefix . sprintf('%012d', $seq);
                $v = apcu_fetch($k, $ok);
                if (!$ok) {
                    // A hole. push bumps the sequence (apcu_inc) a beat BEFORE
                    // it stores the message, so a concurrent drain can read the
                    // top seq for a message whose store has not landed yet:
                    // never ack past that top, or it would be skipped for good
                    // and lost. A hole BELOW the top is a permanent gap - a
                    // store that failed and was resent at a higher seq (see
                    // push), or an expired key - so ack past it and move on.
                    if ($seq === $hi) {
                        $ackTo = $hi - 1;
                    }
                    continue;
                }
                // apcu_delete wins for exactly one racing poll; the loser must
                // not deliver the same message again.
                if (!apcu_delete($k) || !is_array($v)) {
                    continue;
                }
                if ((int)$v['c'] < $cut) {
                    continue;   // past its TTL: drop, never deliver
                }
                $out[] = [
                    'seq' => $seq,
                    'payload' => (string)$v['p'],
                    'created' => intdiv((int)$v['c'], 1000),
                    'age' => max(0, $nowMs - (int)$v['c']),
                ];
            }
            apcu_store(self::ackKey($to, $from), $ackTo, 86400);
            return $out;
        }

        $db = Db::get();
        // One atomic statement, and the handle must not outlive it: this is a
        // WRITE and SQLite holds the write lock until the statement finishes
        // (see Db), so a handle kept across the hold loop would pin the
        // single writer for the whole long poll.
        $rows = Db::retry(static function () use ($db, $to, $from) {
            $st = $db->prepare(
                'DELETE FROM relay WHERE to_id = ? AND from_id = ? RETURNING id, payload, created'
            );
            $st->execute([$to, $from]);
            $r = $st->fetchAll();
            $st->closeCursor();
            return $r;
        });
        foreach ($rows as $r) {
            if ((int)$r['created'] < $cut) {
                continue;
            }
            $out[] = ['seq' => (int)$r['id'], 'payload' => (string)$r['payload'],
                'created' => intdiv((int)$r['created'], 1000),
                'age' => max(0, $nowMs - (int)$r['created'])];
        }
        usort($out, static fn(array $x, array $y) => $x['seq'] <=> $y['seq']);
        return $out;
    }

    /**
     * Authoritative fallback for drain() when the counters cannot be trusted
     * (seq < ack: an evicted, re-seeded counter - see hasAny). Scans the pair's
     * surviving message keys directly, delivers each exactly once, and realigns
     * the ack to the sequence so the cheap addressed path works again. Rare, and
     * the reason it is only a fallback: it costs a keyspace scan.
     * @return array<int, array{seq:int, payload:string, created:int, age:int}>
     */
    private static function drainScan(string $to, string $from, string $prefix, int $cut, int $nowMs): array
    {
        $out = [];
        $keys = [];
        foreach (new APCUIterator('/^' . preg_quote($prefix, '/') . '/') as $entry) {
            $keys[] = $entry['key'];
        }
        sort($keys);   // the seq is zero-padded, so this is numeric order
        foreach ($keys as $k) {
            $v = apcu_fetch($k, $ok);
            if (!$ok || !apcu_delete($k) || !is_array($v)) {
                continue;
            }
            if ((int)$v['c'] < $cut) {
                continue;
            }
            $out[] = [
                'seq' => (int)substr($k, strlen($prefix)),
                'payload' => (string)$v['p'],
                'created' => intdiv((int)$v['c'], 1000),
                'age' => max(0, $nowMs - (int)$v['c']),
            ];
        }
        apcu_store(self::ackKey($to, $from), (int)apcu_fetch(self::seqKey($to, $from)), 86400);
        return $out;
    }

    /**
     * Undelivered messages waiting for $to. With $from it is the backlog from
     * that ONE sender, and on APCu it is O(1): the gap between the sequence and
     * the receiver's ack for that direction - no per-message key scan. A
     * relayed receiver has a single sender (its duel peer), so that gap IS its
     * backlog, and the POST checks it on every message. Without $from it counts
     * across all senders (admin/rare). The gap can momentarily exceed the live
     * key count when a receiver has stopped draining (messages expire but the
     * ack stays put) - which is exactly the "receiver gone" case the cap is
     * there to catch, so counting the gap is if anything the truer signal.
     */
    public static function pending(string $to, ?string $from = null): int
    {
        if (self::usingApcu()) {
            if ($from !== null) {
                $gap = (int)apcu_fetch(self::seqKey($to, $from)) - (int)apcu_fetch(self::ackKey($to, $from));
                return $gap > 0 ? $gap : 0;
            }
            $n = 0;
            foreach (new APCUIterator('/^' . preg_quote(self::PREFIX . "$to:", '/') . '.*:m:/') as $e) {
                $n++;
            }
            return $n;
        }
        if ($from !== null) {
            $st = Db::get()->prepare('SELECT COUNT(*) FROM relay WHERE to_id = ? AND from_id = ?');
            $st->execute([$to, $from]);
        } else {
            $st = Db::get()->prepare('SELECT COUNT(*) FROM relay WHERE to_id = ?');
            $st->execute([$to]);
        }
        $n = (int)$st->fetchColumn();
        $st->closeCursor();
        return $n;
    }

    /**
     * Drop a pair's whole backlog, both directions (a 'bye'): an undelivered
     * input must never reach the pair's next duel.
     */
    public static function forgetPair(string $a, string $b): void
    {
        if (self::usingApcu()) {
            foreach ([[$a, $b], [$b, $a]] as [$to, $from]) {
                foreach (new APCUIterator('/^' . preg_quote(self::msgPrefix($to, $from), '/') . '/') as $e) {
                    apcu_delete($e['key']);
                }
                apcu_delete(self::seqKey($to, $from));
                apcu_delete(self::ackKey($to, $from));
            }
            // The pair's slot is released too: a rematch re-competes for
            // admission (see relay.php) rather than coasting on a stale marker.
            apcu_delete(self::admitKey($a, $b));
            return;
        }
        [$x, $y] = $a < $b ? [$a, $b] : [$b, $a];
        Db::get()->prepare('DELETE FROM relay WHERE pair = ?')->execute(["$x:$y"]);
    }

    /**
     * Bound the backlog table. Nothing to do on APCu, which expires entries
     * itself; on the database this is housekeeping only, because delivery
     * already refuses anything past its TTL - hence the sampling.
     */
    public static function sweep(int $now): void
    {
        if (self::usingApcu() || random_int(1, 50) !== 1) {
            return;
        }
        // created is stored in ms (see push); the cutoff must match.
        Db::get()->prepare('DELETE FROM relay WHERE created < ?')
            ->execute([($now - Settings::int('relay_ttl')) * 1000]);
    }
}
