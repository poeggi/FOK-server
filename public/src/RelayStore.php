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
            // Asked for, not available: say so where an operator will see it.
            // Alerts de-duplicates per type within alert_cooldown, so a busy
            // server raises this once, not once per message.
            Alerts::raise('perf', 'Relay fell back to the database: APCu was requested '
                . '(relay_apcu=1) but is not usable on this host. Expect SQLite write '
                . 'contention and dropped messages under relayed play.');
            return self::$apcu = false;
        }
        // Enabled is not enough. A per-worker APCu segment would take a
        // message from the sender's worker that the receiver's worker never
        // sees - silent loss. Use shared memory only once it is PROVEN shared
        // across workers; until then the database transport carries the relay
        // (slower, but never lossy). No alert: this is the normal warm-up
        // right after a deploy and clears itself within a few requests (see
        // Caps::apcuShared); the Performance tab's relay row shows the state.
        if (!Caps::apcuShared()) {
            return self::$apcu = false;
        }
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

    /**
     * Enqueue one message from $from to $to. Returns false only when the APCu
     * store is full and the message did NOT go in: a game input is never
     * silently dropped - the caller turns a false into a retryable failure so
     * the sender resends. The database transport enqueues durably and always
     * returns true (a hard failure throws through Db::retry).
     */
    public static function push(string $from, string $to, string $payload, int $now): bool
    {
        $ttl = Settings::int('relay_ttl');
        if (self::usingApcu()) {
            // The sequence outlives the messages on purpose: it must keep
            // increasing while a duel runs, and the messages under it expire
            // on their own after relay_ttl.
            $seq = apcu_inc(self::seqKey($to, $from), 1, $ok, 86400);
            $stored = apcu_store(
                self::msgPrefix($to, $from) . sprintf('%012d', $seq),
                ['p' => $payload, 'c' => $now],
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
        )->execute(["$a:$b", $from, $to, $payload, $now]));
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
            return (int)apcu_fetch(self::seqKey($to, $from)) > (int)apcu_fetch(self::ackKey($to, $from));
        }
        $st = Db::get()->prepare('SELECT 1 FROM relay WHERE to_id = ? AND from_id = ? LIMIT 1');
        $st->execute([$to, $from]);
        $any = $st->fetchColumn() !== false;
        $st->closeCursor();   // before the drain writes (see Db)
        return $any;
    }

    /**
     * Take everything pending for $to from $from, oldest first, exactly once.
     * @return array<int, array{seq:int, payload:string, created:int}>
     */
    public static function drain(string $to, string $from): array
    {
        $cut = time() - Settings::int('relay_ttl');
        $out = [];
        if (self::usingApcu()) {
            $prefix = self::msgPrefix($to, $from);
            // Read the high water mark BEFORE draining: everything at or
            // below it is accounted for afterwards, delivered or expired, so
            // acking to it also clears messages that died untaken instead of
            // leaving hasAny() permanently true.
            $hi = (int)apcu_fetch(self::seqKey($to, $from));
            $keys = [];
            foreach (new APCUIterator('/^' . preg_quote($prefix, '/') . '/') as $entry) {
                $keys[] = $entry['key'];
            }
            sort($keys);   // the seq is zero-padded, so this is numeric order
            foreach ($keys as $k) {
                $v = apcu_fetch($k, $ok);
                // apcu_delete wins for exactly one racing poll; the loser
                // must not deliver the same message again.
                if (!$ok || !apcu_delete($k) || !is_array($v)) {
                    continue;
                }
                if ((int)$v['c'] < $cut) {
                    continue;   // past its TTL: drop, never deliver
                }
                $out[] = [
                    'seq' => (int)substr($k, strlen($prefix)),
                    'payload' => (string)$v['p'],
                    'created' => (int)$v['c'],
                ];
            }
            apcu_store(self::ackKey($to, $from), $hi, 86400);
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
                'created' => (int)$r['created']];
        }
        usort($out, static fn(array $x, array $y) => $x['seq'] <=> $y['seq']);
        return $out;
    }

    /** Undelivered messages waiting for $to, from anyone (the backlog cap). */
    public static function pending(string $to): int
    {
        if (self::usingApcu()) {
            $n = 0;
            foreach (new APCUIterator('/^' . preg_quote(self::PREFIX . "$to:", '/') . '.*:m:/') as $e) {
                $n++;
            }
            return $n;
        }
        $st = Db::get()->prepare('SELECT COUNT(*) FROM relay WHERE to_id = ?');
        $st->execute([$to]);
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
        Db::get()->prepare('DELETE FROM relay WHERE created < ?')
            ->execute([$now - Settings::int('relay_ttl')]);
    }
}
