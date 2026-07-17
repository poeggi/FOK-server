<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';

/**
 * Server-tracked friendship relations (mutual handshake). A recorded,
 * ACCEPTED friendship is what entitles a client to query the friend's
 * status (online, latency, name) and to send game invites. Pairs are
 * stored normalized (a < b) with the requester noted, so a pending row
 * knows which side still has to accept.
 */
final class Friends
{
    /**
     * @return array{state: string, changed: bool} state is 'pending' or
     * 'accepted'; changed is true only when this call created or
     * completed the relation (callers notify the peer exactly then).
     */
    public static function request(string $me, string $peer): array
    {
        [$a, $b] = $me < $peer ? [$me, $peer] : [$peer, $me];
        $db = Db::get();
        $now = time();
        // BEGIN IMMEDIATE serializes the read-decide-write so two crossing
        // requests (A->B and B->A at once) cannot both insert the same
        // (a,b) key: one records pending, the other sees it and matches.
        $db->exec('BEGIN IMMEDIATE');
        try {
            $st = $db->prepare('SELECT state, requester FROM friends WHERE a = ? AND b = ?');
            $st->execute([$a, $b]);
            $row = $st->fetch();
            if ($row) {
                if ($row['state'] === 'accepted') {
                    $db->exec('COMMIT');
                    return ['state' => 'accepted', 'changed' => false];
                }
                if ($row['requester'] !== $me) {
                    // The peer asked first; my request answers it.
                    $db->prepare('UPDATE friends SET state = ?, updated = ? WHERE a = ? AND b = ?')
                        ->execute(['accepted', $now, $a, $b]);
                    $db->exec('COMMIT');
                    return ['state' => 'accepted', 'changed' => true];
                }
                $db->exec('COMMIT');
                return ['state' => 'pending', 'changed' => false];
            }
            $db->prepare(
                'INSERT INTO friends (a, b, state, requester, created, updated) VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$a, $b, 'pending', $me, $now, $now]);
            $db->exec('COMMIT');
            return ['state' => 'pending', 'changed' => true];
        } catch (Throwable $e) {
            // SQLite auto-rolls back on some faults; a bare ROLLBACK
            // would then throw and mask the real error.
            if ($db->inTransaction()) {
                $db->exec('ROLLBACK');
            }
            throw $e;
        }
    }

    /**
     * Promotes a pending relation to accepted regardless of who asked -
     * used for the server-side auto-accept (peer is on the QR screen).
     */
    public static function forceAccept(string $me, string $peer): void
    {
        [$a, $b] = $me < $peer ? [$me, $peer] : [$peer, $me];
        Db::get()->prepare(
            'UPDATE friends SET state = ?, updated = ? WHERE a = ? AND b = ? AND state = ?'
        )->execute(['accepted', time(), $a, $b, 'pending']);
    }

    /** Accept a request the peer made; false when there is none. */
    public static function accept(string $me, string $peer): bool
    {
        [$a, $b] = $me < $peer ? [$me, $peer] : [$peer, $me];
        $st = Db::get()->prepare(
            'UPDATE friends SET state = ?, updated = ? WHERE a = ? AND b = ? AND state = ? AND requester = ?'
        );
        $st->execute(['accepted', time(), $a, $b, 'pending', $peer]);
        return $st->rowCount() > 0;
    }

    /** Removes the relation entirely (declines a request or unfriends). */
    public static function remove(string $me, string $peer): void
    {
        [$a, $b] = $me < $peer ? [$me, $peer] : [$peer, $me];
        Db::get()->prepare('DELETE FROM friends WHERE a = ? AND b = ?')->execute([$a, $b]);
    }

    public static function isFriend(string $me, string $peer): bool
    {
        [$a, $b] = $me < $peer ? [$me, $peer] : [$peer, $me];
        $st = Db::get()->prepare('SELECT 1 FROM friends WHERE a = ? AND b = ? AND state = ?');
        $st->execute([$a, $b, 'accepted']);
        return $st->fetchColumn() !== false;
    }

    /** @return array set of ids (from $ids) that are accepted friends of $me */
    public static function acceptedOf(string $me, array $ids): array
    {
        $out = [];
        foreach ($ids as $peer) {
            if (self::isFriend($me, $peer)) {
                $out[$peer] = true;
            }
        }
        return $out;
    }

    /** @return array all relations of $me: [{id, state, outgoing}] */
    public static function listOf(string $me): array
    {
        $st = Db::get()->prepare(
            'SELECT a, b, state, requester FROM friends WHERE a = ? OR b = ? ORDER BY updated DESC'
        );
        $st->execute([$me, $me]);
        $out = [];
        foreach ($st->fetchAll() as $row) {
            $out[] = [
                'id' => $row['a'] === $me ? $row['b'] : $row['a'],
                'state' => $row['state'],
                'outgoing' => $row['requester'] === $me,
            ];
        }
        return $out;
    }
}
