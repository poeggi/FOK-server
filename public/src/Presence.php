<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Util.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Signals.php';
require_once __DIR__ . '/ConnTrack.php';

final class Presence
{
    /**
     * Records the heartbeat and returns whether the server wants this
     * client in debug mode. $debugActive is what the client REPORTS it is
     * doing; null leaves the record alone (non-hello endpoints).
     *
     * The wish rides back on the same RETURNING as the registration check:
     * every hello goes through here, so reading it separately would put a
     * second query on the one path that must stay cheapest.
     */
    public static function touch(string $id, string $ip, ?int $latency = null, ?string $name = null, ?bool $autoAccept = null, ?bool $debugActive = null): bool
    {
        $now = time();
        // null leaves accept_until untouched (non-hello endpoints); hello
        // always passes a bool, so leaving the screen clears the flag.
        $acceptUntil = $autoAccept === null ? null
            : ($autoAccept ? $now + Settings::int('auto_accept_window') : 0);
        $active = $debugActive === null ? null : (int)$debugActive;
        $st = Db::get()->prepare(
            'INSERT INTO players (id, ip, first_seen, last_seen, hello_count, latency, name, accept_until, debug_active)
             VALUES (?, ?, ?, ?, 1, ?, ?, COALESCE(?, 0), COALESCE(?, 0))
             ON CONFLICT (id) DO UPDATE SET ip = excluded.ip, last_seen = excluded.last_seen,
                 hello_count = hello_count + 1,
                 latency = COALESCE(excluded.latency, players.latency),
                 name = COALESCE(excluded.name, players.name),
                 accept_until = COALESCE(?, players.accept_until),
                 debug_active = COALESCE(?, players.debug_active)
             RETURNING first_seen = last_seen AS registered, debug'
        );
        $st->execute([$id, $ip, $now, $now, $latency, $name, $acceptUntil, $active, $acceptUntil, $active]);
        $row = $st->fetch();
        // An INSERT ... RETURNING is a write: finish it before anything else
        // touches the database (see Db).
        $st->closeCursor();
        // Nobody may watch their own first hello report zero online, so a
        // registration drops the cache. The repeat heartbeats that are
        // virtually all the traffic leave it alone.
        if ((int)$row['registered'] === 1) {
            self::flushCounts();
        }
        return (int)$row['debug'] === 1;
    }

    /** Admin-set: what the server WANTS the client to do (see touch). */
    public static function setDebug(string $id, bool $on): void
    {
        Db::get()->prepare('UPDATE players SET debug = ? WHERE id = ?')->execute([(int)$on, $id]);
    }

    /** Forces the next counts() to recount (see the caching there). */
    public static function flushCounts(): void
    {
        Db::get()->exec('DELETE FROM stats');
    }

    public static function isAutoAccepting(string $id): bool
    {
        $st = Db::get()->prepare('SELECT accept_until FROM players WHERE id = ?');
        $st->execute([$id]);
        $until = (int)$st->fetchColumn();
        $st->closeCursor();
        return $until > time();
    }

    public static function touchDuel(string $id, string $peer): void
    {
        [$a, $b] = $id < $peer ? [$id, $peer] : [$peer, $id];
        $now = time();
        $st = Db::get()->prepare(
            'INSERT INTO duels (a, b, started, last_seen) VALUES (?, ?, ?, ?)
             ON CONFLICT (a, b) DO UPDATE SET last_seen = excluded.last_seen
             RETURNING started = last_seen'
        );
        $st->execute([$a, $b, $now, $now]);
        // A duel starting is visible; the heartbeats keeping it alive are not.
        $started = (int)$st->fetchColumn() === 1;
        $st->closeCursor();
        if ($started) {
            self::flushCounts();
        }
    }

    /** @return array map of id => [online: bool, latency: ?int, name: ?string] */
    public static function infoOf(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = Db::get()->prepare("SELECT id, last_seen, latency, name FROM players WHERE id IN ($ph)");
        $st->execute($ids);
        $cutoff = time() - FOK_ONLINE_WINDOW;
        $out = [];
        foreach ($st->fetchAll() as $row) {
            $online = (int)$row['last_seen'] > $cutoff;
            $out[$row['id']] = [
                'online' => $online,
                // A latency is only meaningful while the friend is online.
                'latency' => $online && $row['latency'] !== null ? (int)$row['latency'] : null,
                'name' => $row['name'],
            ];
        }
        return $out;
    }

