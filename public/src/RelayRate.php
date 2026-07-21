<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Alerts.php';
require_once __DIR__ . '/RelayStore.php';

/**
 * Per-client relay send-rate guard, with two transports behind one interface -
 * the SAME choice the message store makes (RelayStore::usingApcu), so a relay
 * running on shared memory keeps its rate-limiting there too and never touches
 * the SQLite writer on the hot path.
 *
 * relay.php cannot rate-limit off the relay backlog itself - a receiver drains
 * it on delivery, so a fast pair leaves nothing to count. Instead we keep a
 * running TOTAL of messages a client has pushed and, once a full timeslice
 * (> 1 s) has elapsed, look at the INCREASE over that slice. A client
 * sustaining more than relay_rate_max messages a second is blocked for
 * relay_rate_block_secs.
 *
 * This is an APPROXIMATE counter, not a ledger: exactly-once is not needed and
 * a count lost to eviction only gives a flooder a moment's slack, so APCu's
 * apcu_inc (no writer, no transaction, self-expiring) is the right medium -
 * the same reasoning that moved the messages there. On APCu the enforcement
 * read (blocked) and the count (record) are shared-memory ops, so a relayed
 * message no longer takes the database at all for rate-limiting.
 *
 * Enforcement is one cheap read (blocked()) at the top of the POST; the count
 * is deferred (record(), off the client's latency, like the other counters).
 * So a block lands within a message or two of the flood starting, never in the
 * caller's critical path.
 */
final class RelayRate
{
    // The window the rate is measured over. Kept above 1 s so a single
    // sub-second burst (a batched flush) cannot trip it - only a rate
    // SUSTAINED across more than a second does.
    private const SLICE = 2;

    // APCu keys, one set per client. total counts up (apcu_inc); mark is the
    // (total, time) the current slice started from; block, when present, holds
    // the moment the block ends and carries a TTL so it clears itself.
    private const PREFIX = 'fok:rr:';
    // total and mark outlive a slice but not an idle hour (mirrors the DB
    // prune); the block key lives exactly as long as the block.
    private const STATE_TTL = 3600;

    /** Which transport rate-limiting uses - the message store decides. */
    public static function usesApcu(): bool
    {
        return RelayStore::usingApcu();
    }

    private static function key(string $id, string $suffix): string
    {
        return self::PREFIX . $id . ':' . $suffix;
    }

    /** True while $id is serving a rate-limit block. One O(1) read. */
    public static function blocked(string $id): bool
    {
        if (self::usesApcu()) {
            $until = apcu_fetch(self::key($id, 'b'), $ok);
            return $ok && (int)$until > time();
        }
        $st = Db::get()->prepare('SELECT blocked_until FROM relay_rate WHERE id = ?');
        $st->execute([$id]);
        $until = (int)$st->fetchColumn();
        $st->closeCursor();
        return $until > time();
    }

    /** The running message total for $id (admin gauge, see ConnTrack::listDuels). */
    public static function totalOf(string $id): int
    {
        if (self::usesApcu()) {
            return (int)apcu_fetch(self::key($id, 't'));
        }
        $st = Db::get()->prepare('SELECT total FROM relay_rate WHERE id = ?');
        $st->execute([$id]);
        $total = (int)$st->fetchColumn();
        $st->closeCursor();
        return $total;
    }

    /**
     * Counts one relayed message from $id and, once a full slice has passed,
     * checks the rate over it. Deferred: never in the caller's latency (see
     * Util::defer).
     */
    public static function record(string $id): void
    {
        if (self::usesApcu()) {
            self::recordApcu($id);
            return;
        }
        self::recordDb($id);
    }

    private static function recordApcu(string $id): void
    {
        $now = time();
        $total = apcu_inc(self::key($id, 't'), 1, $ok, self::STATE_TTL);
        if ($total === false) {
            // Lost the increment (a full or racing cache): seed it and move on.
            $total = 1;
            apcu_store(self::key($id, 't'), 1, self::STATE_TTL);
        }
        $mark = apcu_fetch(self::key($id, 'm'), $ok);
        if (!$ok || !is_array($mark)) {
            // First sighting: mark the slice start at the count BEFORE this
            // message (like the database path), so the first full slice
            // measures every message the client sent in it.
            apcu_store(self::key($id, 'm'), ['t' => $total - 1, 's' => $now], self::STATE_TTL);
            return;
        }
        $elapsed = $now - (int)$mark['s'];
        if ($elapsed < self::SLICE) {
            return;   // still inside the slice; wait for a full window
        }
        $rate = ($total - (int)$mark['t']) / $elapsed;
        if ($rate > Settings::int('relay_rate_max')) {
            $block = Settings::int('relay_rate_block_secs');
            // The TTL clears the block on its own; the stored value lets
            // blocked() double-check against the clock.
            apcu_store(self::key($id, 'b'), $now + $block, $block);
            Alerts::raise('spam', sprintf(
                'Relay flood: client %s at %.0f msg/s over %d s; blocked %d s',
                $id, $rate, $elapsed, $block
            ));
        }
        apcu_store(self::key($id, 'm'), ['t' => $total, 's' => $now], self::STATE_TTL);
    }

    private static function recordDb(string $id): void
    {
        $db = Db::get();
        $now = time();
        $st = $db->prepare('SELECT total, mark_total, mark_time, blocked_until FROM relay_rate WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        $st->closeCursor();

        $total = ($row ? (int)$row['total'] : 0) + 1;
        $markTotal = $row ? (int)$row['mark_total'] : $total - 1;
        $markTime = $row ? (int)$row['mark_time'] : $now;
        $blockedUntil = $row ? (int)$row['blocked_until'] : 0;

        $elapsed = $now - $markTime;
        if ($elapsed >= self::SLICE) {
            $rate = ($total - $markTotal) / $elapsed;
            if ($rate > Settings::int('relay_rate_max')) {
                $blockedUntil = $now + Settings::int('relay_rate_block_secs');
                Alerts::raise('spam', sprintf(
                    'Relay flood: client %s at %.0f msg/s over %d s; blocked %d s',
                    $id, $rate, $elapsed, Settings::int('relay_rate_block_secs')
                ));
            }
            $markTotal = $total;
            $markTime = $now;
        }

        $db->prepare(
            'INSERT INTO relay_rate (id, total, mark_total, mark_time, blocked_until)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (id) DO UPDATE SET total = excluded.total,
                 mark_total = excluded.mark_total, mark_time = excluded.mark_time,
                 blocked_until = excluded.blocked_until'
        )->execute([$id, $total, $markTotal, $markTime, $blockedUntil]);

        // Bound the table: a client idle for an hour and not serving a
        // block is forgotten. Swept only when a NEW client appears, so it
        // is not on every message. (APCu expires its own keys - STATE_TTL.)
        if ($row === false) {
            $db->prepare('DELETE FROM relay_rate WHERE mark_time < ? AND blocked_until < ?')
                ->execute([$now - self::STATE_TTL, $now]);
        }
    }
}
