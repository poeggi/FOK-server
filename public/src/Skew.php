<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Caps.php';
require_once __DIR__ . '/Settings.php';

/**
 * The pair clock cross-check (API 4.4, see docs/API.md).
 *
 * A single client's clock proof is uninterpretable on its own. What arrives
 * at start.php is `pts + one-way delay + signed clock error`, and no server
 * can separate those three from one direction - which is why the sync gate in
 * start.php is deliberately gross, and why a client that anchored its clock
 * inside its own ICE burst passes it while carrying tens of milliseconds of
 * queue wait in its offset.
 *
 * But a PAIR proves two clocks against the SAME start. Both figures carry
 * their own delay and their own error; their DIFFERENCE cancels nothing and
 * proves nothing about either one alone, and yet it bounds how far apart the
 * two anchors can be - which is the only thing a duel actually needs, because
 * a duel is two clients agreeing on when a moment is. Neither client can see
 * this: each holds one half of it. The server holds both.
 *
 * So it is a HINT and never a refusal. Two healthy clients on very asymmetric
 * paths would fail this comparison honestly, and locking a real pair out of
 * its own match to protect it from a millisecond is the wrong trade. Past the
 * tolerance the answer says `resync: true` and the client re-anchors before
 * its next start, which it should be doing anyway.
 *
 * State lives in APCu with a short TTL and nowhere else. It is worthless
 * seconds after it is written, it belongs to one epoch of one pair, and a
 * `starts` column for it would have bought a migration for data that does not
 * outlive a handshake. No shared memory means no cross-check - the same
 * position as any other 4.4 hint: absent, not wrong.
 */
final class Skew
{
    private const PREFIX = 'fok:skew:';

    // Long enough that a peer arriving late for the same start is still
    // compared, short enough that the next epoch never sees the last one's
    // figure. A pair that halts and re-starts does so many seconds apart.
    private const TTL = 30;

    // How long a verdict waits for the caller it was not able to answer.
    // The first caller is already answered when the second one reveals the
    // disagreement, so its copy of the verdict has to sit somewhere until it
    // asks for something; the next start is usually seconds away.
    private const WANT_TTL = 120;

    /**
     * Files this caller's figure for (pair, epoch) and returns true when the
     * pair's two figures disagree by more than the tolerance.
     *
     * $delta is `server receive time - reported pts`: one-way delay plus
     * signed clock error, meaningless alone and comparable in pairs.
     *
     * The first caller of an epoch always returns false - there is nothing to
     * compare it against yet, and it is answered before its peer arrives. The
     * second caller does the comparison and, when it fails, leaves the verdict
     * for the first one to collect (see wanted()).
     */
    public static function note(string $id, string $peer, int $epoch, int $delta): bool
    {
        $tol = Settings::int('start_pair_skew_ms');
        if ($tol <= 0 || Caps::apcu() !== true) {
            return false;
        }
        $key = self::key($id, $peer, $epoch);
        // apcu_add is the whole of the race handling: exactly one of the two
        // callers wins the slot and becomes the first, whichever order they
        // actually arrive in. A second call from the SAME client (a retry)
        // finds its own id in the slot and is not compared against itself.
        if (apcu_add($key, [$id, $delta], self::TTL)) {
            return false;
        }
        $held = apcu_fetch($key);
        if (!is_array($held) || count($held) !== 2 || $held[0] === $id) {
            return false;
        }
        if (abs($delta - (int)$held[1]) <= $tol) {
            return false;
        }
        // Both sides need to re-anchor: the comparison says they disagree,
        // never which of them is wrong.
        apcu_store(self::PREFIX . 'w:' . $held[0], 1, self::WANT_TTL);
        return true;
    }

    /** Takes the pending verdict for one client, if it has one. */
    public static function wanted(string $id): bool
    {
        if (Caps::apcu() !== true) {
            return false;
        }
        $key = self::PREFIX . 'w:' . $id;
        if (apcu_fetch($key) === false) {
            return false;
        }
        // Delivered once. A client that ignores the hint is not nagged, and a
        // client that honours it has re-anchored by its next start.
        apcu_delete($key);
        return true;
    }

    private static function key(string $id, string $peer, int $epoch): string
    {
        [$a, $b] = $id < $peer ? [$id, $peer] : [$peer, $id];
        return self::PREFIX . "$a:$b:$epoch";
    }
}
