<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Alerts.php';
require_once __DIR__ . '/Settings.php';

final class Util
{
    /**
     * Keeps the "every response is JSON with ok" contract when something
     * fails: with display_errors=0 an uncaught exception (locked database,
     * full disk) would end the request with an EMPTY 500 body, blowing up
     * the client's response.json() instead of telling it what happened.
     */
    public static function installFaultHandler(): void
    {
        set_exception_handler(static function (Throwable $e): void {
            error_log('FOK fault: ' . $e);
            if (!headers_sent()) {
                self::jsonOut(['ok' => false, 'error' => 'server fault'], 500);
            }
        });
        // Exception handlers do not see fatals, and an execution timeout
        // hits the long polls first.
        register_shutdown_function(static function (): void {
            $e = error_get_last();
            $fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
            if ($e !== null && ($e['type'] & $fatal) !== 0 && !headers_sent()) {
                self::jsonOut(['ok' => false, 'error' => 'server fault'], 500);
            }
            // Endpoints that answer without jsonOut (backup download) would
            // otherwise drop their queue on the floor.
            self::runDeferred();
        });
    }

    // Player IDs are 32-bit values as 8 lowercase hex chars (see FOK-snake js/storage.js).
    public static function isValidId(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[0-9a-f]{8}$/', $id) === 1;
    }

