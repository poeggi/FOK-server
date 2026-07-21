<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Presence.php';
require_once __DIR__ . '/ConnTrack.php';
require_once __DIR__ . '/Load.php';
require_once __DIR__ . '/Vault.php';

/**
 * Read-only aggregation for the admin dashboard's two heaviest views - the
 * Statistics card and the per-client detail popup - each of which stitches
 * rows together from several subsystems. Kept out of admin/api.php so that
 * endpoint stays a thin dispatcher.
 */
final class AdminData
{
    // Every table the "DB entries" tile sums (see the Statistics card).
    private const TABLES = ['players', 'scores', 'signals', 'duels', 'mm_queue',
        'counters', 'alerts', 'settings', 'admin_fails', 'ipcount', 'friends',
        'relay', 'starts', 'conn', 'stats'];

    /** The Statistics card: live counts, stored totals and the load gauges. */
    public static function stats(): array
    {
        $db = Db::get();
        $st = $db->prepare('SELECT bucket, metric, value FROM counters WHERE bucket >= ? ORDER BY bucket');
        $st->execute([gmdate('YmdH', time() - 24 * 3600)]);
        $load = [];
        foreach ($st->fetchAll() as $r) {
            $load[$r['bucket']][$r['metric']] = (int)$r['value'];
        }
        $dbRows = 0;
        foreach (self::TABLES as $table) {
            $dbRows += (int)$db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
        }
        return [
            'counts' => Presence::counts(),
            'relaying' => ConnTrack::relayPairs(),
            'friendships' => (int)$db->query("SELECT COUNT(*) FROM friends WHERE state = 'accepted'")->fetchColumn(),
            'friendships_pending' => (int)$db->query("SELECT COUNT(*) FROM friends WHERE state = 'pending'")->fetchColumn(),
            'scores_total' => (int)$db->query('SELECT COUNT(*) FROM scores')->fetchColumn(),
            'db_rows' => $dbRows,
            'load' => $load,
            'load_live' => Load::lastMinute(),   // totals over the last complete minute
            'db_size' => is_file(FOK_DB_FILE) ? filesize(FOK_DB_FILE) : 0,
            'php' => PHP_VERSION,
            'server_version' => FOK_SERVER_VERSION,
            'env' => FOK_ENV,
            'now' => time(),
        ];
    }

    /**
     * Everything known about one client for the detail popup - identity,
     * presence, its 1:1 state, relay/matchmaking/friend/score/mailbox
     * counters and its config backup. Null if the id is unknown. Read-only,
     * gathered from the tables each subsystem already keeps.
     */
    public static function client(string $id): ?array
    {
        $db = Db::get();
        $st = $db->prepare('SELECT id, name, ip, first_seen, last_seen, hello_count,
            latency, debug, debug_active, accept_until, friend_ban_until FROM players WHERE id = ?');
        $st->execute([$id]);
        $p = $st->fetch();
        $st->closeCursor();
        if ($p === false) {
            return null;
        }
        $now = time();
        $duel = ConnTrack::stateOf($id);
        if ($duel !== null) {
            $duel['age'] = $now - $duel['updated'];
            $duel['live'] = $duel['updated'] > $now - FOK_CONN_TTL;
        }
        $rate = self::one($db, 'SELECT total, blocked_until FROM relay_rate WHERE id = ?', $id);
        $queue = self::one($db, 'SELECT since, matched_with FROM mm_queue WHERE id = ?', $id);
        $fr = $db->prepare("SELECT state, COUNT(*) c FROM friends WHERE a = ? OR b = ? GROUP BY state");
        $fr->execute([$id, $id]);
        $friends = ['accepted' => 0, 'pending' => 0];
        foreach ($fr->fetchAll() as $f) {
            $friends[$f['state']] = (int)$f['c'];
        }
        $fr->closeCursor();
        $scores = self::one($db, 'SELECT COUNT(*) c, MAX(score) best FROM scores WHERE player_id = ?', $id);
        $mb = $db->prepare('SELECT COUNT(*) FROM signals WHERE to_id = ?');
        $mb->execute([$id]);
        $mailbox = (int)$mb->fetchColumn();
        $mb->closeCursor();
        $backup = Vault::peek($id);
        return [
            'now' => $now,
            'online_window' => FOK_ONLINE_WINDOW,
            'client' => [
                'id' => $p['id'],
                'name' => $p['name'],
                'ip' => $p['ip'],
                'first_seen' => (int)$p['first_seen'],
                'last_seen' => (int)$p['last_seen'],
                'hello_count' => (int)$p['hello_count'],
                'latency' => $p['latency'] === null ? null : (int)$p['latency'],
                'online' => (int)$p['last_seen'] > $now - FOK_ONLINE_WINDOW,
                'debug' => (int)$p['debug'] === 1,
                'debug_active' => (int)$p['debug_active'] === 1,
                'accept_until' => (int)$p['accept_until'],
                'friend_ban_until' => (int)$p['friend_ban_until'],
                'duel' => $duel,
                'relay_rate' => $rate === null ? null
                    : ['total' => (int)$rate['total'], 'blocked_until' => (int)$rate['blocked_until']],
                'matchmaking' => $queue === null ? null
                    : ['since' => (int)$queue['since'], 'matched_with' => $queue['matched_with']],
                'friends' => $friends,
                'scores' => ['count' => (int)$scores['c'],
                    'best' => $scores['best'] === null ? null : (int)$scores['best']],
                'mailbox' => $mailbox,
                'backup' => $backup === null ? null
                    : ['updated' => $backup['updated'], 'bytes' => strlen($backup['payload']),
                        'enrolled' => $backup['enrolled']],
            ],
        ];
    }

    /** One id-keyed row (or null), cursor closed - the shape client() repeats. */
    private static function one(PDO $db, string $sql, string $id): ?array
    {
        $st = $db->prepare($sql);
        $st->execute([$id]);
        $row = $st->fetch();
        $st->closeCursor();
        return $row === false ? null : $row;
    }
}
