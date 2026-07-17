<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Settings.php';

/**
 * Store-and-forward mailbox for matchmaking and WebRTC signaling.
 * The server never interprets SDP/ICE payloads; it only relays them
 * between player IDs. The game traffic itself is peer-to-peer, except
 * in relay-fallback mode, which runs over relay.php - not through here.
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
     * Cheapest "anything for me?" check: one indexed read, no writes. The
     * TTL must match take()'s, or a long poll wakes on an expired message
     * and answers 200 with an empty list instead of holding for a real one.
     */
    public static function any(string $to): bool
    {
        $st = Db::get()->prepare('SELECT 1 FROM signals WHERE to_id = ? AND created >= ? LIMIT 1');
        $st->execute([$to, time() - Settings::int('signal_ttl')]);
        return $st->fetchColumn() !== false;
    }

    /**
     * Drops messages past signal_ttl. A NEEDS_RECEIPT message dying here
     * is a failed connection attempt, so its sender gets an 'undelivered'
     * signal naming the peer and the type - otherwise an invite nobody
     * picks up evaporates behind its ok:true and the inviter waits forever.
     */
    private static function expire(PDO $db): void
    {
        $cut = time() - Settings::int('signal_ttl');
        // DELETE ... RETURNING makes the receipt exactly-once: only the
        // request that wins the delete holds the rows. SELECT-then-DELETE
        // would let two racing mailbox reads both report the same loss.
        $st = $db->prepare('DELETE FROM signals WHERE created < ? RETURNING from_id, to_id, type');
        $st->execute([$cut]);
        $rows = $st->fetchAll();
        if ($rows === []) {
            return;
        }
        foreach ($rows as $r) {
            if (!in_array($r['type'], self::NEEDS_RECEIPT, true)) {
                continue;
            }
            // FROM the peer that never picked it up, so the client can
            // correlate it. Past the mailbox cap on purpose: a flood must
            // not swallow the message that says the connection failed.
            // Bounded - one per connection message the player sent itself.
            $db->prepare(
                'INSERT INTO signals (from_id, to_id, type, payload, created) VALUES (?, ?, ?, ?, ?)'
            )->execute([$r['to_id'], $r['from_id'], 'undelivered', (string)json_encode([
                'event' => 'undelivered',
                'peer' => $r['to_id'],
                'type' => $r['type'],
            ]), time()]);
        }
    }

    /**
     * Drains all pending messages for a player, oldest first. Read and
     * delete are one transaction: two overlapping polls (a retry over a
     * slow link) must never both be handed the same message - exactly-once
     * is the contract, and a replayed invite or input desyncs the game.
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
            // SQLite auto-rolls back on some faults; a bare ROLLBACK
            // would then throw and mask the real error.
            if ($db->inTransaction()) {
                $db->exec('ROLLBACK');
            }
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
