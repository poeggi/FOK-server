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
        if (self::$pending === []) {
            return;
        }
        $pending = self::$pending;
        self::$pending = [];
        self::$flushing = true;
        try {
            $db = Db::get();
            $bucket = gmdate('YmdHi');
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
     * a minute. "in" comes from the shared req_min request counter.
     * @return array{in:int,out:int,db_writes:int}
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
