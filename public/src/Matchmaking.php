<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';

/**
 * Quick-match queue: pairs two waiting players into a duel. The longer-
 * waiting player becomes the "offerer" (creates the WebRTC offer and the
 * shared duel seed); the newcomer is the "answerer". After pairing, the
 * peers continue via the normal signaling flow (signal.php).
 */
final class Matchmaking
{
    /** @return array one of {waiting:true} | {matched:id, role:offerer|answerer} */
    public static function seek(string $id): array
    {
        $db = Db::get();
        $now = time();
        $db->exec('BEGIN IMMEDIATE');
        try {
            // Drop seekers that stopped polling and stale delivered matches.
            $db->prepare('DELETE FROM mm_queue WHERE (matched_with IS NULL AND last_poll < ?) OR since < ?')
                ->execute([$now - FOK_MATCH_WINDOW, $now - 300]);

            $st = $db->prepare('SELECT matched_with, role FROM mm_queue WHERE id = ?');
            $st->execute([$id]);
            $row = $st->fetch();
            if ($row && $row['matched_with'] !== null) {
                $db->prepare('DELETE FROM mm_queue WHERE id = ?')->execute([$id]);
                $db->exec('COMMIT');
                return ['matched' => $row['matched_with'], 'role' => $row['role']];
            }

            $db->prepare(
                'INSERT INTO mm_queue (id, since, last_poll) VALUES (?, ?, ?)
                 ON CONFLICT (id) DO UPDATE SET last_poll = excluded.last_poll'
            )->execute([$id, $now, $now]);

            $st = $db->prepare(
                'SELECT id FROM mm_queue WHERE id != ? AND matched_with IS NULL ORDER BY since LIMIT 1'
            );
            $st->execute([$id]);
            $peer = $st->fetchColumn();
            if ($peer === false) {
                $db->exec('COMMIT');
                return ['waiting' => true];
            }

            // The peer waited longer: it gets the offerer role and learns
            // about the match on its next seek poll.
            $db->prepare('UPDATE mm_queue SET matched_with = ?, role = ? WHERE id = ?')
                ->execute([$id, 'offerer', $peer]);
            $db->prepare('DELETE FROM mm_queue WHERE id = ?')->execute([$id]);
            $db->exec('COMMIT');
            return ['matched' => $peer, 'role' => 'answerer'];
        } catch (Throwable $e) {
            // SQLite auto-rolls back on some faults; a bare ROLLBACK
            // would then throw and mask the real error.
            if ($db->inTransaction()) {
                $db->exec('ROLLBACK');
            }
            throw $e;
        }
    }

    public static function cancel(string $id): void
    {
        Db::get()->prepare('DELETE FROM mm_queue WHERE id = ? AND matched_with IS NULL')->execute([$id]);
    }
}
