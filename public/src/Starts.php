<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Util.php';
require_once __DIR__ . '/Items.php';
require_once __DIR__ . '/Stats.php';

/**
 * Server-issued starts. The server owns the PTS clock, so it owns every
 * moment play begins or resumes: the first start, the next level, a
 * respawn after a death and a resume from pause all halt the run, and
 * the moment it picks back up comes from here - never from whichever
 * peer happened to notice first.
 *
 * Both peers NAME the start they mean with a shared epoch, so the answer
 * does not depend on WHEN either of them asks. A peer that asks late
 * gets the same PTS, already in the past, and knows exactly how late it
 * is. Keyed by pair alone it would instead have raced the very moment it
 * was asking about and been handed a different start, with both players
 * then running from different origins and nothing reporting it.
 *
 * The epoch is game state, not a server invention: deterministic lockstep
 * means both peers count the halts identically, so they arrive at the
 * same number without anyone being authoritative. Peers that disagree get
 * a stale-epoch rejection rather than a quiet desync.
 */
final class Starts
{
    // Every start BEGINS play. The halts within a run - next level, respawn,
    // resume from pause - are settled peer-to-peer over the DataChannel and
    // never reach the server, so both of these mint a match of their own and
    // both must prove a fresh sync (see start.php).
    public const REASONS = ['first', 'rematch'];

    // How long a stored start can still be THIS start. Both peers ask within
    // milliseconds of the DataChannel opening on their end, and the lead is a
    // second, so anything older belongs to the pair's PREVIOUS match - and
    // handing its moment to a new game would begin that game in the past.
    //
    // This is what the epoch's ordering used to do. It cannot any more: a
    // rematch names epoch 0 exactly as a first start does, and with the in-run
    // halts gone nothing ever advances the epoch above it, so "a higher stored
    // epoch" - the old test for a leftover line - can no longer happen.
    private const PAIR_WINDOW_MS = 5000;

    // How long the ROW itself is kept, which is a different question and a
    // much longer one: matchInfo reads the pair's mid off it with no window
    // at all, and an item claim may attest against that match well after the
    // duel goes quiet. Housekeeping's horizon, not the pairing window.
    private const KEEP_MS = 300000;

    // One predicate for the sweep and for the card that promises it; they
    // drift apart the moment there are two (see Housekeeping).
    private const PRUNE_WHERE = 'FROM starts WHERE start_pts < ?';

    /**
     * Ends the pair's epoch line. The epoch counts halts WITHIN one
     * connection, so it has to reset when the connection does - otherwise
     * the pair's next duel would open at epoch 0 and be refused as stale
     * forever. Pair-scoped like everything bye touches: bye is not
     * friendship-gated, so a stranger must not reach a duel it is not in.
     */
    public static function forget(string $id, string $peer): void
    {
        [$a, $b] = $id < $peer ? [$id, $peer] : [$peer, $id];
        // This runs on the signaling path, beside the pair's own start, so it
        // is the write most likely to want the writer at the moment somebody
        // else has it. One statement, and re-runnable: it removes a row.
        Db::retry(static function () use ($a, $b): void {
            Db::get()->prepare('DELETE FROM starts WHERE a = ? AND b = ?')->execute([$a, $b]);
        });
    }

    /**
     * Drops start rows nothing can reach any more. Nothing depends on the
     * deletion being prompt - request() stopped treating them as this pair's
     * start long before, at PAIR_WINDOW_MS - so this is ordinary housekeeping
     * on the hour (see Housekeeping), not a whole-table DELETE under the
     * writer lock on the path that issues starts.
     */
    public static function prune(PDO $db, int $nowMs): int
    {
        $st = $db->prepare('DELETE ' . self::PRUNE_WHERE);
        $st->execute([$nowMs - self::KEEP_MS]);
        return $st->rowCount();
    }

    /** What the next prune() would take, for the Housekeeping card. */
    public static function pruneable(PDO $db, int $nowMs): int
    {
        $st = $db->prepare('SELECT COUNT(*) ' . self::PRUNE_WHERE);
        $st->execute([$nowMs - self::KEEP_MS]);
        $n = (int)$st->fetchColumn();
        $st->closeCursor();
        return $n;
    }

    /**
     * The pair's start row, treating one outside the pairing window as absent.
     *
     * @return array<string, mixed>|false
     */
    private static function read(PDO $db, string $a, string $b, int $nowMs): array|false
    {
        $st = $db->prepare(
            'SELECT epoch, reason, start_pts, mid FROM starts
              WHERE a = ? AND b = ? AND start_pts >= ?'
        );
        $st->execute([$a, $b, $nowMs - self::PAIR_WINDOW_MS]);
        $row = $st->fetch();
        $st->closeCursor();
        return $row;
    }

