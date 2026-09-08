<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Caps.php';
require_once __DIR__ . '/Util.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Presence.php';

/**
 * Friend presence as a delta against a cursor (API 4.6, see docs/API.md).
 *
 * The four friend-facing screens ask "what changed since I last looked"
 * instead of naming their whole friend list and reading the whole status
 * table back. Everything the answer needs is in shared memory: the caller's
 * accepted ids, the friends' presence entries, and two stamps per caller
 * that make "nothing changed" a single fetch.
 *
 * Two kinds of change, and the difference is the whole design:
 *
 * - A TRANSITION happens at a moment (coming online, a rename, the edge into
 *   a duel). The player it happens to stamps its own entry and bumps the
 *   watch key of every accepted friend, which is also what wakes their held
 *   polls.
 * - A LAPSE is the absence of a beat (going offline, a duel going quiet).
 *   Nobody is running to write it, so it is derived from the same windows
 *   every other reader uses, at the moment the delta is read. due() is what
 *   keeps that cheap: the earliest moment a lapse could change this caller's
 *   answer, so until then the fetch above is the entire read.
 */
final class FriendFeed
{
    /** The caller's accepted friend ids: a friends-table read, cached. */
    private const LIST = FOK_APCU_NS . 'fl:';

    /** Last transition aimed at this caller, ms. */
    private const WATCH = FOK_APCU_NS . 'fw:';

    /** Earliest ms at which a lapse could change this caller's answer. */
    private const DUE = FOK_APCU_NS . 'fd:';

    /**
     * The list is dropped at every friends-table write, so this is a backstop
     * against a write path nobody remembered, not the mechanism.
     */
    private const LIST_TTL = 300;

    /** Longer than any cursor a client can plausibly still be holding. */
    private const STAMP_TTL = 3600;

    /** Stands in for "no lapse is coming" without special-casing the read. */
    private const NO_DUE = 86400000;

    /**
     * The caller's accepted friend ids. One read of the friends table per
     * caller per LIST_TTL at worst, and none at all in the steady state.
     * @return list<string>
     */
    public static function acceptedIds(string $me): array
    {
        if (Caps::apcu()) {
            $hit = apcu_fetch(self::LIST . $me, $ok);
            if ($ok && is_array($hit)) {
                return $hit;
            }
        }
        $st = Db::get()->prepare(
            "SELECT a, b FROM friends WHERE state = 'accepted' AND (a = ? OR b = ?)"
        );
        $st->execute([$me, $me]);
        $ids = [];
        foreach ($st->fetchAll() as $row) {
            $ids[] = $row['a'] === $me ? (string)$row['b'] : (string)$row['a'];
        }
        $st->closeCursor();
        if (Caps::apcu()) {
            apcu_store(self::LIST . $me, $ids, self::LIST_TTL);
        }
        return $ids;
    }

    /** Drops a cached list. Every write to the friends table calls this. */
    public static function forget(string $id): void
    {
        if (Caps::apcu()) {
            apcu_delete(self::LIST . $id);
        }
    }

    /**
     * Both sides of a friendship that just changed. A new friend is a change
     * of state to each of them, so both watches move too: the pair sees each
     * other on the next read rather than at the next transition.
     */
    public static function forgetPair(string $a, string $b): void
    {
        self::forget($a);
        self::forget($b);
        if (Caps::apcu()) {
            $now = Util::nowMs();
            apcu_store(self::WATCH . $a, $now, self::STAMP_TTL);
            apcu_store(self::WATCH . $b, $now, self::STAMP_TTL);
        }
    }

    /**
     * Announces a transition of $id to everyone who is allowed to see it.
     * Up to FOK_MAX_FRIENDS stores, on the rare request that opens a session
     * or enters a duel - never on a beat.
     */
    public static function bump(string $id, ?int $atMs = null): void
    {
        if (!Caps::apcu()) {
            return;
        }
        $at = $atMs ?? Util::nowMs();
        foreach (self::acceptedIds($id) as $friend) {
            apcu_store(self::WATCH . $friend, $at, self::STAMP_TTL);
        }
    }

    /**
     * Whether a read would find anything - the check a held poll runs beside
     * the mailbox. One fetch of the two stamps: a transition aimed at this
     * caller after the cursor, or a lapse whose moment has arrived.
     */
    public static function pending(string $me, int $cursor): bool
    {
        if (!Caps::apcu()) {
            return true;
        }
        $keys = apcu_fetch([self::WATCH . $me, self::DUE . $me]);
        if ((int)($keys[self::WATCH . $me] ?? 0) > $cursor) {
            return true;
        }
        $due = (int)($keys[self::DUE . $me] ?? 0);
        // No due stamp means nothing has computed one yet, and only a read
        // can. Saying "pending" is what makes that read happen.
        return $due === 0 || Util::nowMs() >= $due;
    }

