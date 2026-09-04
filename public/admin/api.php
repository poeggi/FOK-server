<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Presence.php';
require_once __DIR__ . '/../src/Scores.php';
require_once __DIR__ . '/../src/Backup.php';
require_once __DIR__ . '/../src/Alerts.php';
require_once __DIR__ . '/../src/Logs.php';
require_once __DIR__ . '/../src/Caps.php';
require_once __DIR__ . '/../src/Settings.php';
require_once __DIR__ . '/../src/ConnTrack.php';
require_once __DIR__ . '/../src/Vault.php';
require_once __DIR__ . '/../src/Debug.php';
require_once __DIR__ . '/../src/AdminData.php';
require_once __DIR__ . '/../src/Ledger.php';
require_once __DIR__ . '/../src/Tournament.php';
require_once __DIR__ . '/../src/Stats.php';

Auth::requireLogin();

$action = $_GET['action'] ?? '';
$db = Db::get();

/**
 * State-changing actions are POST-only: a GET could be triggered cross-site
 * by top-level navigation despite the SameSite=Lax cookie.
 */
function requirePost(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        Util::fail('POST only', 405);
    }
}
/** A validated 8-hex client id, from GET (default) or POST. */
function requireId(string $src = 'GET'): string
{
    $id = $src === 'POST' ? ($_POST['id'] ?? '') : ($_GET['id'] ?? '');
    if (!Util::isValidId($id)) {
        Util::fail('invalid id');
    }
    return $id;
}
/** Send an inline text/JSON body as a named download and stop. */
function download(string $filename, string $body, string $type = 'application/json'): never
{
    header('Content-Type: ' . $type);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $body;
    exit;
}

/**
 * The admin audit trail: one log line per state-changing action, naming what
 * was done, to what, and from where - the log's own timestamp says when. A
 * change nobody remembers making can then be traced to the call that made it.
 * Reads and downloads are deliberately absent: they change nothing, and
 * auditing them would bury the writes in noise.
 */
const AUDIT = [
    'set_debug' => 'set the client debug flag',
    'delete_player' => 'deleted player',
    'vault_reset' => 'reset the config-vault token of',
    'debug_delete' => 'deleted debug datasets',
    'delete_score' => 'deleted score',
    'alerts_seen' => 'marked the alerts seen',
    'caps_refresh' => 're-assessed the host capabilities',
    'log_clear' => 'cleared the server log',
    'settings_save' => 'saved the settings',
    'config_import' => 'imported a settings file',
    'backup_create' => 'created a database backup',
    'backup_restore' => 'restored the database from an upload',
];
// The two that replace live state wholesale. A line in the log is not enough
// for these: an operator must find them on the dashboard without going
// looking, so they alert as well as being audited.
const AUDIT_ALERT = ['config_import', 'backup_restore'];

if (isset(AUDIT[$action])) {
    // Whatever names the target of this call, in the order the actions pass
    // it. It is client input, so it is cut down to a printable subset and
    // capped - a crafted field must not be able to forge log lines of its own.
    $target = (string)($_POST['id'] ?? $_POST['pins'] ?? $_GET['id'] ?? '');
    $target = substr((string)preg_replace('/[^0-9a-zA-Z,_.-]/', '', $target), 0, 64);
    $what = AUDIT[$action] . ($target === '' ? '' : ' ' . $target);
    // Written when the response is on its way out, not here: an action that
    // gets rejected (a GET where POST is required, an invalid id) changed
    // nothing, and a trail claiming otherwise is worse than no trail.
    register_shutdown_function(static function () use ($action, $what): void {
        if (http_response_code() >= 400) {
            return;
        }
        Alerts::note('admin', $what . ' (from ' . Util::clientIp() . ')');
        if (in_array($action, AUDIT_ALERT, true)) {
            Alerts::raise('admin-' . $action, 'Admin ' . $what . ' - live state was replaced');
        }
    });
}

