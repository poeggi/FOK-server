<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Settings.php';

/**
 * What this host actually gives us, assessed once and remembered.
 *
 * Shared hosting has no phpinfo and no shell, so the only way to learn what
 * is available is to ask the running server - and some of those questions
 * cost real work (opening a socket, a cache round trip). Probing per request
 * would be exactly the kind of self-inflicted load this class exists to
 * report on, so the verdict is STORED, keyed by FOK_SERVER_VERSION: the
 * first request after a release re-assesses, every request after that is one
 * indexed read (cached per request on top of that). The admin Performance
 * tab shows the result and can force a re-assessment.
 *
 * The relay reads apcu() from here to pick its transport - it never probes.
 */
final class Caps
{
    private const MARK_KEY = 'fok:caps:mark';
    private const SHARED_KEY = 'fok:caps:shared';

    private static ?array $cache = null;
    private static ?bool $shared = null;

    /** Assessment for THIS release, probing only if missing or stale. */
    public static function get(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $st = Db::get()->prepare('SELECT version, checked, data FROM caps WHERE id = 1');
        $st->execute();
        $row = $st->fetch();
        $st->closeCursor();
        $stored = ($row !== false && is_string($row['data']))
            ? (json_decode($row['data'], true) ?: []) : [];
        if ($row !== false && $row['version'] === FOK_SERVER_VERSION) {
            $stored['version'] = (string)$row['version'];
            $stored['checked'] = (int)$row['checked'];
            return self::$cache = self::withRelayRow($stored);
        }
        // Missing or from another release: assess once.
        return self::$cache = self::withRelayRow(self::assess());
    }

    /** Operator-triggered re-assessment (admin Performance tab). */
    public static function refresh(): array
    {
        self::$cache = null;
        self::$shared = null;   // re-read the shared proof from the cache
        return self::$cache = self::withRelayRow(self::assess());
    }

    /**
     * Is APCu shared across this pool's workers, not a per-worker segment?
     * The relay MUST know: a per-worker cache would take a message from the
     * sender's worker that the receiver's worker never sees - silent loss.
     * It cannot be answered within one request (a single worker proves
     * nothing), so the proof lives in the cache itself: each worker leaves
     * its pid, and the first worker to find ANOTHER worker's mark sets a
     * flag every worker then reads. Cheap (a fetch, sometimes a store),
     * self-extinguishing once proven, and needs no operator action - two
     * workers each serving one request is enough. If the cache is flushed it
     * simply re-proves within a few requests.
     */
    public static function apcuShared(): bool
    {
        if (self::$shared !== null) {
            return self::$shared;
        }
        if (!function_exists('apcu_enabled') || !apcu_enabled()) {
            return self::$shared = false;
        }
        if (apcu_fetch(self::SHARED_KEY) === 1) {
            return self::$shared = true;   // some worker proved it; all see this
        }
        $mark = apcu_fetch(self::MARK_KEY, $ok);
        $me = getmypid();
        if ($ok && is_array($mark) && (int)($mark['pid'] ?? 0) !== $me) {
            apcu_store(self::SHARED_KEY, 1, 86400);   // proven: broadcast to all
            return self::$shared = true;
        }
        // Not proven yet: leave my mark for the next distinct worker.
        apcu_store(self::MARK_KEY, ['pid' => $me, 'at' => time()], 86400);
        return self::$shared = false;
    }

    /**
     * The transport row is computed on READ and never stored. relay_apcu is
     * an operator switch that can change at any moment, so a stored verdict
     * would keep reporting the previous transport until somebody pressed
     * Update - the card would be lying about the thing it exists to show.
     */
    private static function withRelayRow(array $c): array
    {
        $usable = ($c['apcu'] ?? false) === true;
        $wanted = Settings::int('relay_apcu') === 1;
        // Enabled is not enough: the relay only moves off the database once
        // APCu is PROVEN shared across workers (see apcuShared and
        // RelayStore::usingApcu), or a per-worker cache would silently lose
        // cross-worker messages.
        $shared = $usable && self::apcuShared();
        $live = $wanted && $shared;
        $c['relay_backend'] = $live ? 'apcu' : 'database';
        $c['checks'][] = [
            'key' => 'relay_backend',
            'label' => 'Relay transport',
            'value' => $live ? 'APCu shared memory' : 'database',
            'status' => $live ? 'good' : (!$wanted || $usable ? 'warn' : 'bad'),
            'note' => $live
                ? 'relay traffic never touches the SQLite writer'
                : (!$wanted
                    ? 'set relay_apcu to 1 to move relay traffic off the database'
                    : ($usable
                        ? 'APCu is enabled but not yet confirmed shared across workers - '
                            . 'relay stays on the database until it is (usually within a '
                            . 'few requests of a deploy)'
                        : 'APCu was requested but is not usable - falling back, expect lock contention')),
        ];
        return $c;
    }