    /**
     * Peer-net hint: at the moment a 1:1 pairing is confirmed (an accepted
     * invite, a fresh quick match) and BEFORE the P2P handshake, tell each
     * side the other's server-observed IP plus its own, so that two peers on
     * the same address family can try a direct connection first (see the
     * 'peer-net' signal in docs/API.md). Both IPs are read from the players
     * table - each side just touched its own row, so both are current. A
     * side the server has never seen is skipped: nothing to announce.
     */
    public static function announceNet(string $a, string $b): void
    {
        $st = Db::get()->prepare('SELECT id, ip FROM players WHERE id IN (?, ?)');
        $st->execute([$a, $b]);
        $ip = [];
        foreach ($st->fetchAll() as $row) {
            $ip[$row['id']] = (string)$row['ip'];
        }
        if (!isset($ip[$a], $ip[$b])) {
            return;
        }
        $na = Util::ipInfo($ip[$a]);
        $nb = Util::ipInfo($ip[$b]);
        self::sendNet($a, $b, $na, $nb);
        self::sendNet($b, $a, $nb, $na);
    }

    /** Queues one peer-net signal: $to learns $peer's net, plus its own. */
    private static function sendNet(string $to, string $peer, array $selfNet, array $peerNet): void
    {
        Signals::send($peer, $to, 'peer-net', (string)json_encode([
            'event' => 'peer-net',
            'peer' => $peer,
            'ip' => $peerNet['ip'],
            'family' => $peerNet['family'],
            'self_ip' => $selfNet['ip'],
            'self_family' => $selfNet['family'],
        ]));
    }

    /**
     * Removes players not seen for player_ttl_days (0 disables expiry):
     * their friendships are cancelled and each friend gets a best-effort
     * 'friend' {event:"expired"} signal (offline friends reconcile their
     * list against friend.php on next start). Scores remain as history.
     * @return int number of players removed
     */
    public static function expireStale(): int
    {
        $days = Settings::int('player_ttl_days');
        if ($days < 1) {
            return 0;
        }
        $db = Db::get();
        $st = $db->prepare('SELECT id FROM players WHERE last_seen < ?');
        $st->execute([time() - $days * 86400]);
        $expired = array_column($st->fetchAll(), 'id');
        foreach ($expired as $id) {
            $st = $db->prepare('SELECT a, b FROM friends WHERE a = ? OR b = ?');
            $st->execute([$id, $id]);
            foreach ($st->fetchAll() as $row) {
                $other = $row['a'] === $id ? $row['b'] : $row['a'];
                Signals::send($id, $other, 'friend', json_encode(['event' => 'expired', 'from' => $id]));
            }
            $db->prepare('DELETE FROM friends WHERE a = ? OR b = ?')->execute([$id, $id]);
            $db->prepare('DELETE FROM players WHERE id = ?')->execute([$id]);
            ConnTrack::forget($id);
        }
        return count($expired);
    }

    /**
     * Presence counters, cached for FOK_COUNTS_TTL seconds. Every hello
     * returns these, so counting rows here would make a heartbeat cost
     * more as the player base grows - the one thing that must not happen.
     * Nobody needs an exact count (online is a 60 s window anyway). The
     * recompute is unlocked: racing requests write the same numbers.
     */
    public static function counts(): array
    {
        $db = Db::get();
        $now = time();
        $st = $db->query('SELECT online, playing, registered, updated FROM stats WHERE id = 1');
        $row = $st->fetch();
        $st->closeCursor();
        if ($row !== false && (int)$row['updated'] > $now - FOK_COUNTS_TTL) {
            return [
                'online' => (int)$row['online'],
                'playing' => (int)$row['playing'],
                'registered' => (int)$row['registered'],
            ];
        }
        $online = $db->prepare('SELECT COUNT(*) FROM players WHERE last_seen > ?');
        $online->execute([$now - FOK_ONLINE_WINDOW]);
        $duels = $db->prepare('SELECT COUNT(*) FROM duels WHERE last_seen > ?');
        $duels->execute([$now - FOK_DUEL_WINDOW]);
        $onlineN = (int)$online->fetchColumn();
        $online->closeCursor();
        $duelsN = (int)$duels->fetchColumn();
        $duels->closeCursor();
        $out = [
            'online' => $onlineN,
            'playing' => 2 * $duelsN,
            'registered' => (int)$db->query('SELECT COUNT(*) FROM players')->fetchColumn(),
        ];
        $db->prepare(
            'INSERT INTO stats (id, online, playing, registered, updated) VALUES (1, ?, ?, ?, ?)
             ON CONFLICT (id) DO UPDATE SET online = excluded.online, playing = excluded.playing,
                 registered = excluded.registered, updated = excluded.updated'
        )->execute([$out['online'], $out['playing'], $out['registered'], $now]);
        return $out;
    }
}
