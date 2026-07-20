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
        if (Caps::apcu()) {
            return self::$apcu = true;
        }
        // Asked for, not available: say so where an operator will see it.
        // Alerts de-duplicates per type within alert_cooldown, so a busy
        // server raises this once, not once per message.
        Alerts::raise('perf', 'Relay fell back to the database: APCu was requested '
            . '(relay_apcu=1) but is not usable on this host. Expect SQLite write '
            . 'contention and dropped messages under relayed play.');
        return self::$apcu = false;
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

    /** Enqueue one message from $from to $to. */
    public static function push(string $from, string $to, string $payload, int $now): void
    {
        $ttl = Settings::int('relay_ttl');
        if (self::usingApcu()) {
            // The sequence outlives the messages on purpose: it must keep
            // increasing while a duel runs, and the messages under it expire
            // on their own after relay_ttl.
            $seq = apcu_inc(self::seqKey($to, $from), 1, $ok, 86400);
            apcu_store(
                self::msgPrefix($to, $from) . sprintf('%012d', $seq),
                ['p' => $payload, 'c' => $now],
                $ttl
            );
            return;
        }
        $db = Db::get();
        [$a, $b] = $from < $to ? [$from, $to] : [$to, $from];
        Db::retry(static fn() => $db->prepare(
            'INSERT INTO relay (pair, from_id, to_id, payload, created) VALUES (?, ?, ?, ?, ?)'
        )->execute(["$a:$b", $from, $to, $payload, $now]));
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
