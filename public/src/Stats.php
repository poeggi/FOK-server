<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';

/**
 * Lifetime totals: the handful of numbers that outlive the thing they count.
 *
 * Tournament state itself is disposable and lives in shared memory (see
 * TourneyStore), but "how many tournaments has this server ever run" cannot be
 * recomputed from anything once that state is gone. These are the counters
 * worth a durable row, and they are deliberately few.
 *
 * They share the existing counters table under one fixed bucket. The metric
 * is incremented BY AN AMOUNT rather than by one, which is what keeps the
 * cost at a single write per tournament: a finished bracket knows how many
 * matches it played and reports the whole total in one statement instead of
 * writing a row per match.
 *
 * Never authoritative for anything - no gameplay, ownership or score decision
 * reads these. They exist to be looked at.
 */
final class Stats
{
    /**
     * Sorts below every YmdH traffic bucket, so a reader scanning the last 24
     * hours of load never picks these up (see AdminData::stats).
     */
    public const BUCKET = '0total';

    /**
     * Adds to one or more lifetime counters in a single statement.
     * Best-effort: a total that fails to record must never fail the request
     * that was actually being served.
     *
     * @param array<string, int> $metrics metric name => amount to add
     */
    public static function bump(array $metrics): void
    {
        try {
            Db::retry(static function () use ($metrics): void {
                self::bumpIn(Db::get(), $metrics);
            });
        } catch (Throwable $e) {
            error_log('FOK stats: ' . $e->getMessage());
        }
    }

    /**
     * The same write, INSIDE a transaction the caller already holds. A
     * counter that belongs to something being written anyway rides along
     * with it instead of taking the single writer a second time right after
     * it was released (see Starts::request).
     *
     * No retry and no catch here on purpose: both belong to the caller's
     * transaction, and swallowing an error would leave it half-applied.
     *
     * @param array<string, int> $metrics metric name => amount to add
     */
    public static function bumpIn(PDO $db, array $metrics): void
    {
        $metrics = array_filter($metrics, static fn(int $n): bool => $n > 0);
        if ($metrics === []) {
            return;
        }
        $rows = [];
        $args = [];
        foreach ($metrics as $metric => $n) {
            $rows[] = '(?, ?, ?)';
            $args[] = self::BUCKET;
            $args[] = $metric;
            $args[] = $n;
        }
        $db->prepare(
            'INSERT INTO counters (bucket, metric, value) VALUES ' . implode(', ', $rows) .
            ' ON CONFLICT (bucket, metric) DO UPDATE SET value = value + excluded.value'
        )->execute($args);
    }

    /**
     * Every lifetime counter, metric => value.
     *
     * @return array<string, int>
     */
    public static function all(): array
    {
        $st = Db::get()->prepare('SELECT metric, value FROM counters WHERE bucket = ? ORDER BY metric');
        $st->execute([self::BUCKET]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[(string)$r['metric']] = (int)$r['value'];
        }
        return $out;
    }
}
