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
        $st->closeCursor();
        if ($row && (int)$row['locked_until'] > $now) {
            return false;
        }

        $hash = is_readable(FOK_ADMIN_HASH_FILE) ? trim((string)file_get_contents(FOK_ADMIN_HASH_FILE)) : '';
        $ok = $hash !== '' && password_verify($user . ':' . $pass, $hash);

        if ($ok) {
            self::startSession();
            session_regenerate_id(true);
            $_SESSION['fok_admin'] = true;
            Db::retry(static function () use ($db, $ip): void {
                $db->prepare('DELETE FROM admin_fails WHERE ip = ?')->execute([$ip]);
            });
            // Who got in, and when - the opening line of the admin audit
            // trail that admin/api.php continues for every write.
            Alerts::note('admin', "login from $ip");
            return true;
        }

        $maxFails = Settings::int('admin_max_fails');
        $lockSeconds = Settings::int('admin_lock_seconds');
        // The count is raised BY the database, not read out and written back.
        // Guesses arrive in parallel, and a read-add-write lets every one of
        // them read the same count and store the same successor - so a burst
        // could spend far more than admin_max_fails attempts before any of
        // them saw a number high enough to lock the address out. Here the
        // increment and the lockout decision are one statement, and what it
        // returns is what was actually stored.
        //
        // The threshold is CAST because PDO binds it as text and the count is
        // an expression, not a column - so no column affinity converts it,
        // and SQLite sorts every integer below every string. Uncast, the
        // comparison is false at any number of failures and the lockout never
        // arms.
        $lockAt = $now + $lockSeconds;
        $first = 1 >= $maxFails ? $lockAt : 0;
        $state = Db::retry(static function () use ($db, $ip, $first, $maxFails, $lockAt): array {
            $st = $db->prepare(
                'INSERT INTO admin_fails (ip, fails, locked_until) VALUES (?, 1, ?)
                 ON CONFLICT (ip) DO UPDATE SET fails = admin_fails.fails + 1,
                     locked_until = CASE WHEN admin_fails.fails + 1 >= CAST(? AS INTEGER)
                         THEN ? ELSE 0 END
                 RETURNING fails, locked_until'
            );
            $st->execute([$ip, $first, $maxFails, $lockAt]);
            $out = $st->fetch();
            // An INSERT ... RETURNING is a write: finish it before anything
            // else asks for the writer (see Db::retry).
            $st->closeCursor();
            return $out === false ? ['fails' => 1, 'locked_until' => 0] : $out;
        });
        $fails = (int)$state['fails'];
        $lock = (int)$state['locked_until'];
        // A single miss is a typo far more often than an attack, so it is
        // only noted; the lockout is the escalation worth alerting on.
        if ($lock > 0) {
            Alerts::raise('admin-lock', "Admin login: IP $ip blocked for {$lockSeconds}s after $fails failed attempts");
        } else {
            Alerts::note('admin', "failed login attempt from $ip ($fails recent)");
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