    /** True when the relay may use shared memory instead of the database. */
    public static function apcu(): bool
    {
        $c = self::get();
        return ($c['apcu'] ?? false) === true;
    }

    private static function assess(): array
    {
        $checks = [];
        $add = static function (
            string $key,
            string $label,
            string $value,
            string $status,
            string $note = ''
        ) use (&$checks): void {
            $checks[] = ['key' => $key, 'label' => $label, 'value' => $value,
                'status' => $status, 'note' => $note];
        };

        $add('php', 'PHP', PHP_VERSION . ' (' . PHP_SAPI . ')', 'good');

        $opcache = extension_loaded('Zend OPcache') && (bool)ini_get('opcache.enable');
        $add('opcache', 'OPcache', $opcache ? 'on' : 'off', $opcache ? 'good' : 'bad',
            $opcache ? '' : 'every request recompiles the sources');

        $flush = function_exists('fastcgi_finish_request');
        $add('deferred_flush', 'Deferred flush', $flush ? 'available' : 'missing',
            $flush ? 'good' : 'bad',
            $flush ? '' : 'bookkeeping runs before the client is answered');

        // APCu. Shared memory between workers is the one thing that can take
        // relay traffic off the single SQLite writer entirely.
        $enabled = function_exists('apcu_enabled') && apcu_enabled();
        $iterator = class_exists('APCUIterator');
        $roundTrip = false;
        if ($enabled) {
            apcu_store('fok:caps:rt', 1, 60);
            $roundTrip = apcu_fetch('fok:caps:rt') === 1;
        }
        // Whether the cache is really shared between workers is proven in the
        // cache itself over successive requests, not within this one - see
        // apcuShared().
        $shared = self::apcuShared();
        $usable = $enabled && $iterator && $roundTrip;
        $add(
            'apcu',
            'APCu shared memory',
            $enabled ? ($shared ? 'enabled, shared across workers' : 'enabled, this worker') : 'unavailable',
            $usable ? ($shared ? 'good' : 'warn') : 'bad',
            $usable
                ? ($shared ? '' : 'not yet confirmed shared across workers - the relay '
                    . 'waits for that (auto-confirmed once a second worker serves a request)')
                : 'the relay cannot leave the database'
        );

        // Redis would give a blocking pop (no poll interval), but the
        // extension is only a client - it says nothing about a server.
        $redisExt = extension_loaded('redis') && class_exists('Redis');
        $redisUp = false;
        if ($redisExt) {
            try {
                $r = new Redis();
                $redisUp = @$r->connect('127.0.0.1', 6379, 0.2);
                if ($redisUp) {
                    $r->close();
                }
            } catch (Throwable $e) {
                $redisUp = false;
            }
        }
        $add('redis', 'Redis server', $redisExt ? ($redisUp ? 'reachable' : 'no server') : 'no extension',
            $redisUp ? 'good' : 'info',
            $redisUp ? '' : 'not required; a blocking pop would remove the poll interval');

        $db = Db::get();
        $ver = (string)$db->query('SELECT sqlite_version()')->fetchColumn();
        $journal = (string)$db->query('PRAGMA journal_mode')->fetchColumn();
        $busy = (int)$db->query('PRAGMA busy_timeout')->fetchColumn();
        $add('sqlite', 'SQLite', $ver . ', journal ' . $journal, $journal === 'wal' ? 'good' : 'bad',
            $journal === 'wal' ? '' : 'without WAL a reader blocks every writer');
        $add('busy_timeout', 'Busy timeout', $busy . ' ms', 'info',
            'a stale read snapshot fails instantly regardless - cursors must be closed');
        $add('db_open', 'Database open cost', (int)round(Db::bootUs()) . ' us', 'info',
            'paid by every request before any work');

        $writable = is_writable(FOK_DATA_DIR);
        $add('data_dir', 'Data directory', $writable ? 'writable' : 'NOT writable',
            $writable ? 'good' : 'bad', $writable ? '' : 'backups and the error log cannot be written');

        $out = [
            'version' => FOK_SERVER_VERSION,
            'checked' => time(),
            'apcu' => $usable,
            'apcu_shared' => $shared,
            'checks' => $checks,
        ];
        Db::get()->prepare(
            'INSERT INTO caps (id, version, checked, data) VALUES (1, ?, ?, ?)
             ON CONFLICT (id) DO UPDATE SET version = excluded.version,
                 checked = excluded.checked, data = excluded.data'
        )->execute([$out['version'], $out['checked'], (string)json_encode($out)]);
        return $out;
    }
}
