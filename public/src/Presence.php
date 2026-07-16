<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

final class Presence
{
    public static function touch(string $id, string $ip): void
    {
        $now = time();
        Db::get()->prepare(
            'INSERT INTO players (id, ip, first_seen, last_seen, hello_count) VALUES (?, ?, ?, ?, 1)
             ON CONFLICT (id) DO UPDATE SET ip = excluded.ip, last_seen = excluded.last_seen,
                 hello_count = hello_count + 1'
        )->execute([$id, $ip, $now, $now]);
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

    /** @return array map of id => true for the given ids that are online */
    public static function onlineOf(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = Db::get()->prepare("SELECT id FROM players WHERE id IN ($ph) AND last_seen > ?");
        $st->execute([...$ids, time() - FOK_ONLINE_WINDOW]);
        $online = [];
        foreach ($st->fetchAll() as $row) {
            $online[$row['id']] = true;
        }
        return $online;
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
