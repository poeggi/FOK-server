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

    // What the database cost this request in microseconds, split into taking
    // the single writer and running the statements themselves (see noteTime).
    // Sum and count make the mean, the maximum is the worst one case a mean
    // would hide, and $slowest is the one access worth naming.
    private static int $lockUs = 0;
    private static int $lockN = 0;
    private static int $lockMax = 0;
    private static int $sqlUs = 0;
    private static int $sqlN = 0;
    private static int $sqlMax = 0;
    /** @var array{us:int, sql:string, lock:bool}|null */
    private static ?array $slowest = null;

    // Under this, a statement is not worth naming in the worst list (the
    // same floor Counters::worst applies to a queue wait).
    private const SLOW_FLOOR_US = 1000;

    // The transaction currently open: who opened it, and how many bytes the
    // write-ahead log held at the moment the writer was taken. Both are read
    // on BEGIN IMMEDIATE and spent on COMMIT, which are the rare statements
    // by construction (see txLabel).
    private static ?string $txWho = null;
    private static int $txWal = -1;

    /**
     * What one page costs the write-ahead log: the page itself plus a
     * 24-byte frame header. Db never issues PRAGMA page_size, so the page is
     * SQLite's default and this is fixed for the life of the file.
     */
    private const WAL_FRAME = 4096 + 24;

    // Longest transaction name a COMMIT label carries, chosen so the whole
    // label stays inside shortSql's 40 characters with the page count on it.
    private const TX_WHO_MAX = 24;

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

    /**
     * How long one database access took, in microseconds.
     *
     * A statement that does nothing but TAKE the single writer - BEGIN
     * IMMEDIATE - is booked as a lock wait, because waiting is all it does.
     * Everything else is booked as access duration. SQLite does not report
     * how long its busy handler slept inside an ordinary statement, so a
     * bare write that waited counts the wait as part of its duration; the
     * explicit acquisitions are where a real stall shows and they are what
     * every contended path takes (Friends, Items, Starts, Db::tryWrite).
     *
     * The slowest access of the request is remembered rather than filed
     * here: naming one costs a shared-memory read and write, and a request
     * that issues twenty statements must not pay that twenty times (see
     * flush).
     */
    public static function noteTime(string $sql, int $us): void
    {
        if (self::$untracked || $us < 0) {
            return;
        }
        $head = ltrim($sql);
        $lock = stripos($head, 'BEGIN IMMEDIATE') === 0;
        $name = null;
        if ($lock) {
            self::$lockUs += $us;
            self::$lockN++;
            self::$lockMax = max(self::$lockMax, $us);
            self::txOpened();
        } else {
            self::$sqlUs += $us;
            self::$sqlN++;
            self::$sqlMax = max(self::$sqlMax, $us);
            if (stripos($head, 'COMMIT') === 0) {
                $name = self::txLabel();
            } elseif (stripos($head, 'ROLLBACK') === 0) {
                self::$txWho = null;
                self::$txWal = -1;
            }
        }
        if ($us >= self::SLOW_FLOOR_US && $us > (int)(self::$slowest['us'] ?? 0)) {
            self::$slowest = [
                'us' => $us,
                'sql' => self::shortSql($name ?? $sql),
                'lock' => $lock,
            ];
        }
        self::arm();
    }

    /** Remembers who took the writer and what the log looked like then. */
    private static function txOpened(): void
    {
        self::$txWho = self::txCaller();
        self::$txWal = self::walBytes();
    }

    /**
     * A COMMIT as the worst list should show it: which transaction it was,
     * and what it actually wrote. Both are the questions a bare "COMMIT" row
     * cannot answer - every contended path ends in one, so the string alone
     * says nothing about which of them was slow or why.
     *
     * The page count is the write-ahead log's growth over the transaction,
     * in frames. It is exact rather than sampled: SQLite has exactly ONE
     * writer, this transaction held it from the BEGIN IMMEDIATE that
     * measured the first size to here, so nothing else can have appended in
     * between.
     *
     * A log that SHRANK is the reading worth having. It means the commit
     * crossed wal_autocheckpoint and paid for the checkpoint SQLite charges
     * to whichever write happens to cross the line - so it is named 'ckpt'
     * rather than counted, and a slow COMMIT is explained on sight instead
     * of guessed at.
     */
    private static function txLabel(): string
    {
        $who = self::$txWho;
        $was = self::$txWal;
        self::$txWho = null;
        self::$txWal = -1;
        $out = 'COMMIT';
        if ($who !== null) {
            // The CALLER is what gets cut when a name is long, never the
            // tail: shortSql trims from the right, and the pages are the
            // half of this label that cannot be guessed from anywhere else.
            $out .= ' ' . (strlen($who) > self::TX_WHO_MAX
                ? substr($who, 0, self::TX_WHO_MAX - 1) . '~' : $who);
        }
        $now = $was < 0 ? -1 : self::walBytes();
        if ($now >= 0) {
            $out .= $now < $was ? ' ckpt' : ' ' . intdiv($now - $was, self::WAL_FRAME) . 'p';
        }
        return $out;
    }

    /**
     * Which code opened this transaction, as Class::method. Read off the
     * stack rather than passed in by the dozen call sites, so it cannot
     * drift out of step with them - and the plumbing every transaction goes
     * through is skipped, which is what turns a housekeeping COMMIT into the
     * task that asked for it instead of into Db::tryWrite. A frame with no
     * class is the script's own top level and is skipped with it.
     */
    private static function txCaller(): ?string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8) as $f) {
            $cls = (string)($f['class'] ?? '');
            if ($cls === '' || $cls === self::class || $cls === 'LoadPDO' || $cls === 'Db') {
                continue;
            }
            return $cls . '::' . (string)($f['function'] ?? '?');
        }
        return null;
    }

    /**
     * The write-ahead log's size in bytes, or -1 when there is none to read.
     * A stat, and only on the two statements that bracket a transaction -
     * the file is not opened and nothing is parsed.
     */
    private static function walBytes(): int
    {
        $wal = FOK_DB_FILE . '-wal';
        clearstatcache(true, $wal);
        $n = @filesize($wal);
        return $n === false ? -1 : $n;
    }

    /**
     * A statement as the worst list shows it: the first few words, which is
     * the verb and the table. The whole text can be a page of SQL and the
     * column is one line, and anything past the table name says nothing an
     * operator reading a slow access needs.
     */
    private static function shortSql(string $sql): string
    {
        $flat = preg_replace('/\s+/', ' ', trim($sql)) ?? '';
        return strlen($flat) > 40 ? substr($flat, 0, 39) . '...' : $flat;
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
        // The same argument for the timings: five PRAGMAs and a schema read
        // that every request pays identically would BE the access-time mean.
        // What opening costs has its own reading on the Performance card.
        self::$lockUs = self::$lockN = self::$lockMax = 0;
        self::$sqlUs = self::$sqlN = self::$sqlMax = 0;
        self::$slowest = null;
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

    /**
     * How long this request waited for a free PHP-FPM worker, in
     * microseconds, or null where that wait cannot be seen.
     *
     * PHP cannot time this itself: the waiting is over before the first line
     * of it runs, and nothing in $_SERVER remembers it. Apache does - it
     * stamps the arrival time into X-Request-Start (see public/.htaccess) -
     * and REQUEST_TIME_FLOAT is when a worker finally picked the request up,
     * so the difference between them is the queue.
     *
     * It is THE saturation signal. Worker occupancy says how close to the
     * pool's ceiling the server runs; this says whether it hit it. Close to
     * zero is healthy, and a sustained reading in the hundreds of
     * milliseconds means requests are lining up behind workers that are all
     * busy - which no endpoint's own timings can show, because a request
     * that is still queued is not yet running any of them.
     *
     * Null rather than zero when the header is absent: php -S ignores
     * .htaccess and a host without mod_headers sets nothing, and a run of
     * zeroes would read as a server with no queue instead of one that is not
     * measuring the queue at all.
     */
    public static function queueUs(): ?int
    {
        // Apache writes it as "t=<microseconds>"; take the digits, nothing else.
        if (preg_match('/(\d{10,})/', (string)($_SERVER['HTTP_X_REQUEST_START'] ?? ''), $m) !== 1) {
            return null;
        }
        $start = (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? 0);
        if ($start <= 0.0) {
            return null;
        }
        // Never negative. Both stamps come off the same machine's clock, but
        // rounding one against the other must not read as a worker that
        // started before the request arrived.
        return max(0, (int)round($start * 1e6 - (float)$m[1]));
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
        require_once __DIR__ . '/Counters.php';
        $pending = self::$pending;
        self::$pending = [];
        foreach ($pending as $metric => $n) {
            Counters::add('n:' . $metric, $n);
        }
        self::flushDbTime();
    }

    /**
     * What the database cost, booked in the shapes every other gauge uses: a
     * sum and a count that divide into the mean, a maximum beside them, and
     * the one slowest access named in the standing worst list. All of it
     * once per request, all of it in shared memory.
     */
    private static function flushDbTime(): void
    {
        if (self::$lockN > 0) {
            Counters::add('n:dbw_us', self::$lockUs);
            Counters::add('n:dbw_n', self::$lockN);
            Counters::max('dbw_us', self::$lockMax);
        }
        if (self::$sqlN > 0) {
            Counters::add('n:dbt_us', self::$sqlUs);
            Counters::add('n:dbt_n', self::$sqlN);
            Counters::max('dbt_us', self::$sqlMax);
        }
        if (self::$slowest !== null) {
            Counters::worst('db_us', self::$slowest['us'], Util::who() + [
                'q' => self::$slowest['sql'],
                'lk' => self::$slowest['lock'] ? 1 : 0,
            ]);
        }
        self::$lockUs = self::$lockN = self::$lockMax = 0;
        self::$sqlUs = self::$sqlN = self::$sqlMax = 0;
        self::$slowest = null;
    }
}

/** Counts write queries issued through exec() (DDL, batch deletes). */
final class LoadPDO extends PDO
{
    public function exec(string $statement): int|false
    {
        Load::noteQuery($statement);
        $t = microtime(true);
        try {
            return parent::exec($statement);
        } finally {
            Load::noteTime($statement, (int)round((microtime(true) - $t) * 1e6));
        }
    }

    /** The other direct path: a one-shot read that skips prepare(). */
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false
    {
        Load::noteQuery($query);
        $t = microtime(true);
        try {
            return parent::query($query, $fetchMode, ...$args);
        } finally {
            Load::noteTime($query, (int)round((microtime(true) - $t) * 1e6));
        }
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
        $t = microtime(true);
        try {
            return parent::execute($params);
        } finally {
            Load::noteTime($this->queryString, (int)round((microtime(true) - $t) * 1e6));
        }
    }
}
