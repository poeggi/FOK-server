<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Caps.php';
require_once __DIR__ . '/Presence.php';
require_once __DIR__ . '/ConnTrack.php';
require_once __DIR__ . '/Matchmaking.php';
require_once __DIR__ . '/Relay.php';
require_once __DIR__ . '/Load.php';
require_once __DIR__ . '/Vault.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Ledger.php';
require_once __DIR__ . '/Items.php';
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
            // The worst queue waits on record, with what caused them. Read
            // here rather than off the history payload so the gauge's popup
            // has them the moment it opens (see Counters::worst).
            'q_worst' => self::worstNamed(Counters::worstList('q_us')),
            // ...and the slowest database accesses, read the same way.
            'db_worst' => self::worstNamed(Counters::worstList('db_us')),
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
     * Hour buckets only in "hours". The same table also holds the per-minute
     * request totals until they are pruned, and a minute stamp is not an
     * hour: it is two digits longer, and drawn as an hour it is a row of
     * zeroes for an hour that never existed. Non-numeric buckets (the
     * lifetime totals, the meta rows) sort above every stamp in a string
     * comparison, hence GLOB as well.
     *
     * "totals" and "minute" ride along beside it for the per-script view's
     * window selector, which is why one payload answers all three.
     */
    public static function hours(): array
    {
        // The per-script view offers a last-minute window off this same
        // payload, and the newest closed minute may still be buffered in
        // shared memory (see minutes()).
        Counters::flushDue();
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
        // The three windows the per-script view offers, in one payload: the
        // running totals, the 24 h of hour buckets the graphs are drawn from
        // (the last complete hour is one of them), and the last complete
        // minute. The minute is a single bucket, so carrying it here costs
        // one small query and saves a second round trip per refresh.
        return ['now' => time(), 'hours' => $hours, 'totals' => self::scriptTotals(),
            'minute' => self::bucket(gmdate('YmdHi', time() - 60))];
    }

    /**
     * The last 60 minutes of traffic, one row per UTC minute, in the shape
     * hours() returns. The Live tab's graphs follow its window selector, so
     * a per-minute reading opens a per-minute history - an hour bucket next
     * to a "/min" figure is sixty times the number the operator just read.
     *
     * Minute buckets only, and twelve digits is what tells them from the ten
     * of an hour (see hours()). They are kept for two hours before they are
     * pruned (see Util::watch), so a full hour of them is always there.
     *
     * The sampled LEVELS are not in here at all: they are written once an
     * hour under the hour bucket (see Counters::sampleGauges), so a gauge
     * that reads a level keeps its 24 h graph in either window.
     */
    public static function minutes(): array
    {
        // The newest closed minutes may still be buffered in shared memory,
        // and those are exactly the ones the graph ends on (see live()).
        Counters::flushDue();
        $st = Db::get()->prepare("SELECT bucket, metric, value FROM counters
                                  WHERE bucket >= ? AND bucket GLOB '[0-9]*'
                                    AND length(bucket) = 12
                                    AND metric NOT GLOB 'mint_*'
                                  ORDER BY bucket");
        $st->execute([gmdate('YmdHi', time() - 3600)]);
        $minutes = [];
        foreach ($st->fetchAll() as $r) {
            $minutes[$r['bucket']][$r['metric']] = (int)$r['value'];
        }
        return ['now' => time(), 'minutes' => $minutes];
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
    /**
     * Metrics the live window carries through as they are, metric => field.
     *
     * The three pairs are a sum beside a count, useful only divided into
     * each other: the queue wait a request served out before it started
     * (Load::queueUs), the time spent taking the single writer, and the time
     * spent running statements (Load::noteTime). The mean is worked out at
     * the end and the pair does not travel to the browser. An "x:" beside a
     * pair is the worst single case of the bucket, which a mean hides.
     */
    private const DIRECT = [
        'n:msg_out' => 'out',
        'n:db_w' => 'db_writes',
        'n:db_skip' => 'db_skip',
        'n:q_us' => 'q_us',
        'n:q_n' => 'q_n',
        'x:q_us' => 'q_max_us',
        'n:dbw_us' => 'dbw_us',
        'n:dbw_n' => 'dbw_n',
        'x:dbw_us' => 'dbw_max_us',
        'n:dbt_us' => 'dbt_us',
        'n:dbt_n' => 'dbt_n',
        'x:dbt_us' => 'dbt_max_us',
    ];

    private static function window(string $bucket): array
    {
        $out = ['in' => 0, 'out' => 0, 'db_writes' => 0, 'wall_ms' => 0, 'cpu_ms' => 0,
            'db' => 0, 'top' => null, 'top_ms' => 0];
        foreach (self::DIRECT as $field) {
            $out[$field] = 0;
        }
        foreach (self::bucket($bucket) as $metric => $v) {
            if (isset(self::DIRECT[$metric])) {
                $out[self::DIRECT[$metric]] = $v;
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
        $out['q_mean_us'] = self::mean($out['q_us'], $out['q_n']);
        $out['dbw_mean_us'] = self::mean($out['dbw_us'], $out['dbw_n']);
        $out['dbt_mean_us'] = self::mean($out['dbt_us'], $out['dbt_n']);
        return $out;
    }

    private static function mean(int $sum, int $n): int
    {
        return $n > 0 ? (int)round($sum / $n) : 0;
    }

    /** One bucket's metrics, in the metric => value shape hours() keys by. */
    private static function bucket(string $bucket): array
    {
        $st = Db::get()->prepare('SELECT metric, value FROM counters WHERE bucket = ?');
        $st->execute([$bucket]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[(string)$r['metric']] = (int)$r['value'];
        }
        return $out;
    }

    /**
     * Per-endpoint totals over every hour bucket the table still holds - the
     * "Total" window of the per-script view. Honest horizon rather than a
     * lifetime: thirty days, because that is when Util::watch drops an hour
     * bucket, and the view says so rather than implying all time.
     *
     * Summed in SQLite rather than in the browser: the alternative is
     * shipping a month of buckets across to add up four numbers per endpoint.
     * Only the endpoint counters travel - a "g:" level and an "x:" maximum
     * would both be nonsense once added up, and the view discards them
     * anyway (see Counters).
     */
    private static function scriptTotals(): array
    {
        $st = Db::get()->query("SELECT metric, SUM(value) AS v FROM counters
                                WHERE bucket GLOB '[0-9]*' AND length(bucket) = 10
                                  AND metric NOT GLOB 'mint_*'
                                  AND metric NOT GLOB '*:*'
                                GROUP BY metric");
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[(string)$r['metric']] = (int)$r['v'];
        }
        return $out;
    }

    // Each worst-wait row names the client that caused it, and the table is
    // read about people, so the name rides along (see namesFor).
    private static function worstNamed(array $rows): array
    {
        $names = self::namesFor(array_map(static fn($r) => $r['id'] ?? '', $rows));
        foreach ($rows as &$r) {
            $r['name'] = $names[(string)($r['id'] ?? '')] ?? null;
        }
        unset($r);
        return $rows;
    }

    /**
     * Names for a set of player ids. Every table here is keyed on ids and
     * every one of them is read about PEOPLE, so an id on screen carries the
     * name it resolves to. Missing where the player row is gone -
     * Presence::forget keeps what somebody owned and drops the person - and
     * missing is a real answer, not an error.
     *
     * @param array<int, mixed> $ids
     * @return array<string, string>
     */
    public static function namesFor(array $ids): array
    {
        // Where player identity lives, so the client-facing event
        // roster and this one answer the same question the same way.
        return Presence::namesFor($ids);
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
        // Newest verdict first, and the owner's name rides along: the registry
        // is keyed on ids, but a review queue is read about people. The join
        // is outer because an owner expired by Presence::forget keeps the
        // item and loses the row, so the name can be null.
        $frozen = [];
        foreach ($db->query(
            'SELECT i.uid, i.item_id, i.owner, i.seq, i.frozen_at, i.frozen_why, p.name
               FROM items i LEFT JOIN players p ON p.id = i.owner
              WHERE i.frozen = 1 ORDER BY i.frozen_at DESC, i.minted DESC LIMIT 30'
        ) as $r) {
            $frozen[] = ['uid' => $r['uid'], 'item_id' => $r['item_id'],
                'owner' => $r['owner'], 'name' => $r['name'], 'seq' => (int)$r['seq'],
                'at' => (int)$r['frozen_at'], 'why' => (string)$r['frozen_why']];
        }
        // The queue is what an operator has NOT reviewed, not everything that
        // ever happened: the tally never moves backwards, so without the seen
        // mark beside it a finding dealt with years ago sits here for good.
        // What was found stays readable per player (see disputes below).
        $disputed = [];
        foreach ($db->query(
            'SELECT id, name, claims_ok, claims_untagged, claims_disputed, claims_disputed_seen
               FROM players WHERE claims_disputed > claims_disputed_seen
              ORDER BY claims_disputed - claims_disputed_seen DESC LIMIT 20'
        ) as $r) {
            $disputed[] = ['id' => $r['id'], 'name' => $r['name'], 'ok' => (int)$r['claims_ok'],
                'untagged' => (int)$r['claims_untagged'], 'disputed' => (int)$r['claims_disputed'],
                'open' => (int)$r['claims_disputed'] - (int)$r['claims_disputed_seen']];
        }
        $parties = [];
        foreach ($recent as $r) {
            $parties[] = $r['from'];
            $parties[] = $r['to'];
        }
        return [
            'now' => time(),
            'names' => (object)self::namesFor($parties),
            'items_total' => (int)$db->query('SELECT COUNT(*) FROM items')->fetchColumn(),
            'items_frozen' => (int)$db->query('SELECT COUNT(*) FROM items WHERE frozen = 1')->fetchColumn(),
            'matches_open' => Items::openMatches($db),
            'ledger_rows' => Ledger::rows($db),
            'ledger_max' => Settings::int('ledger_max_rows'),
            'recent' => $recent,
            'frozen' => $frozen,
            'disputed' => $disputed,
        ];
    }

    /**
     * Every tampering verdict recorded against one player, for the popup an
     * operator opens off the review queue. `logged` is how many of the
     * player's disputes this list can actually account for: a finding from
     * before the log existed (schema 42) left nothing but the tally, and
     * saying so is better than an empty list that reads like a bug.
     *
     * Read-only. Resolving is two separate acts and neither happens here:
     * an instance still frozen is released through item_resolve, and the
     * review itself through Items::reviewDisputes.
     */
    public static function disputes(string $player): ?array
    {
        $st = Db::get()->prepare(
            'SELECT name, claims_ok, claims_untagged, claims_disputed, claims_disputed_seen
               FROM players WHERE id = ?'
        );
        $st->execute([$player]);
        $row = $st->fetch();
        $st->closeCursor();
        if ($row === false) {
            return null;
        }
        $rows = Items::disputesOf($player);
        return [
            'id' => $player,
            'name' => $row['name'],
            'ok' => (int)$row['claims_ok'],
            'untagged' => (int)$row['claims_untagged'],
            'disputed' => (int)$row['claims_disputed'],
            'reviewed' => (int)$row['claims_disputed_seen'],
            'logged' => count($rows),
            'disputes' => $rows,
        ];
    }

    /**
     * One instance in full, for the operator deciding what happens to a
     * frozen one: the registry row, the owner's name where a player row
     * still exists, and what the ledger still holds about this uid. Null
     * when the registry does not know the uid.
     *
     * The ledger is checkpointed and truncated, so an old instance can have
     * no history left. It is shown for what it is - an audit trail, never
     * the answer to who owns the thing, which is the items row and only it.
     */
    public static function item(string $uid): ?array
    {
        $db = Db::get();
        $st = $db->prepare(
            'SELECT i.uid, i.item_id, i.owner, i.seq, i.origin, i.minted, i.frozen,
                    i.frozen_at, i.frozen_why, p.name
               FROM items i LEFT JOIN players p ON p.id = i.owner WHERE i.uid = ?'
        );
        $st->execute([$uid]);
        $row = $st->fetch();
        $st->closeCursor();
        if ($row === false) {
            return null;
        }
        $history = [];
        $st = $db->prepare(
            'SELECT n, kind, from_id, to_id, mid, tick, at FROM ledger
              WHERE uid = ? ORDER BY n DESC LIMIT 50'
        );
        $st->execute([$uid]);
        $rows = $st->fetchAll();
        $st->closeCursor();
        foreach ($rows as $r) {
            $history[] = ['n' => (int)$r['n'], 'kind' => $r['kind'], 'from' => $r['from_id'],
                'to' => $r['to_id'], 'mid' => $r['mid'], 'tick' => (int)$r['tick'],
                'at' => (int)$r['at']];
        }
        // The parties this instance has been between, named. The registry is
        // keyed on ids, but the operator deciding where it goes next is
        // reading about people - and a player row can be gone (Presence::forget
        // keeps the property and drops the person), so a name can be missing.
        $ids = [(string)$row['owner']];
        foreach ($history as $h) {
            $ids[] = $h['from'];
            $ids[] = $h['to'];
        }
        $names = self::namesFor($ids);
        return [
            'item' => [
                'uid' => $row['uid'],
                'item_id' => $row['item_id'],
                'owner' => $row['owner'],
                'name' => $row['name'],
                'seq' => (int)$row['seq'],
                'origin' => $row['origin'],
                'minted' => (int)$row['minted'],
                'frozen' => (int)$row['frozen'] === 1,
                'frozen_at' => (int)$row['frozen_at'],
                'frozen_why' => (string)$row['frozen_why'],
            ],
            'history' => $history,
            // An object even when empty, so the client can index it either way.
            'names' => (object)$names,
        ];
    }

    /**
     * Everything known about one client for the detail popup - identity,
     * presence, its 1vs1 state, relay/matchmaking/friend/score/mailbox
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
        // What is true right now is in the entry; the row holds the session.
        $e = Presence::entryOf($id);
        $duel = ConnTrack::stateOf($id);
        if ($duel !== null) {
            $duel['age'] = $now - $duel['updated'];
            $duel['live'] = $duel['updated'] >= Util::since(FOK_CONN_TTL, $now);
        }
        $rate = Relay::rateDetail($id);
        $queue = Matchmaking::stateOf($id);
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
        return [
            'now' => $now,
            // The window as it is checked, grace second included, so the
            // card and the server agree on who is online.
            'online_window' => FOK_ONLINE_WINDOW + FOK_BEAT_JITTER,
            'names' => (object)self::namesFor([
                (string)($duel['peer'] ?? ''), (string)($queue['matched_with'] ?? ''),
            ]),
            'client' => [
                'id' => $p['id'],
                'name' => $e['name'] ?? $p['name'],
                'ip' => $e['ip'] ?? $p['ip'],
                'first_seen' => (int)$p['first_seen'],
                'last_seen' => (int)($e['seen'] ?? $p['last_seen']),
                'hello_count' => (int)$p['hello_count'],
                'latency' => $e !== null ? $e['lat'] : ($p['latency'] === null ? null : (int)$p['latency']),
                'online' => $e !== null && (int)$e['seen'] >= Util::since(FOK_ONLINE_WINDOW, $now),
                'debug' => (int)$p['debug'] === 1,
                'debug_active' => $e !== null ? (bool)$e['dbg'] : (int)$p['debug_active'] === 1,
                'accept_until' => (int)($e['accept'] ?? $p['accept_until']),
                'friend_ban_until' => (int)$p['friend_ban_until'],
                'duel' => $duel,
                'relay_rate' => $rate,
                'matchmaking' => $queue,
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
