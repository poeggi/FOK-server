<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

/**
 * Store-and-forward mailbox for matchmaking and WebRTC signaling.
 * The server never interprets SDP/ICE payloads; it only relays them
 * between player IDs. Game traffic itself is peer-to-peer.
 */
final class Signals
{
    public const TYPES = ['invite', 'accept', 'decline', 'offer', 'answer', 'ice', 'bye'];

    public static function send(string $from, string $to, string $type, string $payload): void
    {
        Db::get()->prepare(
            'INSERT INTO signals (from_id, to_id, type, payload, created) VALUES (?, ?, ?, ?, ?)'
        )->execute([$from, $to, $type, $payload, time()]);
    }

    /** Cheapest possible "anything for me?" check: one indexed read, no writes. */
    public static function any(string $to): bool
    {
        $st = Db::get()->prepare('SELECT 1 FROM signals WHERE to_id = ? LIMIT 1');
        $st->execute([$to]);
        return $st->fetchColumn() !== false;
    }

    /** Drains and returns all pending messages for a player, oldest first. */
    public static function take(string $to): array
    {
        $db = Db::get();
        $db->prepare('DELETE FROM signals WHERE created < ?')->execute([time() - FOK_SIGNAL_TTL]);
        $st = $db->prepare('SELECT id, from_id, type, payload, created FROM signals WHERE to_id = ? ORDER BY id');
        $st->execute([$to]);
        $rows = $st->fetchAll();
        if ($rows !== []) {
            $ids = array_column($rows, 'id');
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $db->prepare("DELETE FROM signals WHERE id IN ($ph)")->execute($ids);
        }
        return array_map(static fn(array $r) => [
            'from' => $r['from_id'],
            'type' => $r['type'],
            'payload' => $r['payload'],
            'created' => (int)$r['created'],
        ], $rows);
    }
}
