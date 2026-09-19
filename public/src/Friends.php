<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Caps.php';
require_once __DIR__ . '/FriendFeed.php';
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
        // Re-runnable: the read under the lock decides, and a lost race for
        // the writer rolls back before anything is written.
        return Db::retry(static function () use ($db, $a, $b, $me, $now): array {
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
                        FriendFeed::forgetPair($a, $b);
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
        });
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
     * ban. Three scales: at most one request per friend_rate_interval
     * seconds; a friend_rate_cooldown-second cooldown once friend_rate_burst
     * requests have gone through with no real pause; and, for a persistent
     * abuser, an escalated friend_rate_cooldown_hard cooldown when that burst
     * is tripped AGAIN within friend_rate_repeat_window seconds of the last
     * trip. A too-fast request still advances the streak, so a client
     * hammering the endpoint trips the cooldown and then backs off for the
     * whole window instead of being answered once a second forever. The
     * streak clears after an idle gap of one cooldown length; the last-trip
     * marker outlives it, so the escalation window spans across the first
     * cooldown. Call it once per "request" action; $id must have a players
     * row already (the caller's Presence::touch guarantees it).
     *
     * @return array{blocked: bool, retry: int, why: string, tripped: bool, escalated: bool}
     *   why is 'banned' (the spam ban, see friend.php), 'interval',
     *   'cooldown' or '' (allowed); retry is the seconds to wait; tripped is
     *   true only on the request that STARTS a cooldown; escalated is true
     *   only when that trip landed the long cooldown.
     */
    public static function rateHit(string $id): array
    {
        $interval = Settings::int('friend_rate_interval');
        $burst = Settings::int('friend_rate_burst');
        $cooldown = Settings::int('friend_rate_cooldown');
        $repeatWindow = Settings::int('friend_rate_repeat_window');
        $cooldownHard = Settings::int('friend_rate_cooldown_hard');
        $db = Db::get();
        $now = time();
        // Fast path: a plain read (no writer lock in WAL) short-circuits a
        // client that is already cooling down - the common abusive case -
        // without contending for the single writer. The authoritative check
        // is repeated inside the transaction below against a race. The spam
        // ban (see friend.php) is read off the same row and outranks the
        // throttle: a banned id is turned away here, before any transaction,
        // so it costs no writer take however hard it hammers.
        $st = $db->prepare('SELECT friend_req_cooldown_until, friend_ban_until FROM players WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        $st->closeCursor();
        $ban = (int)($row['friend_ban_until'] ?? 0);
        if ($ban > $now) {
            return ['blocked' => true, 'retry' => $ban - $now, 'why' => 'banned', 'tripped' => false, 'escalated' => false];
        }
        $cd0 = (int)($row['friend_req_cooldown_until'] ?? 0);
        if ($cd0 > $now) {
            return ['blocked' => true, 'retry' => $cd0 - $now, 'why' => 'cooldown', 'tripped' => false, 'escalated' => false];
        }
        // Serialize the read-decide-write so two bursts cannot both slip past
        // the streak check (see Friends::request for the same guard).
        // Re-runnable: everything is read again under the lock.
        return Db::retry(static function () use ($db, $id, $now, $interval, $burst, $cooldown, $repeatWindow, $cooldownHard): array {
            $db->exec('BEGIN IMMEDIATE');
            try {
                $st = $db->prepare(
                    'SELECT friend_req_last, friend_req_streak, friend_req_cooldown_until, friend_req_last_trip FROM players WHERE id = ?'
                );
                $st->execute([$id]);
                $row = $st->fetch();
                $st->closeCursor();
                $last = (int)($row['friend_req_last'] ?? 0);
                $streak = (int)($row['friend_req_streak'] ?? 0);
                $cd = (int)($row['friend_req_cooldown_until'] ?? 0);
                $lastTrip = (int)($row['friend_req_last_trip'] ?? 0);
                if ($cd > $now) {
                    $db->exec('COMMIT');
                    return ['blocked' => true, 'retry' => $cd - $now, 'why' => 'cooldown', 'tripped' => false, 'escalated' => false];
                }
                // A real pause (idle for a whole cooldown) starts the streak over.
                if ($last > 0 && $now - $last >= $cooldown) {
                    $streak = 0;
                }
                $tooFast = $last > 0 && $now - $last < $interval;
                $streak++;
                $newCd = 0;
                $newTrip = $lastTrip;
                $why = '';
                $tripped = false;
                $escalated = false;
                $dur = $cooldown;
                if ($streak > $burst) {
                    // A burst trip. If this id already tripped within the
                    // repeat window, it came straight back and burst again -
                    // a persistent abuser - so escalate from the short
                    // cooldown to the long one.
                    $escalated = $lastTrip > 0 && $now - $lastTrip < $repeatWindow;
                    $dur = $escalated ? $cooldownHard : $cooldown;
                    $newCd = $now + $dur;
                    $newTrip = $now;
                    $why = 'cooldown';
                    $tripped = true;
                } elseif ($tooFast) {
                    $why = 'interval';
                }
                $db->prepare(
                    'UPDATE players SET friend_req_last = ?, friend_req_streak = ?, friend_req_cooldown_until = ?, friend_req_last_trip = ? WHERE id = ?'
                )->execute([$now, $streak, $newCd, $newTrip, $id]);
                $db->exec('COMMIT');
                if ($why === 'cooldown') {
                    return ['blocked' => true, 'retry' => $dur, 'why' => 'cooldown', 'tripped' => $tripped, 'escalated' => $escalated];
                }
                if ($why === 'interval') {
                    return ['blocked' => true, 'retry' => max(1, $interval), 'why' => 'interval', 'tripped' => false, 'escalated' => false];
                }
                return ['blocked' => false, 'retry' => 0, 'why' => '', 'tripped' => false, 'escalated' => false];
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->exec('ROLLBACK');
                }
                throw $e;
            }
        });
    }

    /**
     * Promotes a pending relation to accepted regardless of who asked -
     * used for the server-side auto-accept (peer is on the QR screen).
     */
    public static function forceAccept(string $me, string $peer): void
    {
        [$a, $b] = $me < $peer ? [$me, $peer] : [$peer, $me];
        Db::retry(static fn() => Db::get()->prepare(
            'UPDATE friends SET state = ?, updated = ? WHERE a = ? AND b = ? AND state = ?'
        )->execute(['accepted', time(), $a, $b, 'pending']));
        FriendFeed::forgetPair($a, $b);
    }

    /** Accept a request the peer made; false when there is none. */
    public static function accept(string $me, string $peer): bool
    {
        [$a, $b] = $me < $peer ? [$me, $peer] : [$peer, $me];
        $moved = (bool)Db::retry(static function () use ($a, $b, $peer): bool {
            $st = Db::get()->prepare(
                'UPDATE friends SET state = ?, updated = ? WHERE a = ? AND b = ? AND state = ? AND requester = ?'
            );
            $st->execute(['accepted', time(), $a, $b, 'pending', $peer]);
            return $st->rowCount() > 0;
        });
        FriendFeed::forgetPair($a, $b);
        return $moved;
    }

    /** Removes the relation entirely (declines a request or unfriends). */
    /** @return bool whether a row - a friendship or a pending request - went */
    public static function remove(string $me, string $peer): bool
    {
        [$a, $b] = $me < $peer ? [$me, $peer] : [$peer, $me];
        $gone = (bool)Db::retry(static function () use ($a, $b): bool {
            $st = Db::get()->prepare('DELETE FROM friends WHERE a = ? AND b = ?');
            $st->execute([$a, $b]);
            return $st->rowCount() > 0;
        });
        FriendFeed::forgetPair($a, $b);
        return $gone;
    }

    // ---------------------------------------------------------------
    // Moderation (docs/API.md, "Moderation"): blocks and reports
    // ---------------------------------------------------------------

    /** Per-id cache of the ids it blocked, the FriendFeed::acceptedIds shape. */
    private const BLOCKS = FOK_APCU_NS . 'bl:';
    private const BLOCKS_TTL = 300;

    /** What a report may say; the note is the operator's to add. */
    public const REPORT_REASONS = ['name', 'abuse', 'cheat', 'other'];

    /**
     * $me blocks $peer: the row, and any friendship or pending request
     * between the two goes with it. Idempotent.
     * @return bool whether a friendship or request was ended by it
     */
    public static function block(string $me, string $peer): bool
    {
        Db::retry(static fn() => Db::get()
            ->prepare('INSERT OR IGNORE INTO blocks (id, peer, created) VALUES (?, ?, ?)')
            ->execute([$me, $peer, time()]));
        self::forgetBlocks($me);
        return self::remove($me, $peer);
    }

    public static function unblock(string $me, string $peer): void
    {
        Db::retry(static fn() => Db::get()->prepare('DELETE FROM blocks WHERE id = ? AND peer = ?')
            ->execute([$me, $peer]));
        self::forgetBlocks($me);
    }

    /**
     * The ids $me blocked. Cached per id in shared memory, because the
     * question is asked on the signaling path (see isBlocked) where the
     * steady state opens no database; the table answers a cold cache.
     * @return list<string>
     */
    public static function blockedIds(string $me): array
    {
        if (Caps::apcu()) {
            $hit = apcu_fetch(self::BLOCKS . $me, $ok);
            if ($ok && is_array($hit)) {
                return $hit;
            }
        }
        $st = Db::get()->prepare('SELECT peer FROM blocks WHERE id = ? ORDER BY created DESC');
        $st->execute([$me]);
        $ids = array_map('strval', array_column($st->fetchAll(), 'peer'));
        $st->closeCursor();
        if (Caps::apcu()) {
            apcu_store(self::BLOCKS . $me, $ids, self::BLOCKS_TTL);
        }
        return $ids;
    }

    /** Whether either of the two blocked the other: the one gate, three call sites. */
    public static function isBlocked(string $a, string $b): bool
    {
        return in_array($b, self::blockedIds($a), true) || in_array($a, self::blockedIds($b), true);
    }

    /** Drops a cached block list. Every write to the blocks table calls this. */
    public static function forgetBlocks(string $id): void
    {
        if (Caps::apcu()) {
            apcu_delete(self::BLOCKS . $id);
        }
    }

    /**
     * $me reports $peer. One row per (reporter, target) per day: a second
     * report inside it moves the reason and the time onto the first, so a
     * tap repeated in anger is one line for the operator, not ten. The name
     * is the peer's at this moment, because it is often what was reported.
     * @return bool whether this is a NEW row (the alert is raised for those)
     */
    public static function report(string $me, string $peer, string $reason, ?string $name): bool
    {
        $now = time();
        return (bool)Db::retry(static function () use ($me, $peer, $reason, $name, $now): bool {
            $db = Db::get();
            $st = $db->prepare('UPDATE reports SET reason = ?, name = ?, created = ?
                WHERE reporter = ? AND target = ? AND created > ?');
            $st->execute([$reason, $name, $now, $me, $peer, $now - 86400]);
            if ($st->rowCount() > 0) {
                return false;
            }
            $db->prepare('INSERT INTO reports (reporter, target, name, reason, created) VALUES (?, ?, ?, ?, ?)')
                ->execute([$me, $peer, $name, $reason, $now]);
            return true;
        });
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


    /**
     * The caller's whole roster, decorated with the status of its accepted
     * half. THE one implementation of it: hello.php serves this under the
     * `friends_list` flag and friend.php serves it as the `list` action, and
     * a roster that differed between the two routes would be a roster no
     * client could trust.
     *
     * @return array listOf plus, per row: {name, online, latency}
     */
    public static function rosterOf(string $me): array
    {
        $list = self::listOf($me);
        $accepted = array_column(array_filter(
            $list,
            static fn(array $f): bool => $f['state'] === 'accepted'
        ), 'id');
        require_once __DIR__ . '/Presence.php';
        $info = Presence::infoOf($accepted);
        foreach ($list as &$f) {
            // Status belongs to an ACCEPTED friendship only. A pending row
            // says somebody asked; it does not entitle either side to see
            // whether the other is online.
            $peer = $f['state'] === 'accepted' ? ($info[$f['id']] ?? null) : null;
            $f['name'] = $peer['name'] ?? null;
            $f['online'] = $peer['online'] ?? false;
            $f['latency'] = $peer['latency'] ?? null;
        }
        return $list;
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
