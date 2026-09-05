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
 * Four kinds of thing share the buffer, told apart by the metric name so a
 * reader never has to guess which it has: a bare name is an endpoint's
 * request count, "n:" is a counted total that accumulates over the window
 * (messages out, database writes), "g:" is a sampled level that does not
 * accumulate at all (see gauge), and "x:" is a maximum - the worst single
 * reading the window saw, which is the one shape the fold must not add up
 * (see max).
 *
 * One thing in the buffer is not a metric at all: worst() keeps a short
 * list of the worst readings a metric saw and who caused them, under its
 * own key. It shares the prefix so that clearing the history clears it too.
 */
final class Counters
{
    private const PREFIX = 'fok:ct:';

    // Long enough that a minute cannot expire before the request that closes
    // it arrives, short enough to be self-cleaning if one never does.
    private const BUCKET_TTL = 900;

    // How often a request may go looking for closed minutes to fold.
    private const SCAN_EVERY = 2;

    // The worst-case list beside the "x:" maximum: how many cases it keeps
    // and how far back it looks. The graph the list is read under covers
    // 24 hours, so a case that has aged out of the graph ages out here too.
    private const WORST_KEEP = 10;
    private const WORST_AGE = 86400;

    // Under a millisecond there is nothing to diagnose - the worker was
    // there for the taking - and without a floor an idle server would
    // rewrite the whole list on almost every request.
    private const WORST_FLOOR_US = 1000;

    private static function reqKey(string $minute): string
    {
        return self::PREFIX . "m:$minute:req";
    }

    private static function metricKey(string $minute, string $metric): string
    {
        return self::PREFIX . "m:$minute:e:$metric";
    }

