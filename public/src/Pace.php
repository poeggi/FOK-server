<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Holds.php';

/**
 * The one pacing decision only the server can make (API 4.4, hello `pace`):
 * whether this client may hold a long poll right now.
 *
 * The beat itself - the heartbeat, the poll wait, the gap between a client's
 * own requests - is a set of constants in docs/API.md (Pacing) and is not
 * handed over. Nothing about it follows load: a steady heartbeat is no load
 * at all on this host, and it is how a player stays online. What costs is a
 * worker doing nothing, and the one value here answers to exactly that.
 *
 * A held long poll occupies an FPM worker for its whole wait while doing
 * nothing, on a host that serves about twenty concurrent PHP requests; one
 * lobby of eight can take half the pool by waiting. Holds already refuses a
 * hold when the budget is spent, and that is the right behaviour at the
 * wall. This is what the server says BEFORE the wall - and it withdraws the
 * privilege in tier order, so what gives way first is the client that is
 * only browsing.
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
    public const TIER_LOBBY = 0;
    public const TIER_TOURNEY = 1;
    public const TIER_DUEL = 2;

    /**
     * The pacing block for one client.
     *
     * @return array{hold:bool}
     */
    public static function forTier(int $tier): array
    {
        // Withdrawn by tier as the pool fills, so a duel handshake outlives
        // a lobby's patience.
        return ['hold' => self::pressure() <= $tier];
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
