<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Caps.php';
require_once __DIR__ . '/Settings.php';

/**
 * How many FPM workers the long polls may hold at once.
 *
 * A held request (poll.php, relay.php) occupies a worker for its whole wait
 * while doing nothing at all. That is the point of it, and it is also the one
 * resource this deployment genuinely runs out of: the host serves about twenty
 * concurrent PHP requests, and the caps that sit around the holds
 * (relay_max_duels, tournament_max_players) are each sized against that pool
 * on their own. Nothing stopped their sum from taking all of it - and when it
 * is taken, ordinary requests (a hello, a score, a tournament state read)
 * queue behind requests that are deliberately idle.
 *
 * So the holds share one budget. A request that cannot get a slot does not
 * queue and does not fail: it answers at once with the same "nothing pending"
 * it would have answered with after waiting, and the client polls again. That
 * caller trades handshake latency for the pool staying answerable, which is
 * the right way round - a slow handshake is a slow handshake, a full pool is
 * every player's request timing out at once.
 *
 * Slots are APCu keys rather than one counter, because a counter can only be
 * decremented by the process that incremented it: a worker killed mid-hold
 * would leak its share of the budget for good. A slot key carries a TTL, so
 * the worst a lost release costs is one slot for a few seconds.
 */
final class Holds
{
    private const PREFIX = 'fok:hold:';

    // The safety net for a hold whose worker never runs its release. Above the
    // longest hold there can be (FOK_POLL_WAIT_MAX) by enough margin that a
    // live hold is never evicted out from under itself.
    private const SLOT_TTL = FOK_POLL_WAIT_MAX + 6;

    private static ?int $slot = null;
    private static int $token = 0;

    /**
     * Takes a slot for a hold about to start. False means the budget is spent
     * and the caller must answer now instead of waiting.
     */
    public static function claim(): bool
    {
        if (self::$slot !== null) {
            return true;
        }
        $budget = Settings::int('hold_max_workers');
        // Without shared memory there is no pool-wide view to budget against,
        // and guessing would refuse holds on a host that has room for them.
        // Hold as before; 0 is the operator's own off switch.
        if ($budget <= 0 || Caps::apcu() !== true) {
            return true;
        }
        // The token is what makes the release safe: the slots are a fixed set
        // of keys, so the only way to know one is still ours is to have put a
        // value in it that nobody else would write.
        $token = random_int(1, PHP_INT_MAX);
        for ($i = 0; $i < $budget; $i++) {
            if (apcu_add(self::PREFIX . $i, $token, self::SLOT_TTL) !== true) {
                continue;
            }
            self::$slot = $i;
            self::$token = $token;
            // Not released at the foot of the endpoint's loop: a hold leaves
            // that loop through exit() or jsonOut(), so a slot handed back
            // only where control falls through would leak on every ordinary
            // path. Shutdown functions run for all of them.
            register_shutdown_function(static fn() => self::release());
            return true;
        }
        return false;
    }

    /** Hands the slot back. Safe to call twice, and safe to call unclaimed. */
    public static function release(): void
    {
        if (self::$slot === null) {
            return;
        }
        $key = self::PREFIX . self::$slot;
        self::$slot = null;
        // Only if it is still ours. A hold that somehow outlived SLOT_TTL has
        // already had its slot expire and handed on, and deleting it then
        // would put two requests in one slot.
        if (apcu_fetch($key) === self::$token) {
            apcu_delete($key);
        }
    }

    /** Slots taken right now - what the saturation alert reads (Util::watch). */
    public static function inUse(): int
    {
        if (Caps::apcu() !== true) {
            return 0;
        }
        return iterator_count(new APCUIterator('/^' . preg_quote(self::PREFIX, '/') . '/'));
    }
}
