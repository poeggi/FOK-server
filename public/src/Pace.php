<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Holds.php';

/**
 * The beat the server hands a client (API 4.4, hello `pace`).
 *
 * Every periodic interval in this system used to be a client-side constant,
 * so changing one meant a client release. The server states them instead:
 * the heartbeat, the poll wait and the spacing between a client's own
 * requests are operator settings handed over on every hello, the same for
 * every client. Additive and ignorable - a client that never reads the block
 * behaves exactly as it did before it existed.
 *
 * None of those intervals follows load, on purpose. A steady heartbeat is
 * no load at all on this host, and it is how a player stays online: asking
 * for it less often buys nothing measurable and costs a presence dot that
 * flickers. What costs is a worker doing nothing, and the one value here
 * that answers to load is the one about that.
 *
 * `hold` is that lever. A held long poll occupies an FPM worker for its whole
 * wait while doing nothing, on a host that serves about twenty concurrent PHP
 * requests; one lobby of eight can take half the pool by waiting. Holds
 * already refuses a hold when the budget is spent, and that is the right
 * behaviour at the wall. This is what the server says BEFORE the wall - and
 * it withdraws the privilege in tier order, so what gives way first is the
 * client that is only browsing.
 *
 * Tiers, in the order they lose their hold:
 *   0  lobby - online, nothing pending. Cheapest to disappoint: it is
 *      waiting for something that has not happened yet.
 *   1  a tournament screen with a match pending. It is waiting on the
 *      server, but it can afford to ask again.
 *   2  in a duel, or reconnecting. Its handshake IS the latency the player
 *      feels; it keeps the hold until the pool is genuinely out.
 */
final class Pace
{
    // Backstops against a mistuned setting, not tuning. Below the floor a
    // heartbeat is a flood; above the online window every client reads as
    // offline between its own beats.
    private const FLOOR_MS = 5000;
    private const CEIL_MS = FOK_ONLINE_WINDOW * 1000;

    // A mistuned gap must not stall a client's background work outright. The
    // gap delays only what the client itself calls background, and this is
    // the backstop against a setting that forgets that.
    private const GAP_MAX_MS = 2000;

    public const TIER_LOBBY = 0;
    public const TIER_TOURNEY = 1;
    public const TIER_DUEL = 2;

    /**
     * The pacing block for one client.
     *
     * @return array{hello_ms:int, poll_ms:int, hold:bool, gap_ms:int}
     */
    public static function forTier(int $tier): array
    {
        $gap = Settings::int('pace_gap_ms');
        return [
            'hello_ms' => min(max(Settings::int('pace_hello_ms'), self::FLOOR_MS), self::CEIL_MS),
            'poll_ms' => FOK_POLL_WAIT_MAX * 1000,
            // The lever. Withdrawn by tier as the pool fills, so a duel
            // handshake outlives a lobby's patience.
            'hold' => self::pressure() <= $tier,
            'gap_ms' => $gap <= 0 ? 0 : min($gap, self::GAP_MAX_MS),
        ];
    }

    /**
     * How close the hold budget is to spent: 0 calm, 1 warm, 2 hot.
     *
     * Measured against the budget rather than against the worker pool,
     * because the budget is the part of the pool this can actually give back.
     * With no budget (0 = the operator's off switch) or no shared memory to
     * count slots in, there is nothing to measure and nothing to ask for.
     */
    private static function pressure(): int
    {
        $budget = Settings::int('hold_max_workers');
        if ($budget <= 0) {
            return 0;
        }
        // Three quarters spent is hot, half is warm. Deliberately early: the
        // point of saying it is to be heard before the budget runs out, and
        // Holds is what happens when it does anyway.
        $used = Holds::inUse();
        if ($used * 4 >= $budget * 3) {
            return 2;
        }
        return $used * 2 >= $budget ? 1 : 0;
    }
}
