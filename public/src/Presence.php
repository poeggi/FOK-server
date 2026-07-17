<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Signals.php';
require_once __DIR__ . '/ConnTrack.php';

final class Presence
{
    public static function touch(string $id, string $ip, ?int $latency = null, ?string $name = null, ?bool $autoAccept = null): void
    {
        $now = time();
        // null leaves accept_until untouched (non-hello endpoints); hello
        // always passes a bool, so leaving the screen clears the flag.
        $acceptUntil = $autoAccept === null ? null
            : ($autoAccept ? $now + Settings::int('auto_accept_window') : 0);
        Db::get()->prepare(
            'INSERT INTO players (id, ip, first_seen, last_seen, hello_count, latency, name, accept_until)
             VALUES (?, ?, ?, ?, 1, ?, ?, COALESCE(?, 0))
             ON CONFLICT (id) DO UPDATE SET ip = excluded.ip, last_seen = excluded.last_seen,
                 hello_count = hello_count + 1,
                 latency = COALESCE(excluded.latency, players.latency),
                 name = COALESCE(excluded.name, players.name),
                 accept_until = COALESCE(?, players.accept_until)'
        )->execute([$id, $ip, $now, $now, $latency, $name, $acceptUntil, $acceptUntil]);
    }

    public static function isAutoAccepting(string $id): bool
    {
        $st = Db::get()->prepare('SELECT accept_until FROM players WHERE id = ?');
        $st->execute([$id]);
        return (int)$st->fetchColumn() > time();
    }

    public static function touchDuel(string $id, string $peer): void
    {
        [$a, $b] = $id < $peer ? [$id, $peer] : [$peer, $id];
        $now = time();
        Db::get()->prepare(
            'INSERT INTO duels (a, b, started, last_seen) VALUES (?, ?, ?, ?)
             ON CONFLICT (a, b) DO UPDATE SET last_seen = excluded.last_seen'
        )->execute([$a, $b, $now, $now]);
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

    public static function counts(): array
    {
        $db = Db::get();
        $now = time();
        $online = $db->prepare('SELECT COUNT(*) FROM players WHERE last_seen > ?');
        $online->execute([$now - FOK_ONLINE_WINDOW]);
        $duels = $db->prepare('SELECT COUNT(*) FROM duels WHERE last_seen > ?');
        $duels->execute([$now - FOK_DUEL_WINDOW]);
        $registered = $db->query('SELECT COUNT(*) FROM players');
        return [
            'online' => (int)$online->fetchColumn(),
            'playing' => 2 * (int)$duels->fetchColumn(),
            'registered' => (int)$registered->fetchColumn(),
        ];
    }
}
