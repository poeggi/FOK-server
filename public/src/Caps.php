<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';

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
 * The relay and the mailbox read apcu() from here; neither ever probes.
 */
final class Caps
{
    // Keyed by release: a deploy misses and re-assesses, which is exactly
    // when the answer can have changed. The TTL only bounds how long a
    // stale segment could answer for a release that is no longer deployed.
    private const CACHE_KEY = FOK_APCU_NS . 'caps:' . FOK_SERVER_VERSION;
    private const CACHE_TTL = 3600;

    private static ?array $cache = null;

    /** Assessment for THIS release, probing only if missing or stale. */
    public static function get(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        if (self::available()) {
            $hit = apcu_fetch(self::CACHE_KEY);
            if (is_array($hit)) {
                return self::$cache = $hit;
            }
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
            self::remember($stored);
            return self::$cache = $stored;
        }
        // Missing or from another release: assess once.
        return self::$cache = self::assess();
    }

    /** Operator-triggered re-assessment (admin Performance tab). */
    public static function refresh(): array
    {
        self::$cache = null;
        return self::$cache = self::assess();
    }

    /** Drop the caches; the next get() re-reads the stored assessment. */
    public static function forget(): void
    {
        if (self::available()) {
            apcu_delete(self::CACHE_KEY);
        }
        self::$cache = null;
    }

    // The raw probe, not apcu() below: that one answers FROM the assessment
    // this is guarding, so asking it here would be a cycle.
    private static function available(): bool
    {
        return function_exists('apcu_fetch') && apcu_enabled();
    }

    private static function remember(array $out): void
    {
        if (self::available()) {
            apcu_store(self::CACHE_KEY, $out, self::CACHE_TTL);
        }
    }

    /**
     * Is APCu usable on this host? Load-bearing rather than an optimization:
     * the signal mailbox and the relay hub live there and have no database
     * transport, so a false here means those features are down (503) until it
     * is fixed - see Signals and RelayStore.
     */
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

        // APCu. Shared memory between workers is load-bearing, not an
        // optimization: the signal mailbox and the relay hub live there and
        // have no database transport (see Signals, RelayStore).
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
            $usable ? '' : 'signaling and relayed duels are down (503) until this works'
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
        // The first request after a deploy runs this, and on a busy server
        // several of them run it at once: the write has to survive losing
        // the writer lock, or one of them answers 500 for a probe.
        Db::retry(static function () use ($out): void {
            Db::get()->prepare(
                'INSERT INTO caps (id, version, checked, data) VALUES (1, ?, ?, ?)
                 ON CONFLICT (id) DO UPDATE SET version = excluded.version,
                     checked = excluded.checked, data = excluded.data'
            )->execute([$out['version'], $out['checked'], (string)json_encode($out)]);
        });
        self::remember($out);
        return $out;
    }
}