    public static function clientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '?';
    }

    public static function nowMs(): int
    {
        return (int)round(microtime(true) * 1000);
    }

    /**
     * Validates a client-sent PTS (server-clock timestamp, ms). Clients
     * report events that already happened, so by the time a PTS arrives
     * it must lie in the past - a future value means a broken sync or a
     * fabricated event and is rejected and logged as bogus.
     */
    public static function checkPts(mixed $pts, string $who): ?int
    {
        if ($pts === null) {
            return null;
        }
        if (!is_int($pts) || $pts < 0) {
            self::fail('invalid pts');
        }
        if ($pts > self::nowMs()) {
            self::bump('bogus');
            Alerts::raise('bogus', "Bogus client event: future PTS from $who (" . self::clientIp() . ')');
            self::fail('bogus pts: in the future');
        }
        return $pts;
    }

    public static function cors(): void
    {
        // Dynamic responses must never be cached by browsers or proxies.
        header('Cache-Control: no-store');
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (in_array($origin, FOK_ALLOWED_ORIGINS, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Access-Control-Max-Age: 3600');
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }

    /**
     * True only for a transport we can POSITIVELY identify as pre-TLS-1.2
     * (SSLv2/3, TLS 1.0/1.1). An empty or unrecognised value reads as
     * acceptable (fail-open): TLS may be terminated upstream where PHP never
     * sees SSL_PROTOCOL, and we must not block a request we cannot judge.
     */
    public static function tlsBelow12(string $proto): bool
    {
        return $proto !== ''
            && (str_starts_with($proto, 'SSL') || preg_match('/^TLSv1(\.[01])?$/', $proto) === 1);
    }

    /**
     * Backstop in case the host's TLS floor is ever relaxed: refuse a
     * request served over pre-1.2 TLS. Runs on every request (see the load
     * hook below). The host already rejects old TLS at the handshake, so in
     * practice this never fires - it is there for the day that changes.
     */
    public static function requireModernTls(): void
    {
        if (self::tlsBelow12($_SERVER['SSL_PROTOCOL'] ?? '')) {
            self::fail('TLS 1.2 or higher required', 426);
        }
    }

    /**
     * The request body, hard-capped: the only other bound is PHP's
     * post_max_size (8M default), i.e. anyone could make a worker buffer
     * megabytes. Rejected loudly (413), never silently trimmed.
     */
    public static function jsonBody(): array
    {
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > FOK_MAX_BODY) {
            self::fail('body too large', 413);
        }
        $raw = file_get_contents('php://input', false, null, 0, FOK_MAX_BODY + 1);
        if ($raw === false) {
            return [];
        }
        if (strlen($raw) > FOK_MAX_BODY) {
            self::fail('body too large', 413);
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /** @var list<callable> */
    private static array $deferred = [];

    /**
     * Runs $fn AFTER the response has been handed to the client. The
     * server's own bookkeeping is not the caller's work and must not sit
     * in its latency: under FPM the response is flushed first, so the
     * client is gone before any of it runs.
     *
     * ONLY for work the client never observes (monitoring, sweeps). A
     * client may issue its next request the instant the response lands,
     * and that request can overtake this work - anything it could read
     * back has to happen before the answer, not here.
     *
     * Without FPM (CLI, the php -S test server) there is nothing to flush
     * and the work simply runs inline: same behaviour, only the timing
     * differs, so the tests still see it.
     */
    public static function defer(callable $fn): void
    {
        self::$deferred[] = $fn;
    }

    /** Idempotent: jsonOut runs the queue, the shutdown handler catches
     *  whatever exits by another path (readfile, a fatal). */
    public static function runDeferred(): void
    {
        if (self::$deferred === []) {
            return;
        }
        $queue = self::$deferred;
        self::$deferred = [];
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        foreach ($queue as $fn) {
            try {
                $fn();
            } catch (Throwable $e) {
                // The response is already sent: bookkeeping cannot be
                // allowed to become the client's problem.
                error_log('FOK deferred: ' . $e);
            }
        }
    }

    public static function jsonOut(array $data, int $code = 200): never
    {
        http_response_code($code);
        header('Cache-Control: no-store');
        header('Content-Type: application/json');
        echo json_encode($data);
        self::runDeferred();
        exit;
    }

    public static function fail(string $msg, int $code = 400): never
    {
        if ($code === 400) {
            self::defer(static fn() => self::noteInvalid());
        }
        self::jsonOut(['ok' => false, 'error' => $msg], $code);
    }

    /**
     * Per-hour counters feed the admin load statistics; the per-minute
     * request counter feeds the traffic alert.
     *
     * Deferred, because none of it is the caller's: two writes on the
     * single SQLite writer for every request, every 25th request a
     * threshold sweep, and once an hour the whole player expiry - which
     * one unlucky client used to wait for.
     */
    public static function bump(string $metric): void
    {
        self::defer(static fn() => self::bumpNow($metric));
    }

    private static function bumpNow(string $metric): void
    {
        // BOTH counters in one statement. Two upserts meant two implicit
        // transactions, so every request took the single write lock twice -
        // and that writer, not the microseconds, is the ceiling behind the
        // worker pool. The rows never conflict with each other: the hour
        // bucket is YmdH, the minute bucket YmdHi.
        $minute = gmdate('YmdHi');
        $st = Db::get()->prepare(
            'INSERT INTO counters (bucket, metric, value) VALUES (?, ?, 1), (?, ?, 1)
             ON CONFLICT (bucket, metric) DO UPDATE SET value = value + 1
             RETURNING bucket, metric, value'
        );
        $st->execute([gmdate('YmdH'), $metric, $minute, 'req_min']);
        $reqPerMin = 0;
        foreach ($st->fetchAll() as $r) {
            if ($r['bucket'] === $minute && $r['metric'] === 'req_min') {
                $reqPerMin = (int)$r['value'];
            }
        }
        // Threshold checks are cheap but not free; sample every 25 requests.
        // The > 0 guard matters: a miss must not read as "every request".
        if ($reqPerMin > 0 && $reqPerMin % 25 === 0) {
            self::watch($reqPerMin);
        }
    }

    // Inline monitoring - shared hosting has no daemons, so thresholds
    // are checked while serving regular requests.
    private static function watch(int $reqPerMin): void
    {
        if ($reqPerMin > Settings::int('alert_req_per_min')) {
            Alerts::raise('traffic', "Excessive traffic: $reqPerMin requests in the current minute");
        }
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg()[0] ?? 0.0;
            if ($load > Settings::int('alert_load1')) {
                Alerts::raise('overload', sprintf('System overload: 1-minute load average %.1f', $load));
            }
        }
        require_once __DIR__ . '/Presence.php';
        $online = Presence::counts()['online'];
        if ($online > Settings::int('alert_online')) {
            Alerts::raise('connections', "Excessive connections: $online players online");
        }
        // At most once per hour: expire players not seen for the TTL.
        $db = Db::get();
        $st = $db->prepare("SELECT value FROM counters WHERE bucket = 'meta' AND metric = 'player_sweep'");
        $st->execute();
        $last = (int)$st->fetchColumn();
        if ($last < time() - 3600) {
            $db->prepare(
                "INSERT INTO counters (bucket, metric, value) VALUES ('meta', 'player_sweep', ?)
                 ON CONFLICT (bucket, metric) DO UPDATE SET value = excluded.value"
            )->execute([time()]);
            $n = Presence::expireStale();
            if ($n > 0) {
                Alerts::raise('expiry', "Expired $n player(s) not seen for "
                    . Settings::int('player_ttl_days') . ' days; friendships cancelled');
            }
        }
        Db::get()->prepare("DELETE FROM counters WHERE metric = 'req_min' AND bucket < ?")
            ->execute([gmdate('YmdHi', time() - 7200)]);
    }

    // Counts invalid (HTTP 400) requests per IP per minute and alerts on
    // clients that keep sending garbage (spam, oversized or malformed).
    private static function noteInvalid(): void
    {
        try {
            $db = Db::get();
            $st = $db->prepare(
                'INSERT INTO ipcount (ip, bucket, value) VALUES (?, ?, 1)
                 ON CONFLICT (ip, bucket) DO UPDATE SET value = value + 1
                 RETURNING value'
            );
            $ip = self::clientIp();
            $st->execute([$ip, gmdate('YmdHi')]);
            $n = (int)$st->fetchColumn();
            if ($n > Settings::int('alert_invalid_per_min')) {
                Alerts::raise('spam', "Client spam: $n invalid requests this minute from $ip");
            }
            if ($n === 1) {
                $db->prepare('DELETE FROM ipcount WHERE bucket < ?')
                    ->execute([gmdate('YmdHi', time() - 600)]);
            }
        } catch (Throwable $e) {
            // Monitoring must never turn an invalid request into a 500.
        }
    }
}

Util::installFaultHandler();
// Every request that reaches PHP passes through here (all endpoints require
// Util). Refuse anything below TLS 1.2 - a no-op while the host enforces it
// at the handshake, a guard if that ever changes. Fail-open when the TLS
// version is not visible (CLI tests, upstream termination).
Util::requireModernTls();