    /**
     * The start this caller is asking about, if the stored row already IS it -
     * the second peer of a pair, or either peer asking again. Null means the
     * caller has to issue one.
     *
     * Three things have to agree, and each rules out a different way of being
     * handed the wrong moment. The EPOCH, so the two peers are naming one
     * start. The REASON, because a rematch names epoch 0 just as the first
     * start did and is the only thing on the wire that says "a new game, not
     * the one you have" - a relay rematch reuses the hub with no new offer,
     * so nothing clears the old row for it (see signal.php). And the WINDOW
     * on read(), which catches the case neither covers: a rematch after a
     * rematch, identical in both fields.
     *
     * @param array<string, mixed>|false $row
     * @return array{0:int}|null
     */
    private static function settled(array|false $row, int $epoch, string $reason): ?array
    {
        if ($row === false) {
            return null;
        }
        if ((int)$row['epoch'] === $epoch && (string)$row['reason'] === $reason) {
            return [(int)$row['start_pts']];
        }
        return null;
    }

    /**
     * The pair's start PTS for this (epoch, reason): issued on the first
     * request, repeated verbatim to the second peer. Every start begins play,
     * so every one of them mints the pair a fresh match.
     */
    public static function request(string $id, string $peer, int $epoch, string $reason): int
    {
        [$a, $b] = $id < $peer ? [$id, $peer] : [$peer, $id];
        $db = Db::get();
        // Before any lock is taken: the first settings read of a request can
        // load the whole overrides table, and under the lock every other
        // writer on the server would be waiting for that too.
        //
        // The lead is one flat figure for every pair, never a function of
        // what either peer reported: a second clears any round trip a
        // playable connection has, and a start moment that depends on no
        // client-supplied number cannot be stretched by a polluted sample.
        $lead = Settings::int('start_lead_ms');

        // Answer without the writer lock wherever the stored row already
        // decides the answer. Both peers ask about the same start and every
        // repeat of either lands here, so this is the common case by far -
        // and none of it writes anything.
        $settled = self::settled(self::read($db, $a, $b, Util::nowMs()), $epoch, $reason);
        if ($settled !== null) {
            return $settled[0];
        }

        return (int)Db::retry(static function () use ($db, $a, $b, $epoch, $reason, $lead): int {
            $db->exec('BEGIN IMMEDIATE');
            try {
                // The clock is read AFTER the lock: what was spent waiting for
                // it must not come out of the lead the answer promises.
                $now = Util::nowMs();
                // And the row is read again under it. The unlocked read only
                // established that there was work to do; the peer may have
                // done it since, and then ITS start is the one both must get.
                $row = self::read($db, $a, $b, $now);
                $settled = self::settled($row, $epoch, $reason);
                if ($settled !== null) {
                    $db->exec('COMMIT');
                    return $settled[0];
                }
                $startPts = $now + $lead;

                // Every start begins play, so every one mints a fresh match -
                // here, in this same transaction, so it is atomic with the
                // start row and both peers read one consistent mid (see
                // Items::openMatch).
                $mid = Items::openMatch($db, $a, $b, $now)['mid'];

                $db->prepare(
                    'INSERT INTO starts (a, b, start_pts, created, epoch, reason, mid)
                     VALUES (?, ?, ?, ?, ?, ?, ?)
                     ON CONFLICT (a, b) DO UPDATE SET start_pts = excluded.start_pts,
                         created = excluded.created, epoch = excluded.epoch,
                         reason = excluded.reason, mid = excluded.mid'
                )->execute([$a, $b, $startPts, $now, $epoch, $reason, $mid]);
                // One duel, counted INSIDE the transaction that mints its
                // match rather than by taking the writer a second time the
                // moment this one lets go. Reached exactly once per duel,
                // because a repeat request is answered from the stored row
                // above. Only the START is countable: the server does not
                // reliably learn that a match ended (see forget).
                Stats::bumpIn($db, ['duel_started' => 1]);
                $db->exec('COMMIT');
                return $startPts;
            } catch (Throwable $e) {
                // SQLite auto-rolls back on some faults; a bare ROLLBACK would
                // then throw and mask the real error.
                if ($db->inTransaction()) {
                    $db->exec('ROLLBACK');
                }
                throw $e;
            }
        });
    }

    /**
     * The pair's open match as this caller sees it: its mid and the caller's
     * OWN secret only (never the peer's). start.php reads this after a start
     * is issued and returns both to the caller (see docs/API.md). Empty when
     * the pair has no open match - a lone in-run start with no begin behind
     * it, which a real duel never reaches.
     *
     * @return array{mid:string, secret:string}
     */
    public static function matchInfo(string $id, string $peer): array
    {
        [$a, $b] = $id < $peer ? [$id, $peer] : [$peer, $id];
        $db = Db::get();
        $st = $db->prepare('SELECT mid FROM starts WHERE a = ? AND b = ?');
        $st->execute([$a, $b]);
        $mid = (string)($st->fetchColumn() ?: '');
        $st->closeCursor();
        if ($mid === '') {
            return ['mid' => '', 'secret' => ''];
        }
        return ['mid' => $mid, 'secret' => Items::matchSecret($db, $mid, $id === $a)];
    }
}
