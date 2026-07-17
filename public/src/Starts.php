<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Util.php';

/**
 * Server-issued level starts. The server owns the PTS clock, so it also
 * owns the absolute moment a level begins: both peers of a pair request
 * a start and receive the IDENTICAL start PTS. The lead time scales
 * with the players' reported latencies so the answer always arrives
 * before the moment it announces.
 */
final class Starts
{
    public static function request(string $id, string $peer): int
    {
        [$a, $b] = $id < $peer ? [$id, $peer] : [$peer, $id];
        $db = Db::get();
        $now = Util::nowMs();
        $db->exec('BEGIN IMMEDIATE');
        try {
            $db->prepare('DELETE FROM starts WHERE start_pts < ?')->execute([$now - 300000]);

            $st = $db->prepare('SELECT start_pts FROM starts WHERE a = ? AND b = ?');
            $st->execute([$a, $b]);
            $pending = $st->fetchColumn();
            if ($pending !== false && (int)$pending > $now) {
                $db->exec('COMMIT');
                return (int)$pending;
            }

            $st = $db->prepare("SELECT MAX(COALESCE(latency, 100)) FROM players WHERE id IN (?, ?)");
            $st->execute([$a, $b]);
            $worstLatency = (int)$st->fetchColumn();
            $lead = max(Settings::int('start_lead_min_ms'), 150 + 2 * $worstLatency);
            $startPts = $now + min($lead, 3000);

            $db->prepare(
                'INSERT INTO starts (a, b, start_pts, created) VALUES (?, ?, ?, ?)
                 ON CONFLICT (a, b) DO UPDATE SET start_pts = excluded.start_pts,
                     created = excluded.created'
            )->execute([$a, $b, $startPts, $now]);
            $db->exec('COMMIT');
            return $startPts;
        } catch (Throwable $e) {
            // SQLite auto-rolls back on some faults; a bare ROLLBACK
            // would then throw and mask the real error.
            if ($db->inTransaction()) {
                $db->exec('ROLLBACK');
            }
            throw $e;
        }
    }
}
