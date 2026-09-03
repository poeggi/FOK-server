<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Settings.php';

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
            $st->closeCursor();
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

    /** True once $id has a players row: it has contacted the server at
     *  least once. Not the same as being online (see Presence::infoOf). */
    public static function exists(string $id): bool
    {
        $st = Db::get()->prepare('SELECT 1 FROM players WHERE id = ?');
        $st->execute([$id]);
        $found = $st->fetchColumn() !== false;
        $st->closeCursor();
        return $found;
    }

    /**
     * Per-id request throttle, independent of the unanswered-request spam
     * ban. Two scales: at most one request per friend_rate_interval seconds,
     * and a friend_rate_cooldown-second cooldown once friend_rate_burst
     * requests have gone through with no real pause. A too-fast request still
     * advances the streak, so a client hammering the endpoint trips the
     * cooldown and then backs off for the whole window instead of being
     * answered once a second forever. The streak clears after an idle gap of
     * one cooldown length. Call it once per "request" action; $id must have a
     * players row already (the caller's Presence::touch guarantees it).
     *
     * @return array{blocked: bool, retry: int, why: string, tripped: bool}
     *   why is 'interval', 'cooldown' or '' (allowed); retry is the seconds
     *   to wait; tripped is true only on the request that STARTS a cooldown.
     */
    public static function rateHit(string $id): array
    {
        $interval = Settings::int('friend_rate_interval');
        $burst = Settings::int('friend_rate_burst');
        $cooldown = Settings::int('friend_rate_cooldown');
        $db = Db::get();
        $now = time();
        // Fast path: a plain read (no writer lock in WAL) short-circuits a
        // client that is already cooling down - the common abusive case -
        // without contending for the single writer. The authoritative check
        // is repeated inside the transaction below against a race.
        $st = $db->prepare('SELECT friend_req_cooldown_until FROM players WHERE id = ?');
        $st->execute([$id]);
        $cd0 = (int)$st->fetchColumn();
        $st->closeCursor();
        if ($cd0 > $now) {
            return ['blocked' => true, 'retry' => $cd0 - $now, 'why' => 'cooldown', 'tripped' => false];
        }
        // Serialize the read-decide-write so two bursts cannot both slip past
        // the streak check (see Friends::request for the same guard).
        $db->exec('BEGIN IMMEDIATE');
        try {
            $st = $db->prepare(
                'SELECT friend_req_last, friend_req_streak, friend_req_cooldown_until FROM players WHERE id = ?'
            );
            $st->execute([$id]);
            $row = $st->fetch();
            $st->closeCursor();
            $last = (int)($row['friend_req_last'] ?? 0);
            $streak = (int)($row['friend_req_streak'] ?? 0);
            $cd = (int)($row['friend_req_cooldown_until'] ?? 0);
            if ($cd > $now) {
                $db->exec('COMMIT');
                return ['blocked' => true, 'retry' => $cd - $now, 'why' => 'cooldown', 'tripped' => false];
            }
            // A real pause (idle for a whole cooldown) starts the streak over.
            if ($last > 0 && $now - $last >= $cooldown) {
                $streak = 0;
            }
            $tooFast = $last > 0 && $now - $last < $interval;
            $streak++;
            $newCd = 0;
            $why = '';
            $tripped = false;
            if ($streak > $burst) {
                $newCd = $now + $cooldown;
                $why = 'cooldown';
                $tripped = true;
            } elseif ($tooFast) {
                $why = 'interval';
            }
            $db->prepare(
                'UPDATE players SET friend_req_last = ?, friend_req_streak = ?, friend_req_cooldown_until = ? WHERE id = ?'
            )->execute([$now, $streak, $newCd, $id]);
            $db->exec('COMMIT');
            if ($why === 'cooldown') {
                return ['blocked' => true, 'retry' => $cooldown, 'why' => 'cooldown', 'tripped' => $tripped];
            }
            if ($why === 'interval') {
                return ['blocked' => true, 'retry' => max(1, $interval), 'why' => 'interval', 'tripped' => false];
            }
            return ['blocked' => false, 'retry' => 0, 'why' => '', 'tripped' => false];
        } catch (Throwable $e) {
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
        $friends = $st->fetchColumn() !== false;
        $st->closeCursor();
        return $friends;
    }

    /** @return array set of ids (from $ids) that are accepted friends of $me */
    public static function acceptedOf(string $me, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        // One read instead of a SELECT per id (this runs on the hello hot
        // path): pull $me's accepted pairs and keep those that were asked
        // about. Pairs are normalized (a < b), so the peer is the side != $me.
        $want = array_flip($ids);
        $st = Db::get()->prepare(
            "SELECT a, b FROM friends WHERE state = 'accepted' AND (a = ? OR b = ?)"
        );
        $st->execute([$me, $me]);
        $out = [];
        foreach ($st->fetchAll() as $row) {
            $peer = $row['a'] === $me ? $row['b'] : $row['a'];
            if (isset($want[$peer])) {
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
