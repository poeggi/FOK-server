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
    // Every one of these halts or restarts the run. Recorded per pair for
    // the admin view; the lead time does not depend on which it is.
    public const REASONS = ['first', 'level', 'respawn', 'resume', 'rematch'];

    // A start that BEGINS play - the first of a connection, or a rematch
    // on it - must prove a fresh sync so the pair enters the run aligned.
    // The in-run halts (level/respawn/resume) are exempt from the staleness
    // half of the gate: the pair is already synced from that first start,
    // and turning one away over a stale proof would break a live duel for
    // nothing but FPM queue delay. See start.php.
    public const SYNC_GATED_REASONS = ['first', 'rematch'];

    // A pair's start is forgotten this long after it passed. Only the
    // stale-epoch guard depends on the row, and a peer that far behind is
    // gone, not late.
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
        // Every bye runs this, so it collides with the duel traffic it is
        // ending. Both statements are re-runnable: one stamps a fixed time,
        // the other removes a row.
        Db::retry(static function () use ($a, $b): void {
            $db = Db::get();
            // Best-effort: stamp the match closed for forensics. Claims are
            // never gated on closed (the server does not reliably learn a
            // match ended), so this only records the ends it happens to see.
            Items::closeMatch($db, $a, $b, Util::nowMs());
            $db->prepare('DELETE FROM starts WHERE a = ? AND b = ?')->execute([$a, $b]);
        });
    }

    /**
     * Drops start rows the stale-epoch guard can no longer reach. Nothing
     * depends on the deletion being prompt - request() already treats a row
     * older than KEEP_MS as absent - so this is ordinary housekeeping on the
     * hour (see Housekeeping), not a whole-table DELETE under the writer lock
     * on the path that issues starts.
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
     * The pair's start row, treating one older than KEEP_MS as absent.
     *
     * @return array<string, mixed>|false
     */
    private static function read(PDO $db, string $a, string $b, int $nowMs): array|false
    {
        $st = $db->prepare(
            'SELECT epoch, start_pts, mid FROM starts WHERE a = ? AND b = ? AND start_pts >= ?'
        );
        $st->execute([$a, $b, $nowMs - self::KEEP_MS]);
        $row = $st->fetch();
        $st->closeCursor();
        return $row;
    }

    /**
     * What the stored row alone already decides, wrapped in a one-element
     * array so a refusal (a null answer) stays distinguishable from "nothing
     * decided yet". Null means this caller has to issue the start itself.
     *
     * @param array<string, mixed>|false $row
     * @return array{0:?int}|null
     */
    private static function settled(array|false $row, int $epoch, bool $begin): ?array
    {
        if ($row === false) {
            return null;
        }
        $stored = (int)$row['epoch'];
        // Identical answer however late this peer is: the whole point of
        // naming the epoch.
        if ($stored === $epoch) {
            return [(int)$row['start_pts']];
        }
        // A peer behind the pair's epoch WITHIN a run must not be handed a
        // start from the wrong origin, so 409 it. But a start that BEGINS
        // play (first/rematch) is epoch 0 on a fresh connection: a higher
        // stored epoch there is a leftover line from a torn-down one - and a
        // relay rematch reuses the hub with no new offer, so nothing calls
        // Starts::forget to clear it (see signal.php). That stranded the
        // rematch at a 409 until the row aged out. Reset the line for a
        // begin-play reason rather than refuse the new game; the second
        // peer's identical begin then reads the fresh row and both stay
        // aligned.
        if ($stored > $epoch && !$begin) {
            return [null];
        }
        return null;
    }

    /**
     * The pair's start PTS for $epoch: issued on first request, repeated
     * verbatim to the second peer. Returns null if the pair has already
     * moved PAST $epoch, which means the caller is behind and must not be
     * handed a start at all (the endpoint answers 409).
     */
    public static function request(string $id, string $peer, int $epoch, string $reason): ?int
    {
        [$a, $b] = $id < $peer ? [$id, $peer] : [$peer, $id];
        $db = Db::get();
        $begin = in_array($reason, self::SYNC_GATED_REASONS, true);
        // Before any lock is taken: the first settings read of a request can
        // load the whole overrides table, and under the lock every other
        // writer on the server would be waiting for that too.
        $leadMin = Settings::int('start_lead_min_ms');

        // Answer without the writer lock wherever the stored row already
        // decides the answer. Both peers ask about the same start and every
        // repeat of either lands here, so this is the common case by far -
        // and none of it writes anything.
        $settled = self::settled(self::read($db, $a, $b, Util::nowMs()), $epoch, $begin);
        if ($settled !== null) {
            return $settled[0];
        }

        // The answer must arrive before the moment it announces, so the lead
        // covers the slower peer's round trip. Also a read, also unlocked.
        $st = $db->prepare('SELECT MAX(COALESCE(latency, 100)) FROM players WHERE id IN (?, ?)');
        $st->execute([$a, $b]);
        $worstLatency = (int)$st->fetchColumn();
        $st->closeCursor();
        $lead = min(max($leadMin, 150 + 2 * $worstLatency), 3000);

        return Db::retry(static function () use ($db, $a, $b, $epoch, $reason, $begin, $lead): ?int {
            $db->exec('BEGIN IMMEDIATE');
            try {
                // The clock is read AFTER the lock: what was spent waiting for
                // it must not come out of the lead the answer promises.
                $now = Util::nowMs();
                // And the row is read again under it. The unlocked read only
                // established that there was work to do; the peer may have
                // done it since, and then ITS start is the one both must get.
                $row = self::read($db, $a, $b, $now);
                $settled = self::settled($row, $epoch, $begin);
                if ($settled !== null) {
                    $db->exec('COMMIT');
                    return $settled[0];
                }
                $startPts = $now + $lead;

                // Where play BEGINS (first/rematch), mint a fresh match here,
                // in this same transaction, so it is atomic with the start row
                // and both peers read one consistent mid (see
                // Items::openMatch). An in-run halt carries the pair's open
                // match forward untouched - one match spans every level of a
                // duel.
                $mid = $begin
                    ? Items::openMatch($db, $a, $b, $now)['mid']
                    : ($row !== false ? (string)$row['mid'] : '');

                $db->prepare(
                    'INSERT INTO starts (a, b, start_pts, created, epoch, reason, mid)
                     VALUES (?, ?, ?, ?, ?, ?, ?)
                     ON CONFLICT (a, b) DO UPDATE SET start_pts = excluded.start_pts,
                         created = excluded.created, epoch = excluded.epoch,
                         reason = excluded.reason, mid = excluded.mid'
                )->execute([$a, $b, $startPts, $now, $epoch, $reason, $mid]);
                if ($begin) {
                    // One duel, counted INSIDE the transaction that mints its
                    // match rather than by taking the writer a second time the
                    // moment this one lets go. Reached exactly once per duel,
                    // because a repeat request for the same epoch is answered
                    // from the stored row above. Only the START is countable:
                    // the server does not reliably learn that a match ended
                    // (see forget).
                    Stats::bumpIn($db, ['duel_started' => 1]);
                }
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
