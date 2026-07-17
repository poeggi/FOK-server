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
    // Client-sendable types. 'friend' (friendship notifications) and
    // 'undelivered' (see expire()) are server-generated and deliberately
    // NOT in this list, so a client cannot forge them.
    public const TYPES = ['invite', 'invite-relay', 'accept', 'accept-relay', 'decline', 'offer', 'answer', 'ice', 'bye', 'chat'];

    // Types that establish a connection: the sender is waiting for an
    // answer, so it MUST be told when one of these expires undelivered.
    private const NEEDS_RECEIPT = ['invite', 'invite-relay', 'accept', 'accept-relay'];

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

    /**
     * Cheapest possible "anything for me?" check: one indexed read, no
     * writes. It must apply the same TTL take() does, or a long poll would
     * wake on an already-expired message and answer 200 with an empty
     * list instead of holding for the real one.
     */
    public static function any(string $to): bool
    {
        $st = Db::get()->prepare('SELECT 1 FROM signals WHERE to_id = ? AND created >= ? LIMIT 1');
        $st->execute([$to, time() - Settings::int('signal_ttl')]);
        return $st->fetchColumn() !== false;
    }

    /**
     * Drops messages past signal_ttl. An expiring message that a peer was
     * WAITING for an answer to (see NEEDS_RECEIPT) is a failed connection
     * attempt, so the sender is told: it gets a server-generated
     * 'undelivered' signal naming the peer and the message type. Without
     * it an invite that nobody picks up just evaporates behind an
     * ok:true, and the inviter waits forever.
     */
    private static function expire(PDO $db): void
    {
        $cut = time() - Settings::int('signal_ttl');
        $st = $db->prepare('SELECT from_id, to_id, type FROM signals WHERE created < ?');
        $st->execute([$cut]);
        $rows = $st->fetchAll();
        if ($rows === []) {
            return;
        }
        $db->prepare('DELETE FROM signals WHERE created < ?')->execute([$cut]);
        foreach ($rows as $r) {
            if (!in_array($r['type'], self::NEEDS_RECEIPT, true)) {
                continue;
            }
            // Addressed FROM the peer that never picked it up, so the
            // client correlates the receipt to its pending attempt.
            self::send($r['to_id'], $r['from_id'], 'undelivered', (string)json_encode([
                'event' => 'undelivered',
                'peer' => $r['to_id'],
                'type' => $r['type'],
            ]));
        }
    }

    /**
     * Drains and returns all pending messages for a player, oldest first.
     * The read and the delete are one transaction: two overlapping polls
     * by the same client (a retry over a slow link) must never both be
     * handed the same message - "exactly once" is the API contract, and a
     * replayed invite or input desyncs the game.
     */
    public static function take(string $to): array
    {
        $db = Db::get();
        self::expire($db);
        $db->exec('BEGIN IMMEDIATE');
        try {
            $st = $db->prepare('SELECT id, from_id, type, payload, created FROM signals WHERE to_id = ? ORDER BY id');
            $st->execute([$to]);
            $rows = $st->fetchAll();
            if ($rows !== []) {
                $ids = array_column($rows, 'id');
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $db->prepare("DELETE FROM signals WHERE id IN ($ph)")->execute($ids);
            }
            $db->exec('COMMIT');
        } catch (Throwable $e) {
            $db->exec('ROLLBACK');
            throw $e;
        }
        return array_map(static fn(array $r) => [
            'from' => $r['from_id'],
            'type' => $r['type'],
            'payload' => $r['payload'],
            'created' => (int)$r['created'],
        ], $rows);
    }
}