switch ($action) {
    // ---- dashboard cards (read-only) ----
    case 'stats':
        Util::jsonOut(['ok' => true] + AdminData::stats());

    case 'props':
        $ms = Util::nowMs();
        Util::jsonOut([
            'ok' => true,
            'pts_anchor' => '1970-01-01T00:00:00.000Z (unix epoch)',
            'utc_now' => gmdate('Y-m-d\TH:i:s', intdiv($ms, 1000)) . sprintf('.%03dZ', $ms % 1000),
            'pts_now' => $ms,
            'server_version' => FOK_SERVER_VERSION,
            'api_version' => FOK_API_VERSION,
            'env' => FOK_ENV,
            'php' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            // What the load-average alert is divided by (see Util::watch).
            'cores' => Util::cores(),
            // What the host actually gives the hot path. Shared hosting has
            // no shell and no phpinfo, so asking the running server is the
            // only way to find out - and each of these decides whether an
            // optimisation is available at all:
            //   opcache        - are the sources recompiled per request
            //   apcu           - is there shared memory between workers, the
            //                    prerequisite for keeping counters off the
            //                    single SQLite writer
            //   deferred_flush - can the response be handed over before the
            //                    bookkeeping runs (see Util::defer)
            'opcache' => extension_loaded('Zend OPcache') && (bool)ini_get('opcache.enable'),
            'apcu' => function_exists('apcu_enabled') && apcu_enabled(),
            'deferred_flush' => function_exists('fastcgi_finish_request'),
            'db_boot_us' => (int)round(Db::bootUs()),
        ]);

    case 'conns':
        Util::jsonOut(['ok' => true, 'now' => time(),
            'online_window' => FOK_ONLINE_WINDOW, 'conns' => ConnTrack::listPresence()]);

    case 'duels':
        Util::jsonOut(['ok' => true, 'now' => time(), 'duels' => ConnTrack::listDuels(),
            'tourneys' => Tournament::listLive(), 'totals' => Stats::all()]);

    // ---- clients ----
    case 'client':
        // One condensed, read-only view of everything known about a client,
        // gathered from the tables each subsystem already keeps (AdminData).
        $c = AdminData::client(requireId());
        if ($c === null) {
            Util::fail('unknown client', 404);
        }
        Util::jsonOut(['ok' => true] + $c);

    case 'set_debug':
        requirePost();
        // The wish only: the client honours it on its next hello and reports
        // back what it actually did (see the users card).
        Presence::setDebug(requireId('POST'), ($_POST['on'] ?? '') === '1');
        Util::jsonOut(['ok' => true]);

    case 'users':
        $total = (int)$db->query('SELECT COUNT(*) FROM players')->fetchColumn();
        $st = $db->query('SELECT id, name, ip, first_seen, last_seen, hello_count, latency, debug, debug_active FROM players ORDER BY last_seen DESC LIMIT 200');
        $users = array_map(static function (array $u) {
            $u['debug'] = (int)$u['debug'] === 1;
            $u['debug_active'] = (int)$u['debug_active'] === 1;
            return $u;
        }, $st->fetchAll());
        Util::jsonOut(['ok' => true, 'total' => $total, 'online_window' => FOK_ONLINE_WINDOW,
            'now' => time(), 'users' => $users]);

    case 'delete_player':
        // Reads $_POST['id'], so a GET (no such field) fails as 'invalid id'
        // rather than deleting - that empty-id path is the guard here.
        $id = requireId('POST');
        $db->prepare('DELETE FROM players WHERE id = ?')->execute([$id]);
        // Its item instances go with it - ownership is a row naming a player,
        // so leaving them behind strands them with an owner that no longer
        // exists. The ledger is append-only audit and deliberately stays: it
        // records that the instances existed and where they went.
        $db->prepare('DELETE FROM items WHERE owner = ?')->execute([$id]);
        ConnTrack::forget($id);
        // registered/online are derived from the players table and cached
        // (see Presence::counts); drop the cache so the dashboard reflects the
        // removal at once instead of lingering until the counts TTL lapses.
        Presence::flushCounts();
        Util::jsonOut(['ok' => true]);

    // ---- config vault (per-client backup) ----
    case 'vault_export':
        // Manual recovery: download a client's config backup WITHOUT its
        // token, as the same snake-fok-backup.json the game imports.
        $id = requireId();
        $vault = Vault::peek($id);
        if ($vault === null) {
            Util::fail('no backup', 404);
        }
        download('snake-fok-backup-' . $id . '.json', $vault['payload']);

    case 'vault_reset':
        // Clear a client's backup token so it can re-enroll (its next backup
        // mints a fresh one); keeps the payload.
        requirePost();
        Util::jsonOut(['ok' => true, 'reset' => Vault::resetToken(requireId('POST'))]);

    // ---- debug datasets ----
    case 'debug_list':
        // ttl + now let the dashboard show when each one expires.
        Util::jsonOut(['ok' => true, 'now' => time(), 'ttl' => FOK_DEBUG_TTL,
            'datasets' => Debug::recent()]);

    case 'debug_get':
        // Download one dataset by its 4-digit PIN (the handle a user reads out).
        $pin = $_GET['pin'] ?? '';
        if (!preg_match('/^[0-9]{4}$/', $pin)) {
            Util::fail('invalid pin');
        }
        $ds = Debug::get($pin);
        if ($ds === null) {
            Util::fail('unknown pin', 404);
        }
        download('debug-' . $pin . '.json', $ds['payload']);

    case 'debug_delete':
        // Bulk-delete debug datasets by PIN (comma-separated).
        $pins = array_values(array_filter(
            explode(',', (string)($_POST['pins'] ?? '')),
            static fn($p) => preg_match('/^[0-9]{4}$/', $p) === 1
        ));
        Util::jsonOut(['ok' => true, 'deleted' => Debug::delete($pins)]);

    // ---- scores ----
    case 'scores':
        Util::jsonOut(['ok' => true, 'scores' => Scores::top()]);

    case 'delete_score':
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            Util::fail('invalid id');
        }
        Scores::delete($id);
        Util::jsonOut(['ok' => true]);

    // ---- alerts ----
    case 'alerts':
        Util::jsonOut([
            'ok' => true,
            'unseen' => Alerts::unseenCount(),
            'alerts' => Alerts::recent(),
        ]);

    case 'alerts_seen':
        requirePost();
        Alerts::markSeen();
        Util::jsonOut(['ok' => true]);

    // ---- item registry (see Items, Ledger) ----
    case 'items':
        Util::jsonOut(['ok' => true] + AdminData::items());

    case 'items_verify':
        // Walk the hash chain from the newest checkpoint forward and report
        // whether it is intact (and where it breaks if not). A read, but
        // POST-only so it is never triggered by a cross-site navigation.
        requirePost();
        Util::jsonOut(['ok' => true, 'verify' => Ledger::verify($db)]);

    // ---- host capabilities ----
    case 'caps':
        Util::jsonOut(['ok' => true, 'now' => time()] + Caps::get());

    case 'caps_refresh':
        requirePost();   // re-assessment is a write
        Util::jsonOut(['ok' => true, 'now' => time()] + Caps::refresh());

    // ---- server log ----
    case 'log':
        Util::jsonOut(['ok' => true] + Logs::tail());

    case 'log_clear':
        requirePost();
        Logs::clear();
        Util::jsonOut(['ok' => true]);

    // ---- settings ----
    case 'settings':
        Util::jsonOut(['ok' => true, 'settings' => Settings::all()]);

    case 'config_export':
        $map = [];
        foreach (Settings::all() as $s) {
            $map[$s['key']] = $s['value'];
        }
        download('fok-config.json', (string)json_encode($map, JSON_PRETTY_PRINT));

    case 'config_import':
        requirePost();
        $map = json_decode((string)($_POST['config'] ?? ''), true);
        if (!is_array($map) || $map === []) {
            Util::fail('invalid config JSON');
        }
        foreach ($map as $key => $value) {
            if (!is_string($key) || !isset(Settings::DEFS[$key])) {
                Util::fail("unknown setting $key");
            }
            if (!is_int($value) || $value < 0 || $value > 1000000000) {
                Util::fail("invalid value for $key");
            }
        }
        foreach ($map as $key => $value) {
            Settings::set($key, $value);
        }
        Util::jsonOut(['ok' => true, 'settings' => Settings::all()]);

    case 'settings_save':
        requirePost();
        foreach (Settings::DEFS as $key => $def) {
            if (!isset($_POST[$key])) {
                continue;
            }
            $value = filter_var($_POST[$key], FILTER_VALIDATE_INT);
            if ($value === false || $value < 0 || $value > 1000000000) {
                Util::fail("invalid value for $key");
            }
            Settings::set($key, $value);
        }
        Util::jsonOut(['ok' => true, 'settings' => Settings::all()]);

    // ---- database backups ----
    case 'backup_create':
        requirePost();
        Util::jsonOut(['ok' => true, 'name' => Backup::create()]);

    case 'backup_list':
        Util::jsonOut(['ok' => true, 'backups' => Backup::list()]);

    case 'backup_download':
        $name = $_GET['file'] ?? '';
        if (!Backup::isValidName($name) || !is_file(FOK_BACKUP_DIR . '/' . $name)) {
            Util::fail('unknown backup', 404);
        }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . (string)filesize(FOK_BACKUP_DIR . '/' . $name));
        readfile(FOK_BACKUP_DIR . '/' . $name);
        exit;

    case 'backup_restore':
        if (!isset($_FILES['db']) || $_FILES['db']['error'] !== UPLOAD_ERR_OK) {
            Util::fail('upload failed');
        }
        try {
            Backup::restore($_FILES['db']['tmp_name']);
        } catch (RuntimeException $e) {
            Util::fail($e->getMessage());
        }
        Util::jsonOut(['ok' => true]);

    default:
        Util::fail('unknown action', 404);
}
