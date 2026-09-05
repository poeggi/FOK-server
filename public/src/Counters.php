<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Caps.php';
require_once __DIR__ . '/Load.php';

/**
 * Request counters, accumulated in shared memory and written to the
 * database one minute at a time.
 *
 * The counters table itself stays where it is: lifetime totals (see Stats),
 * the hourly load history and the item mint buckets are all things an
 * operator expects to survive a restart, so the database is their home. What
 * does NOT belong there is the arrival of a single request - that used to be
 * an upsert on the single SQLite writer for every hello, signal and relayed
 * message, purely to add one to a number.
 *
 * So this is a write-behind buffer, not a second store: requests increment
 * an APCu counter per minute, and the first request to arrive after a minute
 * has closed folds that whole minute into the database in one statement.
 * The writer sees one write a minute instead of one per request, and nothing
 * durable moves out of SQLite.
 *
 * A minute closed by the last request before an idle patch is flushed by
 * whoever asks next - the admin dashboard calls flushDue() before reading
 * (see AdminData::window), so a quiet server still reports the truth.
 *
 * Three kinds of thing share the buffer, told apart by the metric name so a
 * reader never has to guess which it has: a bare name is an endpoint's
 * request count, "n:" is a counted total that accumulates over the window
 * (messages out, database writes), and "g:" is a sampled level that does not
 * accumulate at all (see gauge).
 */
final class Counters
{
    private const PREFIX = 'fok:ct:';

    // Long enough that a minute cannot expire before the request that closes
    // it arrives, short enough to be self-cleaning if one never does.
    private const BUCKET_TTL = 900;

    // How often a request may go looking for closed minutes to fold.
    private const SCAN_EVERY = 2;

    private static function reqKey(string $minute): string
    {
        return self::PREFIX . "m:$minute:req";
    }

    private static function metricKey(string $minute, string $metric): string
    {
        return self::PREFIX . "m:$minute:e:$metric";
    }

    /**
     * Counts one request against $metric and the shared per-minute request
     * total. Returns how many requests this minute has seen so far, which is
     * the number the traffic alert samples (see Util::watch).
     */
    public static function hit(string $metric): int
    {
        $minute = gmdate('YmdHi');
        // What this request cost, filed before the fold below: that fold
        // writes to the database, and its write must not be booked against
        // whichever request happens to carry it.
        self::cost($minute, $metric);
        // Finding closed minutes costs a keyspace scan, so it is rate-gated
        // rather than run on every request: apcu_add() succeeds for one
        // caller per window across the whole pool. A minute folded a couple
        // of seconds late is still the same number.
        if (apcu_add(self::PREFIX . 'scan', 1, self::SCAN_EVERY) === true) {
            self::flushDue($minute);
        }
        apcu_inc(self::metricKey($minute, $metric), 1, $ok, self::BUCKET_TTL);
        return (int)apcu_inc(self::reqKey($minute), 1, $ok, self::BUCKET_TTL);
    }

    /**
     * Adds to a counted total for the current minute - a gauge the request
     * accumulated rather than a request of its own (see Load::flush). It
     * folds with everything else, so it costs no database write here.
     */
    public static function add(string $metric, int $n): void
    {
        if ($n > 0) {
            apcu_inc(self::metricKey(gmdate('YmdHi'), $metric), $n, $ok, self::BUCKET_TTL);
        }
    }

    /**
     * Records the server's LEVELS for the current hour: how full shared
     * memory is, how big the database has grown, how many rows it holds, how
     * many duels are being relayed. These do not accumulate - two samples an
     * hour apart are the same reading taken twice, not twice as much of
     * anything - so the newest sample of an hour replaces the one before it,
     * and an hour that got no sample keeps the last known value when it is
     * drawn (see the Live tab's graphs).
     *
     * Called on the hourly maintenance cadence, off the same gate as the
     * player sweep (see Util::watch): one statement an hour.
     */
    public static function sampleGauges(): void
    {
        require_once __DIR__ . '/Relay.php';
        $sma = Caps::apcu() ? apcu_sma_info(true) : false;
        $used = is_array($sma)
            ? (int)($sma['num_seg'] ?? 0) * (int)($sma['seg_size'] ?? 0) - (int)($sma['avail_mem'] ?? 0)
            : 0;
        self::gauge([
            'relaying' => Relay::activePairs(),
            'db_rows' => Db::rowCount(),
            'db_size' => is_file(FOK_DB_FILE) ? (int)filesize(FOK_DB_FILE) : 0,
            'apcu' => $used,
        ]);
    }

