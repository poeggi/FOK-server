<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Settings.php';

/**
 * Store-and-forward mailbox for matchmaking and WebRTC signaling.
 * The server never interprets SDP/ICE payloads; it only relays them
 * between player IDs. Game traffic itself is peer-to-peer.
 */
final class Signals
{
    // Client-sendable types. 'friend' is reserved for server-generated
    // friendship notifications and is deliberately NOT in this list.
    public const TYPES = ['invite', 'invite-relay', 'accept', 'accept-relay', 'decline', 'offer', 'answer', 'ice', 'bye', 'chat'];

    /** @return bool false when the recipient's mailbox is full (flood cap) */
    public static function send(string $from, string $to, string $type, string $payload): bool
    {
        $db = Db::get();
        $st = $db->prepare('SELECT COUNT(*) FROM signals WHERE to_id = ? AND created >= ?');
        $st->execute([$to, time() - Settings::int('signal_ttl')]);
        if ((int)$st->fetchColumn() >= Settings::int('mailbox_cap')) {
            return false;
        }
        $db->prepare(
            'INSERT INTO signals (from_id, to_id, type, payload, created) VALUES (?, ?, ?, ?, ?)'
        )->execute([$from, $to, $type, $payload, time()]);
        return true;
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
        $db->prepare('DELETE FROM signals WHERE created < ?')->execute([time() - Settings::int('signal_ttl')]);
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
