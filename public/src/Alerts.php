<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Caps.php';

/**
 * Alert store for operational events, and the one place that decides what an
 * operator is shown. Two calls, and the difference between them is the whole
 * doctrine:
 *
 *   raise() - something is WRONG and someone has to look: an overload, a
 *             broken hash chain, an abuse guard that escalated, live state
 *             replaced wholesale. Stores a dashboard row AND writes one
 *             "FOK alert <type>:" log line, both de-duplicated per type
 *             within alert_cooldown, so a sustained condition alerts once
 *             instead of thousands of times.
 *   note()  - something worth being able to read back, but needing no
 *             reaction: a rate limit that tripped once, an admin write, a
 *             login. A log line only - never a row, never de-duplicated.
 *
 * The rule between them is escalation: a condition that is ordinary once and
 * suspicious when it repeats is noted every time and raised only when the
 * repeat guard fires (see Friends::rateHit, Auth::login). That is what keeps
 * an unseen alert count meaning something.
 */
final class Alerts
{
    // TODO: pluggable delivery backends (Telegram / SMS / Email). Add a
    // dispatch($type, $message) call at the end of raise() that fans out
    // to configured backends; until then alerts are local-only (admin UI).

    /**
     * Record an operational alert: a dashboard row plus a server-log line.
     * Returns true when a FRESH alert was stored, false when it was suppressed
     * as a duplicate within alert_cooldown. Callers rarely need the return -
     * the log line is written here, not by them.
     */
    public static function raise(string $type, string $message): bool
    {
        $cooldown = Settings::int('alert_cooldown');
        // The de-duplication gate, in shared memory. A sustained condition
        // calls raise() from every request that observes it and all but one
        // are suppressed - but learning that from the alerts table means a
        // scan per suppressed call, on the hot path and, for a desync, from
        // inside a held long poll. apcu_add IS the test: it succeeds
        // for exactly one caller per window, so a suppressed repeat now
        // costs no SQL at all. A cooldown of 0 means "do not de-duplicate",
        // and a zero TTL would mean the opposite here, so it skips the gate
        // and leaves the decision to the query below.
        if ($cooldown > 0 && Caps::apcu()
            && !apcu_add(FOK_APCU_NS . 'alert:' . $type, 1, $cooldown)) {
            return false;
        }
        $db = Db::get();
        // Still the source of truth: shared memory can be flushed (a pool
        // restart, a full segment) and one condition must not become a burst
        // of rows because of it.
        $st = $db->prepare('SELECT 1 FROM alerts WHERE type = ? AND created > ? LIMIT 1');
        $st->execute([$type, time() - $cooldown]);
        $recent = $st->fetchColumn() !== false;
        $st->closeCursor();
        if ($recent) {
            return false;
        }
        Db::retry(static function () use ($db, $type, $message): void {
            $db->prepare('INSERT INTO alerts (type, message, created, seen) VALUES (?, ?, ?, 0)')
                ->execute([$type, $message, time()]);
        });
        // Every alert is also a log line: the dashboard shows the last 50 and
        // can be cleared, the log keeps the history and the exact time. The
        // "alert" word is what Logs::level reads to colour it as a warning.
        error_log('FOK alert ' . $type . ': ' . $message);
        return true;
    }

    /**
     * Record an operational note: one log line, no dashboard row, no
     * de-duplication. For events that must be readable back but must not
     * compete for attention with the alerts that need acting on.
     */
    public static function note(string $type, string $message): void
    {
        error_log('FOK ' . $type . ': ' . $message);
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
