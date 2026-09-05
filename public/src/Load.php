<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Util.php';

/**
 * Live load gauges for the admin dashboard: "messages out" (hub deliveries)
 * and "db writes" (counted by the thin PDO wrapper below), accumulated in
 * memory during the request and handed to the shared-memory counter buffer
 * once, after the response. They ride the same fold every request counter
 * uses (see Counters), so a gauge costs no database write of its own and is
 * exact rather than sampled. "messages in" needs nothing here: it is the
 * request count the same buffer already keeps.
 */
final class Load
{
    /** @var array<string,int> metric => count accumulated this request (summed) */
    private static array $pending = [];
    private static bool $registered = false;
    // While the monitoring writes ITSELF down, nothing it does is counted.
    private static bool $untracked = false;

    // What THIS request has cost, for the per-script view: every query it
    // issued and the CPU it burned. Read once, after the response, by
    // Counters::cost - these two measure the request itself rather than
    // gauge the server, so they never go through the pending/flush path.
    private static int $queries = 0;
    private static ?float $cpu0 = null;

    public static function tick(string $metric, int $n = 1): void
    {
        if ($n <= 0 || self::$untracked) {
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

    /**
     * A query taken off the PDO wrapper. Every one of them counts towards
     * what this request cost the database (the per-script DB column); only
     * writes count towards the db_w gauge, which is about the single writer
     * and not about reads.
     */
    public static function noteQuery(string $sql): void
    {
        if (self::$untracked) {
            return;
        }
        self::$queries++;
        $verb = strtoupper(substr(ltrim($sql), 0, 3));
        if ($verb === 'INS' || $verb === 'UPD' || $verb === 'DEL' || $verb === 'REP'
            || $verb === 'CRE' || $verb === 'ALT' || $verb === 'DRO') {
            self::tick('db_w');
        }
    }

    /** Database queries this request has issued so far (see noteQuery). */
    public static function queries(): int
    {
        return self::$queries;
    }

    /**
     * Runs the monitoring's own database write without counting it. Once a
     * minute the counter buffer folds itself into the database (see
     * Counters::flushMinute), and whichever request happens to carry that
     * fold did not cause it: counted, it would show up as one random request
     * an endpoint made expensive, and the db-writes gauge would be partly a
     * count of itself.
     */
    public static function untracked(callable $fn): void
    {
        self::$untracked = true;
        try {
            $fn();
        } finally {
            self::$untracked = false;
        }
    }

    /**
     * The connection is up: what the request costs the database starts here.
     * Opening it issues four PRAGMAs and reads the schema version, and a
     * migration step issues however many it takes - none of which any
     * endpoint asked for, all of which every endpoint pays identically. Left
     * in the count they would be five sixths of what a cheap request appears
     * to cost the database, and the per-script DB column would be a request
     * count wearing another name (see Db::get).
     */
    public static function openDone(): void
    {
        self::$queries = 0;
    }

    /**
     * Baseline for cpuMs(). getrusage() reports the whole PROCESS, and one
     * FPM worker serves thousands of requests, so a per-request figure can
     * only be a delta - taken when the database is opened (Db::get), which
     * is the first shared work of every request.
     */
    public static function markStart(): void
    {
        self::$cpu0 ??= self::cpu();
    }

    /**
     * CPU milliseconds burned since markStart(): what the request actually
     * spent on a core, as opposed to how long it held its worker. A parked
     * long poll holds a slot for seconds and burns a millisecond, and only
     * the two numbers together say which kind a script is. 0 on a host that
     * will not report usage.
     */
    public static function cpuMs(): int
    {
        return self::$cpu0 === null ? 0 : (int)round((self::cpu() - self::$cpu0) * 1000);
    }

    /** User plus system time of this process, in seconds. */
    private static function cpu(): float
    {
        if (!function_exists('getrusage')) {
            return 0.0;
        }
        $r = getrusage();
        return (float)($r['ru_utime.tv_sec'] ?? 0) + (float)($r['ru_utime.tv_usec'] ?? 0) / 1e6
            + (float)($r['ru_stime.tv_sec'] ?? 0) + (float)($r['ru_stime.tv_usec'] ?? 0) / 1e6;
    }

    /**
     * Hands this request's gauge counts to the shared-memory buffer, once,
     * after the response. Writing a row per request to record that the
     * request wrote rows made the monitoring itself a leading source of load
     * on the single SQLite writer - on the relay path it doubled the write
     * transactions a game message costs. Now it costs no write at all: the
     * buffer folds a whole minute into one statement whoever it is that
     * carries it (see Counters::flushMinute).
     */
    public static function flush(): void
    {
        if (self::$pending === []) {
            return;
        }
        $pending = self::$pending;
        self::$pending = [];
        require_once __DIR__ . '/Counters.php';
        foreach ($pending as $metric => $n) {
            Counters::add('n:' . $metric, $n);
        }
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

    /** The other direct path: a one-shot read that skips prepare(). */
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false
    {
        Load::noteQuery($query);
        return parent::query($query, $fetchMode, ...$args);
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
