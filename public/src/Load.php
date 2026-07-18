<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Util.php';

/**
 * Live load gauges for the admin dashboard. Per-minute counters kept in a
 * small self-pruning table (loadmin), accumulated in memory during the
 * request and written ONCE, after the response, in a single statement -
 * the same one-write-lock discipline the rest of the hot path follows.
 *
 * "messages in" reuses the existing req_min request counter (Util::bump),
 * so it costs nothing extra here; this class adds "messages out" (what the
 * hub hands back to clients) and "db writes" (state-changing queries,
 * counted by the thin PDO wrapper below - see Db::get).
 *
 * The gauges are deliberately approximate: writes issued in the deferred
 * phase (bookkeeping) are not all counted, and a client id with no player
 * row is not chased down. A load meter only has to show the trend.
 */
final class Load
{
    /** @var array<string,int> metric => count accumulated this request */
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

    /** One statement for the whole minute; a rare prune keeps the table tiny. */
    public static function flush(): void
    {
        if (self::$pending === []) {
            return;
        }
        self::$flushing = true;
        try {
            $pending = self::$pending;
            self::$pending = [];
            $db = Db::get();
            $bucket = gmdate('YmdHi');
            $rows = [];
            $args = [];
            foreach ($pending as $metric => $n) {
                $rows[] = '(?, ?, ?)';
                $args[] = $bucket;
                $args[] = $metric;
                $args[] = $n;
            }
            $db->prepare(
                'INSERT INTO loadmin (bucket, metric, value) VALUES ' . implode(', ', $rows) .
                ' ON CONFLICT (bucket, metric) DO UPDATE SET value = value + excluded.value'
            )->execute($args);
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
