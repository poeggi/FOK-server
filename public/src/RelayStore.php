<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Alerts.php';
require_once __DIR__ . '/Caps.php';
require_once __DIR__ . '/Util.php';

/**
 * DEPRECATED - part of the server-side relay fallback. See
 * docs/DEPRECATED-relay.md (delete manifest). Still live; no new callers.
 *
 * The relay hub's message store, in APCu shared memory.
 *
 * A relayed message is ephemeral by definition - worthless once delivered,
 * and dropped after relay_ttl either way - so a durable, transactional,
 * single-writer B-tree is the wrong medium for it. SQLite allows exactly ONE
 * writer for the whole database, which means two unrelated duels serialise
 * against each other and against every heartbeat and counter in the server.
 *
 * APCu is shared memory across the FPM workers of this pool: no global
 * writer, no transactions, no fsync, and an expiry the cache enforces
 * itself, so there is no TTL sweep either. It is the ONLY transport - the
 * database one is gone, so the relay never touches the SQLite writer at all.
 * A host without usable APCu is told so (503, see requireApcu) instead of
 * being served by a second implementation that would only move the outage
 * into the write lock; the signaling mailbox has taken the same line since
 * it moved to shared memory. The host's APCu is trusted to be shared across
 * the pool's workers; if it were per-worker instead, cross-worker messages
 * would be lost with no runtime signal (there is no proof of sharing, by
 * design).
 *
 * Exactly-once: each message is claimed with apcu_delete(), which only one
 * racing poll can win. Ordering is the server-assigned seq.
 */
final class RelayStore
{
    private const PREFIX = 'fok:rq:';

    /**
     * Shared memory is a hard requirement here, not a preference. Called once
     * at the top of the endpoint (api/relay.php), before any hub access: on a
     * host without usable APCu the relay cannot work, and answering 503 with
     * an alert is honest.
     *
     * The incidental callers OUTSIDE the endpoint - a bye tearing a pair down
     * (signal.php), the admin gauges - must not fail their own unrelated
     * request over it, so they consult apcuOk() and do nothing instead.
     */
    public static function requireApcu(): void
    {
        if (self::apcuOk()) {
            return;
        }
        Alerts::raise('perf', 'Relay unavailable: APCu is not usable on this host. '
            . 'The hub has no database transport by design - relayed duels stay '
            . 'down until shared memory works.');
        Util::fail('relay unavailable', 503);
    }

    /**
     * Is shared memory usable? Decided ONCE per request from the stored
     * capability assessment (see Caps); nothing here probes the host per
     * message. Public because the rate guard shares the store's verdict.
     */
    public static function apcuOk(): bool
    {
        static $ok = null;
        return $ok ??= Caps::apcu();
    }

    /**
     * Should the pair's conn liveness row be refreshed for THIS message?
     * The relay never touches the database except that marker
     * (Relay::markRelaying), which the admin cards and the duel cap read over
     * FOK_RELAY_WINDOW. Writing it per message would put the single SQLite
     * writer back on the hot path shared memory exists to clear, so it is
     * throttled to once per pair per FOK_RELAY_TRACK_THROTTLE, held in the
     * cache itself.
     */
    public static function shouldTrackRelay(string $from, string $to, int $now): bool
    {
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
     * evicted marker (see relay.php).
     */
    public static function admitted(string $a, string $b): bool
    {
        return apcu_fetch(self::admitKey($a, $b)) !== false;
    }

    /**
     * Mark the pair admitted, or refresh the marker's life. Called on every
     * relayed message so it lives as long as the duel does, while its TTL
     * (FOK_RELAY_WINDOW, the same window the slot is counted over) frees the
     * slot once the traffic stops.
     */
    public static function markAdmitted(string $a, string $b): void
    {
        apcu_store(self::admitKey($a, $b), 1, FOK_RELAY_WINDOW);
    }

    // ---- keys --------------------------------------------------------
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
     * the sender resends.
     *
     * The message is stamped in ms (created is stored ms, exposed as whole
     * seconds on delivery) so drain can report its age - see docs/API.md.
     */
    public static function push(string $from, string $to, string $payload): bool
    {
        $ttl = Settings::int('relay_ttl');
        $ms = self::nowMs();
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

    /**
     * Cheap "anything for me?" for the hold loop - it runs every
     * FOK_POLL_CHECK_USEC_APCU, so it must stay O(1): two shared-memory
     * reads, no scan. The sequence is the high water mark and the ack is how
     * far this receiver has got.
     */
    public static function hasAny(string $to, string $from): bool
    {
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
     * Undelivered messages waiting for $to from ONE sender, in O(1): the gap
     * between the sequence and the receiver's ack for that direction - no
     * per-message key scan. A relayed receiver has a single sender (its duel
     * peer), so that gap IS its backlog, and the POST checks it on every
     * message. The gap can momentarily exceed the live key count when a
     * receiver has stopped draining (messages expire but the ack stays put) -
     * which is exactly the "receiver gone" case the cap is there to catch, so
     * counting the gap is if anything the truer signal.
     */
    public static function pending(string $to, string $from): int
    {
        $gap = (int)apcu_fetch(self::seqKey($to, $from)) - (int)apcu_fetch(self::ackKey($to, $from));
        return $gap > 0 ? $gap : 0;
    }

    /**
     * Drop a pair's whole backlog, both directions (a 'bye'): an undelivered
     * input must never reach the pair's next duel. Called from signal.php, so
     * it stays silent on a host with no usable shared memory - nothing was
     * ever stored there (see requireApcu).
     */
    public static function forgetPair(string $a, string $b): void
    {
        if (!self::apcuOk()) {
            return;
        }
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
    }
}
