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
    private static ?array $cache = null;

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
        return self::$cache = self::withRelayRow(self::assess());
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
        // APCu is the default transport: usable shared memory is trusted to be
        // shared across the pool's workers (see RelayStore::usingApcu). The
        // relay stays on the database only when APCu is switched off or the
        // host cannot offer it.
        $live = $wanted && $usable;
        $c['relay_backend'] = $live ? 'apcu' : 'database';
        $c['checks'][] = [
            'key' => 'relay_backend',
            'label' => 'Relay transport',
            'value' => $live ? 'APCu shared memory' : 'database',
            'status' => $live ? 'good' : ($wanted ? 'bad' : 'warn'),
            'note' => $live
                ? 'relay traffic never touches the SQLite writer'
                : (!$wanted
                    ? 'set relay_apcu to 1 to move relay traffic off the database'
                    : 'APCu was requested but is not usable - falling back, expect lock contention'),
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
        $usable = $enabled && $iterator && $roundTrip;
        $add(
            'apcu',
            'APCu shared memory',
            $usable ? 'enabled' : ($enabled ? 'enabled, unusable' : 'unavailable'),
            $usable ? 'good' : 'bad',
            $usable ? '' : 'the relay cannot leave the database'
        );

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