    /**
     * The delta itself: every accepted friend whose state changed after
     * $cursor, each one whole. A cursor of 0 is "I know nothing" and answers
     * with all of them.
     *
     * @return array{rows: array<string, array>, at: int, more: bool}
     */
    public static function delta(string $me, int $cursor): array
    {
        $now = Util::nowMs();
        $ids = self::acceptedIds($me);
        if ($ids === []) {
            self::noteDue($me, $now + self::NO_DUE);
            return ['rows' => [], 'at' => $now, 'more' => false];
        }
        $entries = Presence::entriesOf($ids);
        $due = $now + self::NO_DUE;
        $found = [];
        $missing = [];
        foreach ($ids as $fid) {
            $e = $entries[$fid] ?? null;
            if ($e === null) {
                // Nothing in shared memory: offline, and unchanged since
                // before any cursor. It is in a full read and nowhere else.
                $missing[] = $fid;
                if ($cursor <= 0) {
                    $found[] = ['at' => 0, 'id' => $fid, 'state' => [
                        'online' => false, 'playing' => false,
                        'latency' => null, 'name' => null,
                    ]];
                }
                continue;
            }
            // The moment each state expires. Both are the windows every other
            // reader uses (Util::since), read from the other end.
            $offAt = ((int)$e['seen'] + FOK_ONLINE_WINDOW + FOK_BEAT_JITTER) * 1000;
            $duel = (int)($e['duel'] ?? 0);
            $endAt = $duel > 0 ? ($duel + FOK_DUEL_WINDOW + FOK_BEAT_JITTER) * 1000 : 0;
            $online = $now < $offAt;
            $playing = $endAt > 0 && $now < $endAt;
            // What the current state has been true SINCE: the transition that
            // set it, or the lapse that ended the last one.
            $at = (int)($e['chg'] ?? 0);
            if (!$online && $offAt > $at) {
                $at = $offAt;
            }
            if (!$playing && $endAt > $at) {
                $at = $endAt;
            }
            if ($online && $offAt < $due) {
                $due = $offAt;
            }
            if ($playing && $endAt < $due) {
                $due = $endAt;
            }
            if ($cursor <= 0 || $at > $cursor) {
                $found[] = ['at' => $at, 'id' => $fid, 'state' => [
                    'online' => $online,
                    'playing' => $playing,
                    // A latency says nothing about somebody who is not here.
                    'latency' => $online && $e['lat'] !== null ? (int)$e['lat'] : null,
                    'name' => $e['name'],
                ]];
            }
        }
        self::noteDue($me, $due);
        if ($cursor <= 0 && $missing !== []) {
            self::nameMissing($found, $missing);
        }
        return self::page($found, $now);
    }

    /**
     * Cuts the answer to the cap without ever splitting a stamp: the rows
     * that share the last one all travel with it. A page that split a tie
     * would strand the rest of that stamp for good, because the cursor the
     * client comes back with is already past them.
     *
     * @param list<array{at:int, id:string, state:array}> $found
     * @return array{rows: array<string, array>, at: int, more: bool}
     */
    private static function page(array $found, int $now): array
    {
        usort($found, static fn(array $x, array $y): int => $x['at'] <=> $y['at']);
        $cap = Settings::int('friends_delta_max');
        $rows = [];
        $last = 0;
        $more = false;
        foreach ($found as $row) {
            if (count($rows) >= $cap && $row['at'] !== $last) {
                $more = true;
                break;
            }
            $rows[$row['id']] = $row['state'];
            $last = $row['at'];
        }
        // The cursor to come back with: where the answer stopped when it was
        // cut short, and this moment when it was not.
        return ['rows' => $rows, 'at' => $more ? $last : $now, 'more' => $more];
    }

    /**
     * Names for friends with no entry, on a full read only. The row carries
     * a name because the client shows one; a player who has not been here
     * for a day has no entry left and only the table remembers.
     *
     * @param list<array{at:int, id:string, state:array}> $found
     * @param list<string> $missing
     */
    private static function nameMissing(array &$found, array $missing): void
    {
        $ph = implode(',', array_fill(0, count($missing), '?'));
        $st = Db::get()->prepare("SELECT id, name FROM players WHERE id IN ($ph)");
        $st->execute($missing);
        $names = [];
        foreach ($st->fetchAll() as $row) {
            $names[(string)$row['id']] = $row['name'];
        }
        $st->closeCursor();
        foreach ($found as &$row) {
            if ($row['state']['name'] === null && isset($names[$row['id']])) {
                $row['state']['name'] = $names[$row['id']];
            }
        }
        unset($row);
    }

    private static function noteDue(string $me, int $due): void
    {
        if (Caps::apcu()) {
            apcu_store(self::DUE . $me, $due, self::STAMP_TTL);
        }
    }

    /** Everything here is derived from the database, so a restore drops it. */
    public static function dropAll(): void
    {
        Caps::dropKeys(self::LIST);
        Caps::dropKeys(self::WATCH);
        Caps::dropKeys(self::DUE);
    }
}
