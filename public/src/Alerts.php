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
        return self::withNames($st->fetchAll());
    }

    // A player in an alert message is named by its bare 8-hex id (see
    // Util::isValidId): "player 5ad6eb5f", "sender 5ad6eb5f". A raw id says
    // nothing to a human reading the dashboard, so resolve it to the player's
    // name here, on the READ path - never stored into the alert. Resolving at
    // display time is deliberate: a name is picked or changed after the alert
    // fired, and may be unknown when it fires yet known when it is read; the
    // annotation shows the player as known now. An id with no name (or an
    // 8-hex token that is not a player) is left as-is.
    private static function withNames(array $rows): array
    {
        $ids = [];
        foreach ($rows as $r) {
            if (preg_match_all('/\b[0-9a-f]{8}\b/', (string)$r['message'], $m)) {
                foreach ($m[0] as $tok) {
                    $ids[$tok] = true;
                }
            }
        }
        if ($ids === []) {
            return $rows;
        }
        $ids = array_keys($ids);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = Db::get()->prepare("SELECT id, name FROM players WHERE id IN ($ph)");
        $st->execute($ids);
        $names = [];
        foreach ($st->fetchAll() as $row) {
            $name = trim((string)($row['name'] ?? ''));
            if ($name !== '') {
                $names[$row['id']] = $name;
            }
        }
        if ($names === []) {
            return $rows;
        }
        foreach ($rows as &$r) {
            $r['message'] = preg_replace_callback(
                '/\b[0-9a-f]{8}\b/',
                static fn(array $m): string =>
                    isset($names[$m[0]]) ? $m[0] . ' "' . $names[$m[0]] . '"' : $m[0],
                (string)$r['message']
            );
        }
        unset($r);
        return $rows;
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
