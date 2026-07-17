<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Alerts.php';

/**
 * Per-client relay send-rate guard. relay.php cannot rate-limit off the
 * relay table itself - a receiver drains it on delivery, so a fast pair
 * leaves no rows to count. Instead we keep a running TOTAL of messages a
 * client has pushed and, once a full timeslice (> 1 s) has elapsed, look
 * at the INCREASE over that slice. A client sustaining more than
 * relay_rate_max messages a second is blocked for relay_rate_block_secs.
 *
 * The count is a deferred write (record(), off the client's latency, like
 * the other counters); enforcement is one cheap indexed read (blocked())
 * at the top of the POST. So a block lands within a message or two of the
 * flood starting, never in the caller's critical path.
 */
final class RelayRate
{
    // The window the rate is measured over. Kept above 1 s so a single
    // sub-second burst (a batched flush) cannot trip it - only a rate
    // SUSTAINED across more than a second does.
    private const SLICE = 2;

    /** True while $id is serving a rate-limit block. One indexed read. */
    public static function blocked(string $id): bool
    {
        $st = Db::get()->prepare('SELECT blocked_until FROM relay_rate WHERE id = ?');
        $st->execute([$id]);
        return (int)$st->fetchColumn() > time();
    }

    /**
     * Counts one relayed message from $id and, once a full slice has
     * passed, checks the rate over it. Deferred: never in the caller's
     * latency (see Util::defer).
     */
    public static function record(string $id): void
    {
        $db = Db::get();
        $now = time();
        $st = $db->prepare('SELECT total, mark_total, mark_time, blocked_until FROM relay_rate WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();

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
        // is not on every message.
        if ($row === false) {
            $db->prepare('DELETE FROM relay_rate WHERE mark_time < ? AND blocked_until < ?')
                ->execute([$now - 3600, $now]);
        }
    }
}
