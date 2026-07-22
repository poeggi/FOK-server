<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Settings.php';

/**
 * Alert store for operational events (traffic spikes, overload, client
 * spam, admin login trouble). Alerts are shown in the admin dashboard.
 * Raising is de-duplicated per type within the alert_cooldown window so
 * a sustained condition produces one alert, not thousands.
 */
final class Alerts
{
    // TODO: pluggable delivery backends (Telegram / SMS / Email). Add a
    // dispatch($type, $message) call at the end of raise() that fans out
    // to configured backends; until then alerts are local-only (admin UI).

    /**
     * Record an operational alert. Returns true when a FRESH alert was stored,
     * false when it was suppressed as a duplicate within alert_cooldown - so a
     * caller that also wants a server-log line can gate it on the return and
     * inherit the same de-duplication (see RelayStore::usingApcu).
     */
    public static function raise(string $type, string $message): bool
    {
        $db = Db::get();
        $st = $db->prepare('SELECT 1 FROM alerts WHERE type = ? AND created > ? LIMIT 1');
        $st->execute([$type, time() - Settings::int('alert_cooldown')]);
        $recent = $st->fetchColumn() !== false;
        $st->closeCursor();
        if ($recent) {
            return false;
        }
        $db->prepare('INSERT INTO alerts (type, message, created, seen) VALUES (?, ?, ?, 0)')
            ->execute([$type, $message, time()]);
        return true;
    }

    public static function recent(int $limit = 50): array
    {
        $st = Db::get()->prepare(
            'SELECT id, type, message, created, seen FROM alerts ORDER BY id DESC LIMIT ?'
        );
        $st->execute([$limit]);
        return $st->fetchAll();
    }

    public static function unseenCount(): int
    {
        return (int)Db::get()->query('SELECT COUNT(*) FROM alerts WHERE seen = 0')->fetchColumn();
    }

    public static function markSeen(): void
    {
        Db::get()->exec('UPDATE alerts SET seen = 1 WHERE seen = 0');
    }
}