    /** @param array<string,int> $levels metric name (without the g:) => reading */
    private static function gauge(array $levels): void
    {
        $hour = gmdate('YmdH');
        $rows = [];
        $args = [];
        foreach ($levels as $name => $value) {
            $rows[] = '(?, ?, ?)';
            array_push($args, $hour, "g:$name", $value);
        }
        Load::untracked(static fn() => Db::retry(static fn() => Db::get()->prepare(
            'INSERT INTO counters (bucket, metric, value) VALUES ' . implode(', ', $rows) .
            ' ON CONFLICT (bucket, metric) DO UPDATE SET value = excluded.value'
        )->execute($args)));
    }

    /**
     * What one request cost, under the same minute bucket as its hit and
     * with the endpoint's own name in the metric: how long it held its
     * worker (.ms), what it burned on a core (.cpu), and how many database
     * queries it caused (.db). The admin per-script view is then a read of
     * the same hour buckets everything else already folds into - no second
     * store and no second write.
     *
     * Wall time is measured here because here IS the end: bump() is
     * deferred, so this runs after the response was flushed (see
     * Util::runDeferred), and the span it measures is the whole time the
     * worker was unavailable to anyone else.
     */
    private static function cost(string $minute, string $metric): void
    {
        $start = (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? 0);
        $cost = [
            'ms' => $start > 0.0 ? (int)round((microtime(true) - $start) * 1000) : 0,
            'cpu' => Load::cpuMs(),
            'db' => Load::queries(),
        ];
        foreach ($cost as $suffix => $n) {
            if ($n > 0) {
                apcu_inc(self::metricKey($minute, "$metric.$suffix"), $n, $ok, self::BUCKET_TTL);
            }
        }
    }

    /**
     * Folds every minute older than $current into the database. Safe to call
     * from anywhere and safe to call twice: each buffered value is claimed
     * with apcu_delete() and only the caller that wins the delete writes it,
     * so concurrent folds cannot double-count and a fold of a minute with
     * nothing left in it writes nothing.
     */
    public static function flushDue(?string $current = null): void
    {
        $current ??= gmdate('YmdHi');
        // The keys carry their own minute, so a closed minute is found
        // rather than remembered - no marker to keep in step across workers,
        // and a deferred count that lands after its minute was folded is
        // simply picked up by the next fold instead of being lost.
        $minutes = [];
        foreach (new APCUIterator('/^' . preg_quote(self::PREFIX . 'm:', '/') . '/') as $e) {
            $rest = substr((string)$e['key'], strlen(self::PREFIX) + 2);
            $minute = substr($rest, 0, (int)strpos($rest, ':'));
            if ($minute !== '' && $minute < $current) {
                $minutes[$minute] = true;
            }
        }
        foreach (array_keys($minutes) as $key) {
            // A YmdHi stamp is all digits, so PHP has already turned it into
            // an int array key - it has to be spelled back out as the string
            // the bucket column stores.
            self::flushMinute((string)$key);
        }
    }

    /**
     * Writes one closed minute: every buffered total into the hour bucket it
     * belongs to AND into its own minute bucket, plus the request count under
     * req_min. Both windows because the dashboard offers both and neither can
     * be derived from the other - an hour is not a minute times sixty. One
     * statement, so the single writer is taken once for the whole minute
     * however many requests it held; the minute rows are dropped again after
     * two hours (see Util::watch).
     */
    private static function flushMinute(string $minute): void
    {
        $hour = substr($minute, 0, 10);
        $rows = [];
        $args = [];
        $req = self::claim(self::reqKey($minute));
        if ($req > 0) {
            $rows[] = '(?, ?, ?)';
            array_push($args, $minute, 'req_min', $req);
        }
        $prefix = self::PREFIX . "m:$minute:e:";
        foreach (new APCUIterator('/^' . preg_quote($prefix, '/') . '/') as $e) {
            $n = self::claim((string)$e['key']);
            if ($n <= 0) {
                continue;
            }
            $metric = substr((string)$e['key'], strlen($prefix));
            $rows[] = '(?, ?, ?)';
            array_push($args, $hour, $metric, $n);
            $rows[] = '(?, ?, ?)';
            array_push($args, $minute, $metric, $n);
        }
        if ($rows === []) {
            return;
        }
        // Adding, not replacing: an hour bucket collects sixty of these, and
        // a minute bucket must survive a late count arriving after its fold.
        Load::untracked(static fn() => Db::retry(static fn() => Db::get()->prepare(
            'INSERT INTO counters (bucket, metric, value) VALUES ' . implode(', ', $rows) .
            ' ON CONFLICT (bucket, metric) DO UPDATE SET value = value + excluded.value'
        )->execute($args)));
    }

    /**
     * Takes a buffered total out of shared memory, exactly once. Reading and
     * deleting are not one operation, so the delete is what decides: two
     * workers can both read 100, but only the one whose delete succeeds is
     * allowed to write it, and the other must count nothing.
     */
    private static function claim(string $key): int
    {
        $v = apcu_fetch($key, $ok);
        if (!$ok || !apcu_delete($key)) {
            return 0;
        }
        return (int)$v;
    }
}
