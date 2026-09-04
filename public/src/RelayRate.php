<?php
declare(strict_types=1);

require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Alerts.php';
require_once __DIR__ . '/RelayStore.php';

/**
 * DEPRECATED - part of the server-side relay fallback. See
 * docs/DEPRECATED-relay.md (delete manifest). Still live; no new callers.
 *
 * Per-client relay send-rate guard, in the same shared memory the message
 * store uses (see RelayStore): a relayed message never takes the SQLite
 * writer, not for its payload and not for its rate accounting.
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
 * the same reasoning that moved the messages there.
 *
 * Enforcement is one cheap read (blocked()) at the top of the POST; the count
 * is deferred (record(), off the client's latency, like the other counters).
 * So a block lands within a message or two of the flood starting, never in the
 * caller's critical path. Both run behind the endpoint's RelayStore::
 * requireApcu(), so shared memory is known usable by the time they are called.
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
    // total and mark outlive a slice but not an idle hour; the block key
    // lives exactly as long as the block.
    private const STATE_TTL = 3600;

    private static function key(string $id, string $suffix): string
    {
        return self::PREFIX . $id . ':' . $suffix;
    }

    /** True while $id is serving a rate-limit block. One O(1) read. */
    public static function blocked(string $id): bool
    {
        $until = apcu_fetch(self::key($id, 'b'), $ok);
        return $ok && (int)$until > time();
    }

    /**
     * The running message total for $id (admin gauge, see ConnTrack::listDuels).
     * An admin read, not an endpoint one, so it answers 0 rather than failing
     * on a host without usable shared memory (see RelayStore::requireApcu).
     */
    public static function totalOf(string $id): int
    {
        if (!RelayStore::apcuOk()) {
            return 0;
        }
        return (int)apcu_fetch(self::key($id, 't'));
    }

    /**
     * Running total plus any active block, for the admin popup - or null when
     * the client has never sent a relayed message (nothing to show), which is
     * also the answer on a host with no usable shared memory.
     * @return array{total:int, blocked_until:int}|null
     */
    public static function detail(string $id): ?array
    {
        if (!RelayStore::apcuOk()) {
            return null;
        }
        $total = apcu_fetch(self::key($id, 't'), $seen);
        if (!$seen) {
            return null;
        }
        $until = apcu_fetch(self::key($id, 'b'), $blocked);
        return ['total' => (int)$total, 'blocked_until' => $blocked ? (int)$until : 0];
    }

    /**
     * Counts one relayed message from $id and, once a full slice has passed,
     * checks the rate over it. Deferred: never in the caller's latency (see
     * Util::defer).
     */
    public static function record(string $id): void
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
            // message, so the first full slice measures every message the
            // client sent in it.
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
}
