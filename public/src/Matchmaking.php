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
            // GC dead rows - seekers that stopped polling, stale delivered
            // matches - on a sampled fraction of seeks (see
            // FOK_MATCH_PRUNE_SAMPLE). Pairing correctness does not depend on
            // it: the peer-select below refuses a stale seeker outright.
            if (random_int(1, FOK_MATCH_PRUNE_SAMPLE) === 1) {
                $db->prepare('DELETE FROM mm_queue WHERE (matched_with IS NULL AND last_poll < ?) OR since < ?')
                    ->execute([$now - FOK_MATCH_WINDOW, $now - 300]);
            }

            $st = $db->prepare('SELECT matched_with, role FROM mm_queue WHERE id = ?');
            $st->execute([$id]);
            $row = $st->fetch();
            $st->closeCursor();
            if ($row && $row['matched_with'] !== null) {
                $db->prepare('DELETE FROM mm_queue WHERE id = ?')->execute([$id]);
                $db->exec('COMMIT');
                return ['matched' => $row['matched_with'], 'role' => $row['role']];
            }

            $db->prepare(
                'INSERT INTO mm_queue (id, since, last_poll) VALUES (?, ?, ?)
                 ON CONFLICT (id) DO UPDATE SET last_poll = excluded.last_poll'
            )->execute([$id, $now, $now]);

            // A stale seeker (stopped polling) is never handed out as a
            // match, whether or not this seek pruned it: the liveness
            // predicate is what keeps a live seeker from pairing with a ghost.
            $st = $db->prepare(
                'SELECT id FROM mm_queue
                     WHERE id != ? AND matched_with IS NULL AND last_poll > ?
                     ORDER BY since LIMIT 1'
            );
            $st->execute([$id, $now - FOK_MATCH_WINDOW]);
            $peer = $st->fetchColumn();
            $st->closeCursor();
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
        // Collides with every seeker still pairing. Removing a row is
        // re-runnable, so a lost race for the writer is worth another try
        // rather than a 500 on a cancel the client cannot repeat.
        Db::retry(static function () use ($id): void {
            Db::get()->prepare('DELETE FROM mm_queue WHERE id = ? AND matched_with IS NULL')
                ->execute([$id]);
        });
    }
}
