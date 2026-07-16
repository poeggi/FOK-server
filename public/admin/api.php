<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Presence.php';
require_once __DIR__ . '/../src/Scores.php';
require_once __DIR__ . '/../src/Backup.php';

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
        Util::jsonOut([
            'ok' => true,
            'counts' => $counts,
            'scores_total' => $scoreCount,
            'load' => $load,
            'db_size' => is_file(FOK_DB_FILE) ? filesize(FOK_DB_FILE) : 0,
            'php' => PHP_VERSION,
            'server_version' => FOK_VERSION,
            'now' => time(),
        ]);

    case 'users':
        $total = (int)$db->query('SELECT COUNT(*) FROM players')->fetchColumn();
        $st = $db->query('SELECT id, ip, first_seen, last_seen, hello_count FROM players ORDER BY last_seen DESC LIMIT 200');
        Util::jsonOut(['ok' => true, 'total' => $total, 'online_window' => FOK_ONLINE_WINDOW,
            'now' => time(), 'users' => $st->fetchAll()]);

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
        Util::jsonOut(['ok' => true]);

    case 'backup_create':
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
