<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Presence.php';
require_once __DIR__ . '/ConnTrack.php';
require_once __DIR__ . '/Relay.php';
require_once __DIR__ . '/Load.php';
require_once __DIR__ . '/Vault.php';
require_once __DIR__ . '/PStats.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Ledger.php';
require_once __DIR__ . '/Signals.php';

/**
 * Read-only aggregation for the admin dashboard's two heaviest views - the
 * Statistics card and the per-client detail popup - each of which stitches
 * rows together from several subsystems. Kept out of admin/api.php so that
 * endpoint stays a thin dispatcher.
 */
final class AdminData
{
    // Every table the "DB entries" tile sums (see the Statistics card).
    // The signal mailbox and the presence-counter cache are not here: they
    // live in shared memory now, not in any table (see Signals).
    private const TABLES = ['players', 'scores', 'duels', 'mm_queue',
        'counters', 'alerts', 'settings', 'admin_fails', 'ipcount', 'friends',
        'relay', 'starts', 'conn', 'pstats', 'items', 'matches', 'ledger'];

    /** The Statistics card: live counts, stored totals and the load gauges. */
    public static function stats(): array
    {
        $db = Db::get();
        // The bucket of a traffic counter is a YmdH stamp, and this is a
        // STRING comparison - so a non-numeric bucket sharing the table
        // (Stats keeps the lifetime totals in one) sorts above every stamp
        // and would be drawn as an hour that never existed.
        $st = $db->prepare("SELECT bucket, metric, value FROM counters
                            WHERE bucket >= ? AND bucket GLOB '[0-9]*' ORDER BY bucket");
        $st->execute([gmdate('YmdH', time() - 24 * 3600)]);
        $load = [];
        foreach ($st->fetchAll() as $r) {
            $load[$r['bucket']][$r['metric']] = (int)$r['value'];
        }
        // One statement sums every table's rows (TABLES is a fixed allowlist,
        // never user input) instead of a COUNT query per table.
        $dbRows = (int)$db->query(
            'SELECT ' . implode(' + ', array_map(
                static fn($t) => "(SELECT COUNT(*) FROM $t)",
                self::TABLES
            ))
        )->fetchColumn();
        return [
            'counts' => Presence::counts(),
            'relaying' => Relay::activePairs(),
            'friendships' => (int)$db->query("SELECT COUNT(*) FROM friends WHERE state = 'accepted'")->fetchColumn(),
            'friendships_pending' => (int)$db->query("SELECT COUNT(*) FROM friends WHERE state = 'pending'")->fetchColumn(),
            'scores_total' => (int)$db->query('SELECT COUNT(*) FROM scores')->fetchColumn(),
            'items_total' => (int)$db->query('SELECT COUNT(*) FROM items')->fetchColumn(),
            // Every transfer bumps the instance seq, so summing seq counts the
            // handovers the current population has been through. Read from
            // items rather than the ledger because the ledger is checkpointed
            // and trimmed, which would make a ledger count drop over time.
            'item_transfers' => (int)$db->query('SELECT COALESCE(SUM(seq), 0) FROM items')->fetchColumn(),
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
     * The Registry card: item-ownership subsystem health (see Items, Ledger).
     * Read-only. Match SECRETS are never selected - a match row's sec_a/sec_b
     * are what authenticate a claim, so the dashboard counts open matches but
     * never returns a key. The ledger itself holds no secret. Lists are capped;
     * the frozen and disputed lists are the operator's forensic review queue.
     */
    public static function items(): array
    {
        $db = Db::get();
        $recent = [];
        foreach ($db->query(
            'SELECT n, kind, uid, from_id, to_id, mid, tick, at FROM ledger ORDER BY n DESC LIMIT 30'
        ) as $r) {
            $recent[] = ['n' => (int)$r['n'], 'kind' => $r['kind'], 'uid' => $r['uid'],
                'from' => $r['from_id'], 'to' => $r['to_id'], 'mid' => $r['mid'],
                'tick' => (int)$r['tick'], 'at' => (int)$r['at']];
        }
        $frozen = [];
        foreach ($db->query(
            'SELECT uid, item_id, owner, seq FROM items WHERE frozen = 1 ORDER BY minted DESC LIMIT 30'
        ) as $r) {
            $frozen[] = ['uid' => $r['uid'], 'item_id' => $r['item_id'],
                'owner' => $r['owner'], 'seq' => (int)$r['seq']];
        }
        $disputed = [];
        foreach ($db->query(
            'SELECT id, name, claims_ok, claims_untagged, claims_disputed FROM players
             WHERE claims_disputed > 0 ORDER BY claims_disputed DESC LIMIT 20'
        ) as $r) {
            $disputed[] = ['id' => $r['id'], 'name' => $r['name'], 'ok' => (int)$r['claims_ok'],
                'untagged' => (int)$r['claims_untagged'], 'disputed' => (int)$r['claims_disputed']];
        }
        return [
            'now' => time(),
            'items_total' => (int)$db->query('SELECT COUNT(*) FROM items')->fetchColumn(),
            'items_frozen' => (int)$db->query('SELECT COUNT(*) FROM items WHERE frozen = 1')->fetchColumn(),
            'matches_open' => (int)$db->query('SELECT COUNT(*) FROM matches WHERE closed = 0')->fetchColumn(),
            'ledger_rows' => Ledger::rows($db),
            'ledger_max' => Settings::int('ledger_max_rows'),
            'recent' => $recent,
            'frozen' => $frozen,
            'disputed' => $disputed,
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
        $rate = Relay::rateDetail($id);
        $queue = self::one($db, 'SELECT since, matched_with FROM mm_queue WHERE id = ?', $id);
        $fr = $db->prepare("SELECT state, COUNT(*) c FROM friends WHERE a = ? OR b = ? GROUP BY state");
        $fr->execute([$id, $id]);
        $friends = ['accepted' => 0, 'pending' => 0];
        foreach ($fr->fetchAll() as $f) {
            $friends[$f['state']] = (int)$f['c'];
        }
        $fr->closeCursor();
        $scores = self::one($db, 'SELECT COUNT(*) c, MAX(score) best FROM scores WHERE player_id = ?', $id);
        $mailbox = Signals::pending($id);
        $backup = Vault::peek($id);
        $stats = PStats::get($id);
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
                'relay_rate' => $rate,
                'matchmaking' => $queue === null ? null
                    : ['since' => (int)$queue['since'], 'matched_with' => $queue['matched_with']],
                'friends' => $friends,
                'scores' => ['count' => (int)$scores['c'],
                    'best' => $scores['best'] === null ? null : (int)$scores['best']],
                'mailbox' => $mailbox,
                'backup' => $backup === null ? null
                    : ['updated' => $backup['updated'], 'bytes' => strlen($backup['payload']),
                        'enrolled' => $backup['enrolled']],
                'stats' => $stats,
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
