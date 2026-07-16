<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Alerts.php';

/**
 * Admin authentication. Credentials are NEVER stored in code or repo:
 * the server keeps only a password_hash() of "user:pass" in a file
 * above the docroot, written once during setup.
 */
final class Auth
{
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name('FOKADMIN');
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => (($_SERVER['HTTPS'] ?? '') !== ''),
            'path' => (FOK_ENV === 'staging' ? '/staging' : '') . '/admin/',
        ]);
        session_start();
    }

    public static function isLoggedIn(): bool
    {
        self::startSession();
        return ($_SESSION['fok_admin'] ?? false) === true;
    }

    public static function login(string $user, string $pass, string $ip): bool
    {
        $db = Db::get();
        $now = time();
        $st = $db->prepare('SELECT fails, locked_until FROM admin_fails WHERE ip = ?');
        $st->execute([$ip]);
        $row = $st->fetch();
        if ($row && (int)$row['locked_until'] > $now) {
            return false;
        }

        $hash = is_readable(FOK_ADMIN_HASH_FILE) ? trim((string)file_get_contents(FOK_ADMIN_HASH_FILE)) : '';
        $ok = $hash !== '' && password_verify($user . ':' . $pass, $hash);

        if ($ok) {
            self::startSession();
            session_regenerate_id(true);
            $_SESSION['fok_admin'] = true;
            $db->prepare('DELETE FROM admin_fails WHERE ip = ?')->execute([$ip]);
            return true;
        }

        $fails = $row ? (int)$row['fails'] + 1 : 1;
        $maxFails = Settings::int('admin_max_fails');
        $lockSeconds = Settings::int('admin_lock_seconds');
        $lock = $fails >= $maxFails ? $now + $lockSeconds : 0;
        $db->prepare(
            'INSERT INTO admin_fails (ip, fails, locked_until) VALUES (?, ?, ?)
             ON CONFLICT (ip) DO UPDATE SET fails = excluded.fails, locked_until = excluded.locked_until'
        )->execute([$ip, $fails, $lock]);
        if ($lock > 0) {
            Alerts::raise('admin-lock', "Admin login: IP $ip blocked for {$lockSeconds}s after $fails failed attempts");
        } else {
            Alerts::raise('admin-fail', "Admin login: failed attempt from $ip ($fails recent)");
        }
        return false;
    }

    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        session_destroy();
    }

    public static function requireLogin(): void
    {
        if (!self::isLoggedIn()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'not logged in']);
            exit;
        }
    }
}
