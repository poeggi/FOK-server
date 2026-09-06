<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Caps.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/ConnTrack.php';
require_once __DIR__ . '/RelayStore.php';
require_once __DIR__ . '/RelayRate.php';

/**
 * DEPRECATED - the single boundary of the server-side relay fallback.
 * See docs/DEPRECATED-relay.md for the removal plan and delete manifest.
 *
 * Everything OUTSIDE the relay's own files (relay.php, RelayStore, RelayRate)
 * that needs a relay concept goes through this facade and nothing else:
 * signal.php (capacity gate, pair teardown, the accept peer-net guard),
 * ConnTrack::listDuels (the per-duel message count) and AdminData (the
 * Relaying tile and the per-client rate detail). ConnTrack itself no longer
 * carries any relay method - the slot accounting that used to live there
 * (it reads and writes the relay_seen field of a tracked connection) moved
 * here so the whole mechanism deletes with this file.
 *
 * The relay slot is still recorded on the tracked connection itself, as the
 * relay_seen stamp of the ConnTrack entry, because a slot must outlive the
 * request that earned it and be visible to the admin cards. It is what holds
 * that entry's TTL up to FOK_RELAY_WINDOW. The residual touches left in
 * ConnTrack (the relay_seen = 0 on a bye, the field in the entry shape, and
 * the TTL) are the only relay references that stay behind, all flagged
 * there. When the relay goes, delete this class, RelayStore, RelayRate and
 * relay.php, remove the marked call sites, and relay_seen leaves the entry
 * shape with them (see the manifest).
 */
final class Relay
{
    /**
     * Real traffic through the hub - the only writer of relay_seen, so a
     * relay slot always costs hub traffic and never a client's mere claim to
     * be relaying (accept-relay is not friendship-gated, so claims are free
     * and a few would otherwise deny the relay to everyone). Writes only the
     * sender's entry: one store on a hot path. (Was ConnTrack::relaying.)
     */
    public static function markRelaying(string $from, string $to): void
    {
        if (!Caps::apcu()) {
            return;
        }
        $now = time();
        apcu_store(ConnTrack::key($from), [
            'peer' => $to,
            'state' => 'playing',
            'mode' => 'relay',
            'updated' => $now,
            'relay_seen' => $now,
        ], ConnTrack::TTL);
    }

    /**
     * The pair gave its slot up (a bye, see ConnTrack::end). The entry is
     * already zeroed by the caller; what is left is the write throttle that
     * guards it, which must go with it - see RelayStore::clearTrack.
     */
    public static function slotFreed(string $from, string $to): void
    {
        RelayStore::clearTrack($from, $to);
    }

    /**
     * Does this pair already hold a relay admission slot? Asked before the
     * relay-duel cap, so an admitted duel is never rejected mid-game.
     */
    public static function isRelaying(string $a, string $b): bool
    {
        $fresh = time() - FOK_RELAY_WINDOW;
        foreach ([[$a, $b], [$b, $a]] as [$id, $peer]) {
            $e = ConnTrack::stateOf($id);
            if ($e !== null && $e['peer'] === $peer && $e['relay_seen'] > $fresh) {
                return true;
            }
        }
        return false;
    }

    /**
     * Pairs running through the hub. Counted from relay_seen, not from queued
     * relay messages: those are deleted as the receiver drains them, so a
     * healthy duel would count as zero and the cap would protect nothing.
     * Either side's stamp is enough, so the two directions of one pair are
     * folded onto a single unordered key. (Was ConnTrack::relayPairs.)
     */
    public static function activePairs(): int
    {
        $fresh = time() - FOK_RELAY_WINDOW;
        $pairs = [];
        foreach (ConnTrack::entries() as $id => $e) {
            if ($e['peer'] === null || $e['relay_seen'] <= $fresh) {
                continue;
            }
            $pairs[$id < $e['peer'] ? $id . ':' . $e['peer'] : $e['peer'] . ':' . $id] = true;
        }
        return count($pairs);
    }

    /**
     * Is the concurrent-duel cap reached for a pair that does not already
     * hold a slot? The authoritative slot record (isRelaying, an entry read)
     * is consulted FIRST, so an APCu admission-marker eviction can never cut
     * off a live duel - only a genuinely new pair pays the count and is
     * subject to the cap. Both the signal-time declaration gate and the first
     * relayed message ask this; each raises its own alert and answers 503
     * itself.
     */
    public static function capReached(string $a, string $b): bool
    {
        return !self::isRelaying($a, $b)
            && self::activePairs() >= Settings::int('relay_max_duels');
    }

    /**
     * Has the pairing $id had with $peer been explicitly torn down? A bye or
     * decline marks both sides' entries 'ended'/'declined'. A relayed peer
     * holding a GET checks this so it learns the other side left AT ONCE,
     * instead of waiting out its own liveness timeout - the relay's answer to
     * a P2P DataChannel close. Only an explicit teardown counts; a silent drop
     * (tab closed, no bye) is left to that timeout. Live hub traffic from
     * EITHER side means the pair is playing (again), so a stale 'ended' entry
     * from a previous match must not be read as a leave and kill the fresh one
     * - a bye zeroes relay_seen (see ConnTrack::end), so this is false exactly
     * when the teardown is real. (Was ConnTrack::peerLeft.)
     */
    public static function peerLeft(string $id, string $peer): bool
    {
        if (self::isRelaying($id, $peer)) {
            return false;
        }
        $e = ConnTrack::stateOf($id);
        return $e !== null && $e['peer'] === $peer
            && ($e['state'] === 'ended' || $e['state'] === 'declined');
    }

    /**
     * A pairing ended (a bye): drop its whole relay backlog, both directions,
     * so an undelivered input can never reach the pair's next duel.
     */
    public static function pairEnded(string $a, string $b): void
    {
        RelayStore::forgetPair($a, $b);
    }

    /**
     * The running relayed-message count for one client (the admin Duels
     * card's Msgs column). The rate guard holds the total in the hub's own
     * shared memory (see RelayRate).
     */
    public static function msgsFor(string $id): int
    {
        return RelayRate::totalOf($id);
    }

    /**
     * The per-client rate-limiter detail for the admin popup (running total
     * and any active block), or null if the client has never relayed. The
     * guard keeps it in shared memory; there is no relay_rate table any more.
     */
    public static function rateDetail(string $id): ?array
    {
        return RelayRate::detail($id);
    }
}
