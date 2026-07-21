<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Util.php';

/**
 * Live load gauges for the admin dashboard: per-minute counters in a small
 * self-pruning table (loadmin), accumulated in memory and written ONCE after
 * the response, in one statement. "messages in" reuses the req_min counter
 * (Util::bump); this adds "messages out" (hub deliveries) and "db writes"
 * (counted by the thin PDO wrapper below). Deliberately approximate - a load
 * meter only shows the trend.
 */
final class Load
{
    /** @var array<string,int> metric => count accumulated this request (summed) */
    private static array $pending = [];
    /** @var array<string,int> metric => max seen this request (peak, not summed) */
    private static array $peak = [];
    private static bool $registered = false;
    // While the flush runs, its OWN writes must not count themselves.
    private static bool $flushing = false;

    public static function tick(string $metric, int $n = 1): void
    {
        if ($n <= 0 || self::$flushing) {
            return;
        }
        self::$pending[$metric] = (self::$pending[$metric] ?? 0) + $n;
        self::arm();
    }

    /**
     * A gauge kept as the MAX over the minute, not a running total - for
     * "worst relay age this minute" and the like. Flushed unsampled (sampling
     * would miss the very peaks it exists to show).
     */
    public static function peak(string $metric, int $value): void
    {
        if ($value <= 0 || self::$flushing) {
            return;
        }
        self::$peak[$metric] = max(self::$peak[$metric] ?? 0, $value);
        self::arm();
    }

    private static function arm(): void
    {
        if (!self::$registered) {
            self::$registered = true;
            Util::defer([self::class, 'flush']);
        }
    }

    /** A query taken off the PDO wrapper: only writes count as db load. */
    public static function noteQuery(string $sql): void
    {
        if (self::$flushing) {
            return;
        }
        $verb = strtoupper(substr(ltrim($sql), 0, 3));
        if ($verb === 'INS' || $verb === 'UPD' || $verb === 'DEL' || $verb === 'REP'
            || $verb === 'CRE' || $verb === 'ALT' || $verb === 'DRO') {
            self::tick('db_w');
        }
    }

    /** One statement per aggregation for the minute; a rare prune keeps the
     *  table tiny. */
    public static function flush(): void
    {
        if (self::$pending === [] && self::$peak === []) {
            return;
        }
        $pending = self::$pending;
        $peak = self::$peak;
        self::$pending = [];
        self::$peak = [];
        self::$flushing = true;
        try {
            $db = Db::get();
            $bucket = gmdate('YmdHi');
            // Peak gauges (MAX over the minute) must be written every time:
            // sampling would miss the very peaks they exist to report.
            if ($peak !== []) {
                $rows = [];
                $args = [];
                foreach ($peak as $metric => $v) {
                    $rows[] = '(?, ?, ?)';
                    $args[] = $bucket;
                    $args[] = $metric;
                    $args[] = $v;
                }
                $db->prepare(
                    'INSERT INTO loadmin (bucket, metric, value) VALUES ' . implode(', ', $rows) .
                    ' ON CONFLICT (bucket, metric) DO UPDATE SET value = MAX(value, excluded.value)'
                )->execute($args);
            }
            // Sum counters: writing a row per request to record that the
            // request wrote rows made the monitoring itself a leading source of
            // load on the single SQLite writer - on the relay path it doubled
            // the write transactions a game message costs. One request in
            // load_sample flushes and counts for all of them, so the trend
            // survives at a fraction of the lock traffic; these gauges are
            // explicitly approximate. Set load_sample to 1 for exact figures.
            $sample = max(1, Settings::int('load_sample'));
            if ($pending !== [] && ($sample === 1 || random_int(1, $sample) === 1)) {
                $rows = [];
                $args = [];
                foreach ($pending as $metric => $n) {
                    $rows[] = '(?, ?, ?)';
                    $args[] = $bucket;
                    $args[] = $metric;
                    $args[] = $n * $sample;   // stands in for $sample requests
                }
                $db->prepare(
                    'INSERT INTO loadmin (bucket, metric, value) VALUES ' . implode(', ', $rows) .
                    ' ON CONFLICT (bucket, metric) DO UPDATE SET value = value + excluded.value'
                )->execute($args);
            }
            // A couple of minutes is all a live gauge needs. Prune only in the
            // first seconds of a minute so almost every flush stays one write.
            if (time() % 60 < 3) {
                $db->prepare('DELETE FROM loadmin WHERE bucket < ?')
                    ->execute([gmdate('YmdHi', time() - 180)]);
            }
        } finally {
            self::$flushing = false;
        }
    }

    /**
     * The last COMPLETE minute's totals - a true 60 s figure that steps once
     * a minute. "in" comes from the shared req_min request counter;
     * relay_age_ms is a peak (the worst hub message age delivered), not a sum.
     * @return array{in:int,out:int,db_writes:int,relay_age_ms:int}
     */
    public static function lastMinute(): array
    {
        $prev = gmdate('YmdHi', time() - 60);
        $db = Db::get();
        $st = $db->prepare('SELECT metric, value FROM loadmin WHERE bucket = ?');
        $st->execute([$prev]);
        $m = [];
        foreach ($st->fetchAll() as $r) {
            $m[$r['metric']] = (int)$r['value'];
        }
        $rq = $db->prepare("SELECT value FROM counters WHERE bucket = ? AND metric = 'req_min'");
        $rq->execute([$prev]);
        return [
            'in' => (int)$rq->fetchColumn(),
            'out' => $m['msg_out'] ?? 0,
            'db_writes' => $m['db_w'] ?? 0,
            'relay_age_ms' => $m['relay_age_ms'] ?? 0,
        ];
    }
}

/** Counts write queries issued through exec() (DDL, batch deletes). */
final class LoadPDO extends PDO
{
    public function exec(string $statement): int|false
    {
        Load::noteQuery($statement);
        return parent::exec($statement);
    }
}

/** Counts write queries issued through prepared statements (the common path). */
final class LoadStatement extends PDOStatement
{
    protected function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        Load::noteQuery($this->queryString);
        return parent::execute($params);
    }
}
