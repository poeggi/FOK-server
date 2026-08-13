<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';

/**
 * Per-player self-reported gameplay stats: one row of cumulative counters per
 * id (games played, levels cleared, furthest level, deaths, duels and duels
 * won, total playtime). A client saves its running totals here and reads them
 * back to restore progress on a new device (see docs/API.md, api/stats.php).
 *
 * These are CLIENT-ASSERTED - the server has no authority over them - so they
 * are stored MONOTONICALLY: a submitted value never lowers the stored one, so
 * a stale or replaying device cannot roll the totals back, and each field is
 * hard-capped. They are vanity / progress figures, not a trust signal; an open
 * id-keyed write (an id is known to a duel peer) can only raise a value up to
 * its cap, never lower or corrupt it. Kept apart from the opaque config backup
 * (Vault), which the server never parses - these are parsed so the dashboard
 * can show them.
 */
final class PStats
{
    // Wire/column field => hard cap. best_level tops out at the score level
    // range (99); the rest are large but bounded (see Config).
    private const CAPS = [
        'games'        => FOK_PSTATS_COUNT_MAX,
        'levels'       => FOK_PSTATS_COUNT_MAX,
        'best_level'   => 99,
        'deaths'       => FOK_PSTATS_COUNT_MAX,
        'duels'        => FOK_PSTATS_COUNT_MAX,
        'duels_won'    => FOK_PSTATS_COUNT_MAX,
        'play_seconds' => FOK_PSTATS_SECONDS_MAX,
    ];

    /** Zeroed stats - the shape returned for an id with nothing stored yet. */
    public static function zero(): array
    {
        $z = [];
        foreach (self::CAPS as $f => $_) {
            $z[$f] = 0;
        }
        $z['updated'] = 0;
        return $z;
    }

    /** Current stored stats for an id (zeros if none). */
    public static function get(string $id): array
    {
        return self::fetch($id) ?? self::zero();
    }

    /**
     * Merges a client's reported counters into the stored row, monotonically
     * (never lowering a value) and capped. Only the fields present in $in are
     * considered; the rest keep their stored value, and a malformed field is
     * ignored, not fatal. Returns the resulting stats. A row already written
     * within FOK_PSTATS_WRITE_THROTTLE seconds is NOT rewritten - the merged
     * values are returned but persistence waits for the next submission, which
     * recomputes from the client's (cumulative) totals - so a chatty or abusive
     * client cannot hammer the single writer.
     * @param array<string,mixed> $in
     */
    public static function submit(string $id, array $in): array
    {
        $cur = self::fetch($id);
        $merged = $cur ?? self::zero();
        $grew = false;
        foreach (self::CAPS as $f => $cap) {
            if (!array_key_exists($f, $in)) {
                continue;
            }
            $v = $in[$f];
            if (!is_int($v) || $v < 0) {
                continue;
            }
            if ($v > $cap) {
                $v = $cap;
            }
            if ($v > $merged[$f]) {
                $merged[$f] = $v;
                $grew = true;
            }
        }
        $now = time();
        if (!$grew || ($cur !== null && $now - $cur['updated'] < FOK_PSTATS_WRITE_THROTTLE)) {
            return $merged;
        }
        $merged['updated'] = $now;
        Db::get()->prepare(
            'INSERT INTO pstats
                (id, games, levels, best_level, deaths, duels, duels_won, play_seconds, updated)
             VALUES
                (:id, :games, :levels, :best_level, :deaths, :duels, :duels_won, :play_seconds, :updated)
             ON CONFLICT (id) DO UPDATE SET
                games = excluded.games, levels = excluded.levels,
                best_level = excluded.best_level, deaths = excluded.deaths,
                duels = excluded.duels, duels_won = excluded.duels_won,
                play_seconds = excluded.play_seconds, updated = excluded.updated'
        )->execute([
            ':id' => $id,
            ':games' => $merged['games'],
            ':levels' => $merged['levels'],
            ':best_level' => $merged['best_level'],
            ':deaths' => $merged['deaths'],
            ':duels' => $merged['duels'],
            ':duels_won' => $merged['duels_won'],
            ':play_seconds' => $merged['play_seconds'],
            ':updated' => $now,
        ]);
        return $merged;
    }

    /** @return array<string,int>|null */
    private static function fetch(string $id): ?array
    {
        $st = Db::get()->prepare(
            'SELECT games, levels, best_level, deaths, duels, duels_won, play_seconds, updated
             FROM pstats WHERE id = ?'
        );
        $st->execute([$id]);
        $row = $st->fetch();
        $st->closeCursor();
        if ($row === false) {
            return null;
        }
        foreach ($row as $k => $v) {
            $row[$k] = (int)$v;
        }
        return $row;
    }
}
