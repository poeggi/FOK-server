<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';

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
            'path' => '/admin/',
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
        $lock = $fails >= FOK_ADMIN_MAX_FAILS ? $now + FOK_ADMIN_LOCK_SECONDS : 0;
        $db->prepare(
            'INSERT INTO admin_fails (ip, fails, locked_until) VALUES (?, ?, ?)
             ON CONFLICT (ip) DO UPDATE SET fails = excluded.fails, locked_until = excluded.locked_until'
        )->execute([$ip, $fails, $lock]);
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