    private static function worstKey(string $metric): string
    {
        return self::PREFIX . "worst:$metric";
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
     * Records the WORST reading of $metric this minute - a maximum, not a
     * total. Some measurements only mean anything at their peak: a mean
     * queue wait of one millisecond over a minute that contained a single
     * two-second stall describes a healthy server, and the stall was the
     * whole point (see Load::queueUs).
     *
     * "x:" is the name shape that says so, and it is the one the fold has to
     * treat differently: an hour's worst reading is the worst of its sixty
     * minutes, never their sum (see flushMinute).
     */
    public static function max(string $metric, int $n): void
    {
        if ($n <= 0) {
            return;
        }
        // Shared memory unasked, exactly like add() and hit() beside it: this
        // runs on every request, and a Caps lookup to decide whether APCu is
        // there would put a database read on the path that is here to prove
        // the server is not already overloaded.
        $key = self::metricKey(gmdate('YmdHi'), 'x:' . $metric);
        // A maximum cannot be an increment, so it is a compare-and-swap - and
        // a bounded number of tries rather than a loop until it wins. Losing
        // the race means another worker has just written a value of its own,
        // and a reading that keeps being outrun is by definition not the
        // biggest one this minute saw.
        for ($i = 0; $i < 3; $i++) {
            $cur = apcu_fetch($key, $ok);
            if (!$ok) {
                if (apcu_add($key, $n, self::BUCKET_TTL) === true) {
                    return;
                }
                continue;
            }
            if ((int)$cur >= $n || apcu_cas($key, (int)$cur, $n) === true) {
                return;
            }
        }
    }

    /**
     * Files one reading against a standing list of the worst the last
     * WORST_AGE saw, each with whatever identified the request that
     * produced it. The "x:" maximum beside it says how bad the worst case
     * was; this says which requests they were, which is the difference
     * between knowing the pool stalled and knowing what stalled it.
     *
     * $who is stored as given and shown as given - see Util::queueWho for
     * what a queue reading puts in it.
     *
     * Deliberately not a bucket metric: it is one key holding a short list,
     * not a number per minute, so it neither folds into the database nor
     * survives a restart. It is a diagnostic, and the moment it would be
     * worth reading again is a moment that has already been and gone.
     */
    public static function worst(string $metric, int $n, array $who): void
    {
        if ($n < self::WORST_FLOOR_US) {
            return;
        }
        // Shared memory unasked, exactly like max() above it.
        $key = self::worstKey($metric);
        $cur = apcu_fetch($key, $ok);
        $list = ($ok && is_array($cur)) ? self::worstFresh($cur) : [];
        // Nothing to do for the overwhelming majority of readings: the list
        // is full and this one is not among them.
        if (count($list) >= self::WORST_KEEP
            && $n <= (int)($list[count($list) - 1]['v'] ?? 0)) {
            return;
        }
        $list[] = ['v' => $n, 't' => time()] + $who;
        usort($list, static fn(array $a, array $b): int => $b['v'] <=> $a['v']);
        // Read-modify-write rather than the compare-and-swap max() uses,
        // because apcu_cas only works on integers. Two workers writing in
        // the same instant cost one entry of a top ten, which is a fair
        // price for keeping a diagnostic off any kind of lock.
        apcu_store($key, array_slice($list, 0, self::WORST_KEEP),
            self::WORST_AGE + 900);
    }

    /** The entries of a stored list that are still inside the window. */
    private static function worstFresh(array $list): array
    {
        $cut = time() - self::WORST_AGE;
        return array_values(array_filter($list, static fn($e): bool => is_array($e)
            && isset($e['v'], $e['t']) && (int)$e['t'] >= $cut));
    }

    /** The worst cases on record, worst first. Admin-only, hence Caps. */
    public static function worstList(string $metric): array
    {
        if (Caps::apcu() !== true) {
            return [];
        }
        $cur = apcu_fetch(self::worstKey($metric), $ok);
        return ($ok && is_array($cur)) ? self::worstFresh($cur) : [];
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
        $peaks = [];
        $peakArgs = [];
        $prefix = self::PREFIX . "m:$minute:e:";
        foreach (new APCUIterator('/^' . preg_quote($prefix, '/') . '/') as $e) {
            $n = self::claim((string)$e['key']);
            if ($n <= 0) {
                continue;
            }
            $metric = substr((string)$e['key'], strlen($prefix));
            // A maximum goes in the other statement; everything else adds up
            // (see max).
            if (str_starts_with($metric, 'x:')) {
                $peaks[] = '(?, ?, ?)';
                array_push($peakArgs, $hour, $metric, $n);
                $peaks[] = '(?, ?, ?)';
                array_push($peakArgs, $minute, $metric, $n);
                continue;
            }
            $rows[] = '(?, ?, ?)';
            array_push($args, $hour, $metric, $n);
            $rows[] = '(?, ?, ?)';
            array_push($args, $minute, $metric, $n);
        }
        $write = static function (array $rows, array $args, string $merge): void {
            if ($rows === []) {
                return;
            }
            Load::untracked(static fn() => Db::retry(static fn() => Db::get()->prepare(
                'INSERT INTO counters (bucket, metric, value) VALUES ' . implode(', ', $rows) .
                ' ON CONFLICT (bucket, metric) DO UPDATE SET value = ' . $merge
            )->execute($args)));
        };
        // Adding, not replacing: an hour bucket collects sixty of these, and
        // a minute bucket must survive a late count arriving after its fold.
        $write($rows, $args, 'value + excluded.value');
        // The exception, and the reason there are two statements: an hour's
        // worst reading is the worst of the minutes in it, and summing them
        // would turn sixty ordinary waits into one impossible outlier.
        $write($peaks, $peakArgs, 'MAX(value, excluded.value)');
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

    /**
     * Empties the traffic history: every hour and minute bucket in the table,
     * and the shared-memory buffer with them.
     *
     * The buffer is not an afterthought here. Whatever it still holds would
     * fold in moments later and put a minute of traffic back into a history
     * the operator had just emptied, which reads as a clear that did not
     * work.
     *
     * Numeric buckets only, so the lifetime game totals and the meta rows -
     * whose buckets are words and not stamps (see Stats) - are left alone.
     * "mint_" is spared with them: those rows sit in hour buckets, but they
     * are the item registry's accounting rather than traffic, and clearing a
     * statistics view is not an invitation to lose them.
     *
     * Reports what it removed - stored rows and buffered counters - because
     * the operator has no other way to tell an empty history from a clear
     * that never ran (see the admin per-script view).
     *
     * @return array{rows:int, keys:int}
     */
    public static function clearHistory(): array
    {
        // Guarded on the function, not on the stored capability verdict
        // (Caps): every count in this class is written with a bare apcu_inc,
        // so a verdict that says no while the writes go through anyway would
        // leave the buffer standing to fold straight back into the history
        // this just emptied.
        $keys = 0;
        if (function_exists('apcu_delete') && class_exists('APCUIterator')) {
            foreach (new APCUIterator('/^' . preg_quote(self::PREFIX, '/') . '/') as $e) {
                if (apcu_delete((string)$e['key'])) {
                    $keys++;
                }
            }
        }
        $rows = 0;
        Load::untracked(static function () use (&$rows): void {
            $st = Db::retry(static function () {
                $st = Db::get()->prepare(
                    "DELETE FROM counters
                     WHERE bucket GLOB '[0-9]*'
                       AND (length(bucket) = 10 OR length(bucket) = 12)
                       AND metric NOT GLOB 'mint_*'"
                );
                $st->execute();
                return $st;
            });
            $rows = $st->rowCount();
        });
        return ['rows' => $rows, 'keys' => $keys];
    }
}
