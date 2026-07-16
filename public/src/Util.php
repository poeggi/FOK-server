<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Alerts.php';
require_once __DIR__ . '/Settings.php';

final class Util
{
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

    public static function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw === false ? '' : $raw, true);
        return is_array($data) ? $data : [];
    }

    public static function jsonOut(array $data, int $code = 200): never
    {
        http_response_code($code);
        header('Cache-Control: no-store');
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    public static function fail(string $msg, int $code = 400): never
    {
        if ($code === 400) {
            self::noteInvalid();
        }
        self::jsonOut(['ok' => false, 'error' => $msg], $code);
    }

    // Per-hour counters feed the admin load statistics; the per-minute
    // request counter feeds the traffic alert.
    public static function bump(string $metric): void
    {
        $db = Db::get();
        $bucket = gmdate('YmdH');
        $db->prepare(
            'INSERT INTO counters (bucket, metric, value) VALUES (?, ?, 1)
             ON CONFLICT (bucket, metric) DO UPDATE SET value = value + 1'
        )->execute([$bucket, $metric]);

        $st = $db->prepare(
            'INSERT INTO counters (bucket, metric, value) VALUES (?, ?, 1)
             ON CONFLICT (bucket, metric) DO UPDATE SET value = value + 1
             RETURNING value'
        );
        $st->execute([gmdate('YmdHi'), 'req_min']);
        $reqPerMin = (int)$st->fetchColumn();
        // Threshold checks are cheap but not free; sample every 25 requests.
        if ($reqPerMin % 25 === 0) {
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
