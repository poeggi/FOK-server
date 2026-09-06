<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Holds.php';

/**
 * How fast the server wants a client to talk to it (API 4.4, hello `pace`).
 *
 * Every periodic interval in this system used to be a client-side constant,
 * which put the one decision that depends on server load in the one place
 * that cannot see it. A client cannot know that eleven other clients just
 * opened a tournament lobby; the server cannot know it either, until the
 * requests are already queued behind each other. So the server states the
 * beat and the client follows it - additive, ignorable, and worth nothing at
 * all until enough clients honour it, which is exactly why it ships before it
 * pays.
 *
 * The lever that matters is `hold`, not the intervals. A held long poll
 * occupies an FPM worker for its whole wait while doing nothing, on a host
 * that serves about twenty concurrent PHP requests; one lobby of eight can
 * take half the pool by waiting. Holds already refuses a hold when the budget
 * is spent (see Holds), and that is the right behaviour at the wall. This is
 * what the server says BEFORE the wall - and it withdraws the privilege in
 * tier order, so what gives way first is the client that is only browsing.
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
    // A mistuned setting must not become a flood. Nothing sensible asks a
    // client to speak more often than this, and the ceiling is the operator's
    // (pace_hello_max_ms) - this end is only a backstop against a zero.
    private const FLOOR_MS = 5000;

    public const TIER_LOBBY = 0;
    public const TIER_TOURNEY = 1;
    public const TIER_DUEL = 2;

    /**
     * The pacing advice for one client.
     *
     * @return array{hello_ms:int, poll_ms:int, hold:bool, spread_ms:int}
     */
    public static function forTier(int $tier): array
    {
        $pressure = self::pressure();
        $hello = Settings::int('pace_hello_ms');
        $ceiling = Settings::int('pace_hello_max_ms');

        // Stretching the heartbeat is the gentle half: it buys back one
        // request per client per interval and costs only how quickly the
        // server notices somebody left. A client in a duel is never stretched
        // - its heartbeat is how the server knows the game is still running.
        if ($pressure > $tier && $tier < self::TIER_DUEL) {
            $hello = (int)round($hello * (1 + 0.5 * ($pressure - $tier)));
        }
        // Both ends, always: a ceiling or a paced-out client reads as offline
        // before it speaks again, a floor or a zeroed setting floods the host.
        $hello = min(max($hello, self::FLOOR_MS), max($ceiling, self::FLOOR_MS));

        return [
            'hello_ms' => $hello,
            'poll_ms' => FOK_POLL_WAIT_MAX * 1000,
            // The real lever. Withdrawn by tier as the pool fills, so a duel
            // handshake outlives a lobby's patience.
            'hold' => $pressure <= $tier,
            // Drawn ONCE per session by the client, not per request - the
            // point is to separate clients from each other permanently, not
            // to smear each client's own requests.
            'spread_ms' => Settings::int('pace_spread_ms'),
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
