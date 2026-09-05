<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Caps.php';
require_once __DIR__ . '/Presence.php';
require_once __DIR__ . '/ConnTrack.php';
require_once __DIR__ . '/Relay.php';
require_once __DIR__ . '/Load.php';
require_once __DIR__ . '/Vault.php';
require_once __DIR__ . '/PStats.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Ledger.php';
require_once __DIR__ . '/Signals.php';
require_once __DIR__ . '/Counters.php';
require_once __DIR__ . '/TourneyStore.php';

/**
 * Read-only aggregation for the admin dashboard's two heaviest views - the
 * Statistics card and the per-client detail popup - each of which stitches
 * rows together from several subsystems. Kept out of admin/api.php so that
 * endpoint stays a thin dispatcher.
 */
final class AdminData
{
    /** The Game Statistics card: live counts, stored totals and the gauges. */
    public static function stats(): array
    {
        $db = Db::get();
        return [
            'counts' => Presence::counts(),
            'families' => Presence::families(),
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
            'db_rows' => Db::rowCount(),
            'live' => self::live(),
            // Live tournaments are held in shared memory, not in a table.
            'tourneys' => TourneyStore::usable() ? count(TourneyStore::all()) : 0,
            'db_size' => is_file(FOK_DB_FILE) ? filesize(FOK_DB_FILE) : 0,
            'apcu_mem' => self::apcuMem(),
            'php' => PHP_VERSION,
            'server_version' => FOK_SERVER_VERSION,
            'env' => FOK_ENV,
            'now' => time(),
        ];
    }

    /**
     * How full the shared memory segment is. Not an optimization gauge: the
     * signal mailbox, the relay hub and the presence cache live there and
     * have no database transport, so a full segment is an outage rather
     * than a slowdown (see Caps::apcu).
     */
    private static function apcuMem(): array
    {
        $sma = Caps::apcu() ? apcu_sma_info(true) : false;
        if (!is_array($sma)) {
            return ['used' => 0, 'total' => 0];
        }
        $total = (int)($sma['num_seg'] ?? 0) * (int)($sma['seg_size'] ?? 0);
        return ['used' => $total - (int)($sma['avail_mem'] ?? 0), 'total' => $total];
    }

    /**
     * The last 24 hours of traffic, one row per UTC hour: what each endpoint
     * was asked for and what it cost (see Counters::cost). The Server
     * performance card reads this, and only while one of its two history
     * tabs is open - it is the one payload here that grows with the number
     * of endpoints.
     *
     * Hour buckets only. The same table also holds the per-minute request
     * totals until they are pruned, and a minute stamp is not an hour: it is
     * two digits longer, and drawn as an hour it is a row of zeroes for an
     * hour that never existed. Non-numeric buckets (the lifetime totals, the
     * meta rows) sort above every stamp in a string comparison, hence GLOB
     * as well.
     */
    public static function hours(): array
    {
        $st = Db::get()->prepare("SELECT bucket, metric, value FROM counters
                                  WHERE bucket >= ? AND bucket GLOB '[0-9]*'
                                    AND length(bucket) = 10
                                    AND metric NOT GLOB 'mint_*'
                                  ORDER BY bucket");
        $st->execute([gmdate('YmdH', time() - 24 * 3600)]);
        $hours = [];
        foreach ($st->fetchAll() as $r) {
            $hours[$r['bucket']][$r['metric']] = (int)$r['value'];
        }
        return ['now' => time(), 'hours' => $hours];
    }

    /**
     * The two windows the Live tab offers, each a COMPLETE one so the figure
     * is a whole window every time it is read instead of a number climbing
     * from zero: the last full minute and the last full hour. The same
     * measurements in both, because a tile that mixes them - some per minute,
     * some per hour - makes the operator do the conversion in their head.
     */
    private static function live(): array
    {
        // A closed minute is buffered in shared memory until some request
        // folds it in (see Counters). On a quiet server that request may not
        // have come yet, so ask for the fold here - otherwise the dashboard
        // would read a minute that is still in APCu.
        Counters::flushDue();
        return [
            'min' => ['stamp' => gmdate('H:i', time() - 60)]
                + self::window(gmdate('YmdHi', time() - 60)),
            'hour' => ['stamp' => gmdate('H', time() - 3600) . ':00']
                + self::window(gmdate('YmdH', time() - 3600)),
        ];
    }

    /**
     * One counter bucket, summed over the endpoints: the requests served, the
     * gauges they accumulated, the worker time they held, the CPU they burned,
     * the queries they caused, and which endpoint held the most worker time.
     * Metric shapes are laid out in Counters: a bare name is an endpoint's
     * request count, "n:" a counted total, "g:" a sampled level, and a dotted
     * suffix the cost of the endpoint before the dot.
     */
    private static function window(string $bucket): array
    {
        $st = Db::get()->prepare('SELECT metric, value FROM counters WHERE bucket = ?');
        $st->execute([$bucket]);
        $out = ['in' => 0, 'out' => 0, 'db_writes' => 0, 'wall_ms' => 0, 'cpu_ms' => 0,
            'db' => 0, 'top' => null, 'top_ms' => 0];
        foreach ($st->fetchAll() as $r) {
            $metric = (string)$r['metric'];
            $v = (int)$r['value'];
            if ($metric === 'n:msg_out') {
                $out['out'] = $v;
                continue;
            }
            if ($metric === 'n:db_w') {
                $out['db_writes'] = $v;
                continue;
            }
            // req_min is the same requests counted once more, as a total; the
            // levels and the mint buckets are not requests at all.
            if (str_contains($metric, ':') || str_starts_with($metric, 'mint_')
                || $metric === 'req_min') {
                continue;
            }
            $dot = strrpos($metric, '.');
            if ($dot === false) {
                $out['in'] += $v;
                continue;
            }
            switch (substr($metric, $dot + 1)) {
                case 'ms':
                    $out['wall_ms'] += $v;
                    if ($v > $out['top_ms']) {
                        $out['top_ms'] = $v;
                        $out['top'] = substr($metric, 0, $dot);
                    }
                    break;
                case 'cpu':
                    $out['cpu_ms'] += $v;
                    break;
                case 'db':
                    $out['db'] += $v;
                    break;
            }
        }
        return $out;
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
