<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Presence.php';
require_once __DIR__ . '/../src/Scores.php';
require_once __DIR__ . '/../src/Backup.php';
require_once __DIR__ . '/../src/Alerts.php';
require_once __DIR__ . '/../src/Settings.php';
require_once __DIR__ . '/../src/ConnTrack.php';

Auth::requireLogin();

$action = $_GET['action'] ?? '';
$db = Db::get();

switch ($action) {
    case 'stats':
        $counts = Presence::counts();
        $st = $db->prepare('SELECT bucket, metric, value FROM counters WHERE bucket >= ? ORDER BY bucket');
        $st->execute([gmdate('YmdH', time() - 24 * 3600)]);
        $load = [];
        foreach ($st->fetchAll() as $r) {
            $load[$r['bucket']][$r['metric']] = (int)$r['value'];
        }
        $scoreCount = (int)$db->query('SELECT COUNT(*) FROM scores')->fetchColumn();
        $dbRows = 0;
        foreach (['players', 'scores', 'signals', 'duels', 'mm_queue', 'counters',
            'alerts', 'settings', 'admin_fails', 'ipcount', 'friends', 'relay',
            'starts', 'conn', 'stats'] as $table) {
            $dbRows += (int)$db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
        }
        $relaying = ConnTrack::relayPairs();
        $friendships = (int)$db->query(
            "SELECT COUNT(*) FROM friends WHERE state = 'accepted'"
        )->fetchColumn();
        $friendshipsPending = (int)$db->query(
            "SELECT COUNT(*) FROM friends WHERE state = 'pending'"
        )->fetchColumn();
        Util::jsonOut([
            'ok' => true,
            'counts' => $counts,
            'relaying' => $relaying,
            'friendships' => $friendships,
            'friendships_pending' => $friendshipsPending,
            'scores_total' => $scoreCount,
            'db_rows' => $dbRows,
            'load' => $load,
            'db_size' => is_file(FOK_DB_FILE) ? filesize(FOK_DB_FILE) : 0,
            'php' => PHP_VERSION,
            'server_version' => FOK_SERVER_VERSION,
            'env' => FOK_ENV,
            'now' => time(),
        ]);

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
        Util::jsonOut(['ok' => true, 'now' => time(), 'duels' => ConnTrack::listDuels()]);

    case 'set_debug':
        // State-changing, so POST-only: a GET could be triggered cross-site
        // by top-level navigation despite the SameSite=Lax cookie.
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            Util::fail('POST only', 405);
        }
        $id = $_POST['id'] ?? '';
        if (!Util::isValidId($id)) {
            Util::fail('invalid id');
        }
        // The wish only: the client honours it on its next hello and
        // reports back what it actually did (see the users card).
        Presence::setDebug($id, ($_POST['on'] ?? '') === '1');
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

    case 'scores':
        Util::jsonOut(['ok' => true, 'scores' => Scores::top()]);

    case 'delete_score':
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            Util::fail('invalid id');
        }
        Scores::delete($id);
        Util::jsonOut(['ok' => true]);

    case 'delete_player':
        $id = $_POST['id'] ?? '';
        if (!Util::isValidId($id)) {
            Util::fail('invalid id');
        }
        $db->prepare('DELETE FROM players WHERE id = ?')->execute([$id]);
        ConnTrack::forget($id);
        Util::jsonOut(['ok' => true]);

    case 'alerts':
        Util::jsonOut([
            'ok' => true,
            'unseen' => Alerts::unseenCount(),
            'alerts' => Alerts::recent(),
        ]);

    case 'alerts_seen':
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            Util::fail('POST only', 405);
        }
        Alerts::markSeen();
        Util::jsonOut(['ok' => true]);

    case 'settings':
        Util::jsonOut(['ok' => true, 'settings' => Settings::all()]);

    case 'config_export':
        $map = [];
        foreach (Settings::all() as $s) {
            $map[$s['key']] = $s['value'];
        }
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="fok-config.json"');
        echo json_encode($map, JSON_PRETTY_PRINT);
        exit;

    case 'config_import':
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            Util::fail('POST only', 405);
        }
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
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            Util::fail('POST only', 405);
        }
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

    case 'backup_create':
        // State-changing, so POST-only: a GET could be triggered cross-site
        // by top-level navigation despite the SameSite=Lax cookie.
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            Util::fail('POST only', 405);
        }
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
