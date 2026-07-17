<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Util.php';

/**
 * Server-issued starts. The server owns the PTS clock, so it owns every
 * moment play begins or resumes: the first start, the next level, a
 * respawn after a death and a resume from pause all halt the run, and
 * the moment it picks back up comes from here - never from whichever
 * peer happened to notice first.
 *
 * Both peers NAME the start they mean with a shared epoch, so the answer
 * does not depend on WHEN either of them asks. A peer that asks late
 * gets the same PTS, already in the past, and knows exactly how late it
 * is. Keyed by pair alone it would instead have raced the very moment it
 * was asking about and been handed a different start, with both players
 * then running from different origins and nothing reporting it.
 *
 * The epoch is game state, not a server invention: deterministic lockstep
 * means both peers count the halts identically, so they arrive at the
 * same number without anyone being authoritative. Peers that disagree get
 * a stale-epoch rejection rather than a quiet desync.
 */
final class Starts
{
    // Every one of these halts or restarts the run. Recorded per pair for
    // the admin view; the lead time does not depend on which it is.
    public const REASONS = ['first', 'level', 'respawn', 'resume', 'rematch'];

    // A pair's start is forgotten this long after it passed. Only the
    // stale-epoch guard depends on the row, and a peer that far behind is
    // gone, not late.
    private const KEEP_MS = 300000;

    /**
     * Ends the pair's epoch line. The epoch counts halts WITHIN one
     * connection, so it has to reset when the connection does - otherwise
     * the pair's next duel would open at epoch 0 and be refused as stale
     * forever. Pair-scoped like everything bye touches: bye is not
     * friendship-gated, so a stranger must not reach a duel it is not in.
     */
    public static function forget(string $id, string $peer): void
    {
        [$a, $b] = $id < $peer ? [$id, $peer] : [$peer, $id];
        Db::get()->prepare('DELETE FROM starts WHERE a = ? AND b = ?')->execute([$a, $b]);
    }

    /**
     * The pair's start PTS for $epoch: issued on first request, repeated
     * verbatim to the second peer. Returns null if the pair has already
     * moved PAST $epoch, which means the caller is behind and must not be
     * handed a start at all (the endpoint answers 409).
     */
    public static function request(string $id, string $peer, int $epoch, string $reason): ?int
    {
        [$a, $b] = $id < $peer ? [$id, $peer] : [$peer, $id];
        $db = Db::get();
        $now = Util::nowMs();
        $db->exec('BEGIN IMMEDIATE');
        try {
            $db->prepare('DELETE FROM starts WHERE start_pts < ?')->execute([$now - self::KEEP_MS]);

            $st = $db->prepare('SELECT epoch, start_pts FROM starts WHERE a = ? AND b = ?');
            $st->execute([$a, $b]);
            $row = $st->fetch();
            if ($row !== false) {
                $stored = (int)$row['epoch'];
                // Identical answer however late this peer is: the whole
                // point of naming the epoch.
                if ($stored === $epoch) {
                    $db->exec('COMMIT');
                    return (int)$row['start_pts'];
                }
                if ($stored > $epoch) {
                    $db->exec('COMMIT');
                    return null;
                }
            }

            // The answer must arrive before the moment it announces, so the
            // lead covers the slower peer's round trip.
            $st = $db->prepare('SELECT MAX(COALESCE(latency, 100)) FROM players WHERE id IN (?, ?)');
            $st->execute([$a, $b]);
            $worstLatency = (int)$st->fetchColumn();
            $lead = max(Settings::int('start_lead_min_ms'), 150 + 2 * $worstLatency);
            $startPts = $now + min($lead, 3000);

            $db->prepare(
                'INSERT INTO starts (a, b, start_pts, created, epoch, reason)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON CONFLICT (a, b) DO UPDATE SET start_pts = excluded.start_pts,
                     created = excluded.created, epoch = excluded.epoch,
                     reason = excluded.reason'
            )->execute([$a, $b, $startPts, $now, $epoch, $reason]);
            $db->exec('COMMIT');
            return $startPts;
        } catch (Throwable $e) {
            // SQLite auto-rolls back on some faults; a bare ROLLBACK would
            // then throw and mask the real error.
            if ($db->inTransaction()) {
                $db->exec('ROLLBACK');
            }
            throw $e;
        }
    }
}
