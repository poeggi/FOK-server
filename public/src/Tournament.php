<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Util.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Presence.php';
require_once __DIR__ . '/Signals.php';
require_once __DIR__ . '/Alerts.php';
require_once __DIR__ . '/Bracket.php';
require_once __DIR__ . '/TourneyStore.php';
require_once __DIR__ . '/Events.php';
// EventView requires this file in turn. PHP registers a file as included
// before it runs it, so the cycle terminates and both classes are defined
// whichever of the two a request reaches first; neither names the other
// while its own class is being defined.
require_once __DIR__ . '/EventView.php';
require_once __DIR__ . '/Stats.php';

/**
 * Tournament orchestration: lobbies, the schedule, per-match role sheets,
 * results, standings, the round breaks and the bracket. The server is the ONLY
 * authority on all of them and clients render what it says (see docs/API.md
 * "Tournament mode").
 *
 * The tournament also gets HARDER as it narrows: round 1 is played at the
 * level the host chose and every round after it one deeper (see
 * Bracket::level), so the size of the lobby is what decides how deep the
 * final gets. Between two rounds it stops on a scoreboard and waits for the
 * host to press on (see gated/proceed).
 *
 * IT CARRIES NO GAME TRAFFIC. Every match in a tournament is an ordinary P2P
 * duel between the two players the roles sheet names, and every spectator feed
 * is P2P as well; the deprecated relay hub is not involved and nothing here
 * references it. The server deals the roles, waits, and settles - the play
 * itself never touches it. The pair also calls start.php themselves like any
 * other duel, so mid/secret issuance and the items attestation chain are
 * unchanged: a tournament node merely RECORDS the mid the pair reports.
 *
 * ONE shared-memory entry per tournament (see TourneyStore). Every transition
 * reads it, mutates it in PHP and writes the whole thing back under that
 * tournament's own lock, so two transitions never interleave and none of it
 * contends with the single SQLite writer the rest of the server shares. A
 * tournament is worthless once it ends and is never read again, so nothing
 * here is stored durably; the few totals worth keeping go to Stats.
 *
 * NOTHING HERE RUNS ON A TIMER. Shared hosting has no cron, so every deadline
 * (a one-sided result settling, a silent player's walkover) is evaluated
 * lazily on the next touch of the tournament - the same approach as the item
 * registry's claim grace. A lobby nobody started needs not even that: it is
 * stored with the join TTL and expires on its own.
 */
final class Tournament
{
    // No 0/O/1/I/L: a join code is read off someone else's screen and typed
    // back in, so the ambiguous glyphs are simply not in the alphabet.
    public const CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    public const CODE_LEN = 6;

    public const OUTCOMES = ['win', 'loss', 'draw'];
    public const SCORE_MAX = 100000;

    // A node that reached one of these counts towards the advancer cut.
    private const DONE = ['settled', 'confirmed', 'void'];

    /** @var list<callable> queued by afterUnlock(), drained by mutate() */
    private static array $afterUnlock = [];

    // ---- Store ------------------------------------------------------------

    /** @return ?array the whole tournament, players and data included */
    public static function load(string $tid): ?array
    {
        $t = TourneyStore::get($tid);
        return $t === null ? null : self::ready($t);
    }

    /** Join codes are unique among OPEN tournaments only, so they recycle. */
    public static function loadByCode(string $code): ?array
    {
        $t = TourneyStore::byCode($code);
        return $t === null ? null : self::ready($t);
    }

    /**
     * The stored shape with the per-request event queue attached. The queue
     * is never stored - it is drained by mutate() once the write is through.
     */
    private static function ready(array $t): array
    {
        $t['events'] = [];
        return $t;
    }

    private static function emptyData(): array
    {
        return [
            'n' => 0,
            'seats' => [],
            'schedule' => [],
            'results' => [],
            'standings' => [],
            'bracket' => [],
            'cursor' => null,
            'orphan' => [],
            // The round break: null, or {next, at} while the tournament is
            // waiting for the host to press on. `cleared` is the highest
            // round whose break has been passed, so a cleared break can
            // never re-open behind the tournament's back.
            'gate' => null,
            'cleared' => 1,
        ];
    }

    /**
     * Read-modify-write of the one entry, under that tournament's own lock.
     * $fn receives the loaded tournament BY REFERENCE and returns the
     * caller's response.
     *
     * The lock is what BEGIN IMMEDIATE used to be: it serialises transitions
     * on ONE tournament without serialising them against the rest of the
     * server. Rollback needs no machinery here - $fn works on a local copy
     * and nothing is stored until it returns, so a throw leaves the stored
     * tournament exactly as it was.
     *
     * Events are queued inside and flushed only after the store: a signal
     * announcing a transition that then failed is a lie no client can undo.
     * Database work is queued the same way and for a harder reason, see
     * afterUnlock().
     */
    private static function mutate(string $tid, callable $fn): ?array
    {
        if (!TourneyStore::lock($tid)) {
            // Every other worker on this tournament is ahead of us and the
            // client can simply ask again; blocking longer would hold an FPM
            // worker for a transition somebody else is already making.
            return ['ok' => false, 'error' => 'busy', 'http' => 503];
        }
        try {
            $t = self::load($tid);
            if ($t === null) {
                return null;
            }
            $before = self::fingerprint($t);
            $eid = $t['eid'] ?? null;
            $wasLive = is_string($eid) && $eid !== '' && TourneyStore::isLive($t);
            $out = $fn($t);
            // A pure read (a reload calling `state` on a tournament with
            // nothing due) must not write the entry back.
            if ($t['events'] !== [] || $before !== self::fingerprint($t)) {
                TourneyStore::put($t);
                // The edge an event page watches: the tournament it was
                // showing has stopped being the event's live one. Announced
                // from here rather than from the endings themselves because
                // there are four of them (the host's leave, open or running,
                // an abort, and the final result) and this is the one place
                // that sees them all. An entry that merely EXPIRES announces
                // nothing - nothing runs for it - which is why the signal is
                // a hint to re-read and `state` is the truth.
                if ($wasLive && !TourneyStore::isLive($t)) {
                    $tid = (string)$t['tid'];
                    self::afterUnlock(static function () use ($eid, $tid): void {
                        EventView::announce((string)$eid,
                            ['event' => 'tourney', 'tid' => $tid, 'over' => true]);
                    });
                }
            }
            $pending = [$t['host'], $t['events'], self::monitorOf($t)];
        } finally {
            TourneyStore::unlock($tid);
            // A transition that threw stored nothing, so what it queued
            // describes something that never happened: dropped with it.
            $due = self::$afterUnlock;
            self::$afterUnlock = [];
        }
        self::flush($pending[0], $pending[1], $pending[2]);
        foreach ($due as $fn) {
            $fn();
        }
        return $out;
    }

    /**
     * Work a transition wants done but must not do while it holds the lock:
     * anything that can WAIT. The SQLite writer parks a caller for up to
     * busy_timeout, and Db::retry makes three such attempts - the same
     * order as the lock's own 5 s TTL - so a transition that writes under
     * the lock can outlive its lease, and the next worker would take the
     * tournament out from under it. Locks here are therefore held for
     * shared memory only, and the counters and alerts a transition leaves
     * behind run once it is released.
     *
     * Ordered, and run after the events flush: an operator's alert follows
     * the clients being told, never precedes it.
     */
    private static function afterUnlock(callable $fn): void
    {
        self::$afterUnlock[] = $fn;
    }

    /**
     * Everything a transition can change. players is in here because a join
     * or a leave used to be a row write of its own and is now a mutation of
     * this array like any other.
     *
     * @return list<string>
     */
    private static function fingerprint(array $t): array
    {
        return [$t['state'], (string)$t['round'],
            (string)json_encode($t['data']), (string)json_encode($t['players'])];
    }

    /**
     * Fans the queued events out as 'tourney' signals. Reserved: signal.php
     * refuses the type from clients, so one of these can only ever have come
     * from here. `$from` is the host id - the events carry their own tid and
     * no client keys on the sender - and delivery is the ordinary hello/poll
     * drain, so an offline participant simply picks its events up later.
     *
     * Called after the COMMIT and OUTSIDE the transaction's retry, for two
     * reasons: an event announcing a transition that then rolled back is a
     * lie no client can undo, and a signal write that hits the busy writer
     * must retry the signal (Signals::send does) rather than replay the
     * transition that produced it.
     */
    private static function flush(string $from, array $events, ?string $monitor = null): void
    {
        $step = Settings::int('tourney_after_step_ms');
        $seatOf = [];
        foreach ($events as [$to, $payload]) {
            // The event's monitor is not a seat: the stagger exists to spread
            // the seats' follow-up calls, and a screen on a wall makes none
            // that compete with them.
            if ($monitor !== null && $to === $monitor) {
                $payload['after_ms'] = 0;
                Signals::send($from, $to, 'tourney', (string)json_encode($payload));
                continue;
            }
            // after_ms (4.4): a round board wakes every participant in the
            // same instant and they all call back together - the ICE burst
            // again, eight-handed, and on a host whose cost is paid per
            // request that burst is the expensive part of a tournament. The
            // event itself is not delayed; only the call it provokes is, by
            // a fixed step per RECIPIENT - one transition pushes several
            // events to each of them, and all of one recipient's carry the
            // same delay, so the eight callbacks land a step apart instead
            // of stacked. Capped where the client caps it (1000 ms), so a
            // wrong number cannot park a seat. Additive: a client that
            // ignores it behaves as before.
            if ($step > 0) {
                $seatOf[$to] ??= count($seatOf);
                $payload['after_ms'] = min(1000, $seatOf[$to] * $step);
            }
            Signals::send($from, $to, 'tourney', (string)json_encode($payload));
        }
    }

    /**
     * Queues one event per participant, or per id in $only. A BROADCAST on an
     * event tournament also reaches the event's monitor (4.14): it is the
     * screen that shows this tournament, and it should move with it rather
     * than on its own lease. A targeted send ($only) does not - the caller
     * decides the monitor's copy, see deal().
     */
    private static function event(array &$t, array $payload, ?array $only = null): void
    {
        $payload['tid'] = $t['tid'];
        $to = $only ?? array_column($t['players'], 'id');
        if ($only === null) {
            $mon = self::monitorOf($t);
            if ($mon !== null && !in_array($mon, $to, true)) {
                $to[] = $mon;
            }
        }
        foreach ($to as $pid) {
            $t['events'][] = [$pid, $payload];
        }
    }

    /**
     * The event's monitor holder for an event tournament, or null: no eid, or
     * nobody holding the slot. The reserved id if the event names one, else
     * the free lease while it stands (Events::monitorHolder). Read at the
     * moment it is asked, so a slot that changes hands is followed.
     */
    private static function monitorOf(array $t): ?string
    {
        $eid = $t['eid'] ?? null;
        if (!is_string($eid) || $eid === '') {
            return null;
        }
        $card = Events::card($eid);
        return $card === null ? null : Events::monitorHolder($card);
    }

    // ---- Small readers ----------------------------------------------------

    public static function isMember(array $t, string $id): bool
    {
        foreach ($t['players'] as $p) {
            if ($p['id'] === $id) {
                return true;
            }
        }
        return false;
    }

    private static function seatOf(array $t, string $id): ?int
    {
        $s = $t['data']['seats'][$id] ?? null;
        return is_int($s) ? $s : null;
    }

    private static function idOfSeat(array $t, ?int $seat): ?string
    {
        if ($seat === null) {
            return null;
        }
        foreach ($t['data']['seats'] as $id => $s) {
            if ($s === $seat) {
                return (string)$id;
            }
        }
        return null;
    }

    private static function forfeited(array $t, ?int $seat): bool
    {
        $id = self::idOfSeat($t, $seat);
        foreach ($t['players'] as $p) {
            if ($p['id'] === $id) {
                return $p['forfeited'];
            }
        }
        return false;
    }

    /** @return ?array{0:string,1:int} which list the node lives in, and where */
    private static function locate(array $t, string $nid): ?array
    {
        foreach (['schedule', 'bracket'] as $list) {
            foreach ($t['data'][$list] as $i => $n) {
                if ($n['nid'] === $nid) {
                    return [$list, $i];
                }
            }
        }
        return null;
    }

    private static function node(array $t, string $nid): ?array
    {
        $at = self::locate($t, $nid);
        return $at === null ? null : $t['data'][$at[0]][$at[1]];
    }

    private static function stateOf(array $t, string $nid): string
    {
        return (string)($t['data']['results'][$nid]['state'] ?? 'pending');
    }

    private static function isDone(array $t, string $nid): bool
    {
        return in_array(self::stateOf($t, $nid), self::DONE, true);
    }

    /**
     * The level this tournament's round 1 is played at. A tournament already
     * in the store when this shipped has no 'lvl' of its own, and reads as 1
     * so that it keeps the ladder it was created on rather than jumping.
     */
    private static function startLvl(array $t): int
    {
        return (int)($t['lvl'] ?? 1);
    }

    /**
     * Every round of this tournament is played as a speed round. The server
     * never acts on it - it is carried to the two players and to anyone
     * deciding whether to join, exactly like the stakes flag. A tournament
     * with no 'speed' of its own reads as false, which is the store's own
     * shape rather than a compatibility shim.
     */
    private static function isSpeed(array $t): bool
    {
        return (bool)($t['speed'] ?? false);
    }

    /** Done, or frozen - either way the cursor may move past it. */
    private static function isClosed(array $t, string $nid): bool
    {
        return self::isDone($t, $nid) || self::stateOf($t, $nid) === 'frozen';
    }

    // ---- create / join / leave --------------------------------------------

    /**
     * @return array{ok:bool,...} the endpoint's response, error and http status included
     *
     * The one-per-host guard and the join code are each a CLAIM rather than a
     * read followed by a write: apcu_add() stores only if the key is absent,
     * so two simultaneous creates cannot both pass it. That is what the old
     * BEGIN IMMEDIATE was buying here - handing the same join code to two
     * lobbies is the one thing a lobby's whole identity rests on.
     */
    public static function create(string $host, bool $stakes, bool $replace = false,
        int $lvl = 1, bool $speed = false, ?string $eid = null): array
    {
        // An EVENT tournament is an ordinary tournament with a tag on it:
        // the eid decides who may join and where it is archived, and
        // nothing else here knows about events at all. Only the event's
        // organizer may open one, and only while the event is running -
        // a lobby for a room that is closed has nobody to play in it.
        if ($eid !== null) {
            $card = Events::card($eid);
            if ($card === null || !Events::isOrganizer($card, $host)) {
                return ['ok' => false, 'error' => 'not the organizer', 'http' => 403];
            }
            $state = Events::stateOf($card);
            if ($state !== 'active') {
                return ['ok' => false, 'http' => 409,
                    'error' => $state === 'upcoming' ? 'not started' : $state];
            }
        }
        if (!TourneyStore::usable()) {
            // Tournament state has no database fallback by design, so this is
            // fatal rather than slow. Say so where an operator will see it.
            Alerts::raise('perf', 'Tournament mode unavailable: APCu shared memory is not usable '
                . 'on this host, and tournament state has no database fallback.');
            return ['ok' => false, 'error' => 'tournaments unavailable', 'http' => 503];
        }
        // Both are random and derived from nothing that is read back, so a
        // retried create reusing them is exactly right. The seed in
        // particular is fixed BEFORE anyone knows who will join, which is
        // what makes the seating shuffle and the final coin-toss tie-break
        // fair rather than merely deterministic.
        $tid = bin2hex(random_bytes(16));
        $seed = bin2hex(random_bytes(16));
        // The level round 1 is played at. Clamped rather than refused: it is
        // a preference, and a client that asks for a level the game does not
        // have wants the hardest one it does.
        $cap = Settings::int('tournament_max_level');
        $lvl = $lvl < 1 ? 1 : ($lvl > $cap ? $cap : $lvl);
        // Creating is cheap for the host and costly for everyone it can
        // announce to, so it is rate-limited off the host's own last create.
        $wait = TourneyStore::createWait($host);
        if ($replace) {
            // The host has one already and has answered for it: end that one
            // and carry on. One call, because a client that had to leave and
            // then create can lose the second half and hold neither. Which is
            // also why the cooldown is charged BEFORE anything is ended - a
            // replace refused after the abort would do exactly that.
            if ($wait > 0) {
                return self::cooldown($wait);
            }
            $held = TourneyStore::hostedBy($host);
            if ($held !== null) {
                self::abort($held, 'host opened a new one');
            }
        }
        if (!TourneyStore::claimHost($host, $tid)) {
            return ['ok' => false, 'error' => 'already hosting', 'http' => 409];
        }
        if ($wait > 0) {
            TourneyStore::releaseHost($host);
            return self::cooldown($wait);
        }
        $code = self::newCode($tid);
        if ($code === null) {
            TourneyStore::releaseHost($host);
            return ['ok' => false, 'error' => 'no join code available', 'http' => 503];
        }
        $now = time();
        TourneyStore::put([
            'tid' => $tid,
            'host' => $host,
            'code' => $code,
            'state' => 'open',
            'round' => 0,
            'seed' => $seed,
            'stakes' => $stakes,
            'lvl' => $lvl,
            'speed' => $speed,
            'eid' => $eid,
            'created' => $now,
            'data' => self::emptyData(),
            'players' => [['id' => $host, 'seat' => -1, 'forfeited' => false, 'joined' => $now]],
        ]);
        TourneyStore::markCreate($host);
        Stats::bump(['tourney_created' => 1]);
        if ($eid !== null) {
            // The other edge of what mutate() announces: the event now has a
            // live tournament. Its members and its monitor are told the tid
            // and the join code, so a screen already on the event page does
            // not have to notice on its own.
            EventView::announce($eid,
                ['event' => 'tourney', 'tid' => $tid, 'code' => $code]);
        }
        return [
            'ok' => true,
            'tid' => $tid,
            'code' => $code,
            'stakes' => $stakes,
            'lvl' => $lvl,
            'speed' => $speed,
            'eid' => $eid,
            'max' => Settings::int('tournament_max_players'),
        ];
    }

    /** @return array{ok:bool,error:string,http:int,retry_after:int} */
    private static function cooldown(int $wait): array
    {
        return ['ok' => false, 'error' => 'create cooldown', 'http' => 429, 'retry_after' => $wait];
    }

    private static function newCode(string $tid): ?string
    {
        for ($try = 0; $try < 12; $try++) {
            $code = '';
            for ($i = 0; $i < self::CODE_LEN; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
            // The claim IS the uniqueness test.
            if (TourneyStore::claimCode($code, $tid)) {
                return $code;
            }
        }
        return null;
    }

    public static function join(string $id, string $tid): ?array
    {
        return self::mutate($tid, static function (array &$t) use ($id): array {
            self::touch($t);
            if ($t['state'] === 'running') {
                return ['ok' => false, 'error' => 'already started', 'http' => 409];
            }
            if ($t['state'] !== 'open') {
                return ['ok' => false, 'error' => 'no such tournament', 'http' => 404];
            }
            // THE WHOLE SECRECY of an event tournament: by tid and by code
            // alike, only the event's members get in. The code is no use to
            // somebody who is not in the room.
            $eid = $t['eid'] ?? null;
            if (is_string($eid) && $eid !== '' && !Events::isMember($eid, $id)) {
                return ['ok' => false, 'error' => 'not in the event', 'http' => 403];
            }
            // Joining twice is a no-op, not an error: a client that lost the
            // response to its first join must be able to simply ask again.
            if (!self::isMember($t, $id)) {
                if (count($t['players']) >= Settings::int('tournament_max_players')) {
                    return ['ok' => false, 'error' => 'full', 'http' => 409];
                }
                $t['players'][] = ['id' => $id, 'seat' => -1,
                    'forfeited' => false, 'joined' => time()];
                self::event($t, self::lobby($t));
            }
            return ['ok' => true] + self::lobby($t);
        });
    }

    public static function leave(string $id, string $tid): ?array
    {
        return self::mutate($tid, static function (array &$t) use ($id): array {
            self::touch($t);
            if (!self::isMember($t, $id) || $t['state'] === 'done' || $t['state'] === 'abandoned') {
                return ['ok' => true];      // idempotent by design
            }
            if ($t['state'] === 'open') {
                if ($id === $t['host']) {
                    // The host ends it for everyone, at any point in its
                    // life: here before anyone played, below once they have.
                    $t['state'] = 'abandoned';
                    self::event($t, self::lobby($t, 'host left'));
                    return ['ok' => true];
                }
                foreach ($t['players'] as $i => $p) {
                    if ($p['id'] === $id) {
                        array_splice($t['players'], $i, 1);
                        break;
                    }
                }
                self::event($t, self::lobby($t));
                return ['ok' => true];
            }
            if ($id === $t['host']) {
                // Running, and it is the host: they own the TOURNAMENT, not
                // just the lobby, and the client offers them exactly this -
                // END TOURNAMENT FOR ALL. Everyone is dropped rather than
                // left playing a bracket its host has been forfeited out of.
                // Matches were played, so unlike an abandoned lobby this one
                // leaves the same stats trace a finished tournament does.
                $t['state'] = 'abandoned';
                $t['data']['cursor'] = null;   // nothing is being played now
                self::event($t, self::lobby($t, 'host ended it'));
                self::record($t);
                return ['ok' => true];
            }
            // Running, and it is a guest: forfeit. The bracket belongs to
            // everyone now, so it continues without the leaver - every node
            // they were still due to play becomes a walkover as the cursor
            // reaches it, and the one in flight right now is settled by the
            // advance below.
            foreach ($t['players'] as &$p) {
                if ($p['id'] === $id) {
                    $p['forfeited'] = true;
                }
            }
            unset($p);
            $seat = self::seatOf($t, $id);
            foreach ($t['data']['schedule'] as $n) {
                if (($n['a'] === $seat || $n['b'] === $seat) && !self::isClosed($t, $n['nid'])) {
                    self::walkover($t, $n['nid']);
                }
            }
            self::advance($t);
            return ['ok' => true];
        });
    }

    // ---- start ------------------------------------------------------------

    public static function start(string $id, string $tid): ?array
    {
        return self::mutate($tid, static function (array &$t) use ($id): array {
            self::touch($t);
            if ($id !== $t['host']) {
                return ['ok' => false, 'error' => 'host only', 'http' => 403];
            }
            if ($t['state'] !== 'open') {
                return ['ok' => false, 'error' => 'already started', 'http' => 409];
            }
            $n = count($t['players']);
            if ($n < 2) {
                return ['ok' => false, 'error' => 'need 2', 'http' => 409];
            }
            // The seating is derived from the join order, so that order
            // has to be total - see order().
            self::order($t);
            $seated = Bracket::seats(array_column($t['players'], 'id'), $t['seed']);
            $seats = [];
            foreach ($seated as $seat => $pid) {
                $seats[$pid] = $seat;
            }
            foreach ($t['players'] as &$p) {
                $p['seat'] = $seats[$p['id']];
            }
            unset($p);
            $t['data']['n'] = $n;
            $t['data']['seats'] = $seats;
            $t['data']['schedule'] = [];
            foreach (Bracket::schedule($n) as $i => [$a, $b]) {
                $t['data']['schedule'][] = [
                    'nid' => 'r1.' . ($i + 1), 'a' => $a, 'b' => $b,
                    'to' => null, 'slot' => 0, 'round' => 1,
                ];
            }
            $t['state'] = 'running';
            $t['round'] = 1;
            $t['data']['cursor'] = null;
            self::standings($t);
            self::advance($t);
            return ['ok' => true];
        });
    }

    // ---- the result ladder ------------------------------------------------

    /**
     * A player reports how their match went. Verdict-first, like the item
     * registry's claim ladder: what the report ASSERTS is compared against
     * what the other side asserted, and the pair either agrees or the node
     * freezes for an admin. The server never watched the match, so agreement
     * between the only two witnesses is the whole of its evidence.
     */
    public static function report(
        string $id,
        string $tid,
        string $nid,
        string $outcome,
        array $score,
        ?string $mid
    ): ?array {
        return self::mutate($tid, static function (array &$t) use ($id, $nid, $outcome, $score, $mid): array {
            self::touch($t);
            if ($t['state'] !== 'running' && $t['state'] !== 'done') {
                return ['ok' => false, 'error' => 'not running', 'http' => 409];
            }
            $node = self::node($t, $nid);
            if ($node === null) {
                return ['ok' => false, 'error' => 'no such node', 'http' => 404];
            }
            $seat = self::seatOf($t, $id);
            if ($seat === null || ($seat !== $node['a'] && $seat !== $node['b'])) {
                // Spectators NEVER report. They did not play it, and a
                // spectator report is the one input that could rewrite a
                // result nobody else disputes.
                return ['ok' => false, 'error' => 'not your match', 'http' => 403];
            }
            $r = $t['data']['results'][$nid] ?? null;
            if ($r !== null && in_array($r['state'], ['settled', 'confirmed', 'frozen', 'void'], true)) {
                // A decided node is decided. The client that lost its
                // response gets the recorded state back and nothing moves -
                // the report is not applied at all. Letting one in is how a
                // walkover gets re-settled by the player it was taken from,
                // how a node nobody disputed freezes on a late contradiction,
                // and how a knockout node that already sent its winner
                // forward gets replayed underneath the bracket.
                return ['ok' => true, 'nid' => $nid, 'state' => $r['state']];
            }
            if ($nid !== $t['data']['cursor']) {
                return ['ok' => false, 'error' => 'not current', 'http' => 409];
            }

            $other = $seat === $node['a'] ? $node['b'] : $node['a'];
            // What this report asserts about the WINNER, which is the only
            // thing two reports have to agree on. 'draw' asserts no winner.
            $verdict = $outcome === 'win' ? $seat : ($outcome === 'loss' ? $other : 'draw');
            // Scores are recorded seat-ordered [a, b], never reporter-ordered.
            $pair = $seat === $node['a'] ? [$score[0], $score[1]] : [$score[1], $score[0]];

            if ($r === null) {
                $r = self::blankResult(Util::nowMs());
            }
            if ($mid !== null) {
                $r['mid'] = $mid;
            }
            $prev = $r['reports'][$id] ?? null;
            if ($prev !== null) {
                if ($prev['verdict'] === $verdict) {
                    $t['data']['results'][$nid] = $r;
                    return ['ok' => true, 'nid' => $nid, 'state' => $r['state']];
                }
                // Changing your story about a match you already reported is
                // exactly the case a freeze exists for.
                $t['data']['results'][$nid] = $r;
                self::freeze($t, $nid, "player $id changed its report");
                return ['ok' => true, 'nid' => $nid, 'state' => 'frozen'];
            }
            $r['reports'][$id] = ['verdict' => $verdict, 'score' => $pair, 'at' => Util::nowMs()];
            $t['data']['results'][$nid] = $r;

            $peer = self::idOfSeat($t, $other);
            $peerReport = $peer === null ? null : ($r['reports'][$peer] ?? null);
            if ($peerReport !== null) {
                if ($peerReport['verdict'] !== $verdict) {
                    self::freeze($t, $nid, 'the two players disagree');
                    return ['ok' => true, 'nid' => $nid, 'state' => 'frozen'];
                }
                // The settling report's scores win: whichever report
                // completes the pair is the one recorded.
                self::close($t, $nid, $verdict, $pair, 'confirmed');
                return ['ok' => true, 'nid' => $nid, 'state' => 'confirmed'];
            }
            if ($outcome === 'loss') {
                // Nobody lies to lose a match, so one report is enough.
                self::close($t, $nid, $verdict, $pair, 'settled');
                return ['ok' => true, 'nid' => $nid, 'state' => 'settled'];
            }
            // A lone win or draw parks until the opponent answers, or until
            // tournament_result_ms turns the silence into agreement.
            $r = $t['data']['results'][$nid];
            $r['state'] = 'held';
            $t['data']['results'][$nid] = $r;
            return ['ok' => true, 'nid' => $nid, 'state' => 'held'];
        });
    }

    private static function blankResult(int $now): array
    {
        return [
            'state' => 'open', 'winner' => null, 'draw' => false, 'score' => null,
            'reports' => [], 'mid' => null, 'dealt' => $now,
        ];
    }

    /**
     * Records a decided node and moves the tournament on. $verdict is the
     * winning seat, or the string 'draw'.
     *
     * $why is carried only by a VOID, which has two causes that read as
     * opposite things on a bracket: 'gone' is nobody left to play it,
     * 'unplayed' is both players there and no connection between them. A
     * client that could not tell them apart would have to word one of them
     * wrongly, and erasing two people who sat there trying is the worse of
     * the two lies.
     */
    private static function close(array &$t, string $nid, int|string $verdict, ?array $score,
        string $state, ?string $why = null): void
    {
        $r = $t['data']['results'][$nid] ?? self::blankResult(Util::nowMs());
        $draw = $verdict === 'draw';
        $r['state'] = $state;
        $r['winner'] = $draw ? null : $verdict;
        $r['draw'] = $draw;
        $r['score'] = $score;
        $r['why'] = $why;
        $t['data']['results'][$nid] = $r;
        self::standings($t);
        // The rows ride with the result: they are what a result changes on
        // a client's screen, and a client never ranks on its own.
        self::event($t, [
            'event' => 'result',
            'nid' => $nid,
            'winner' => $draw ? null : self::idOfSeat($t, (int)$verdict),
            'draw' => $draw,
            'score' => $score,
            'why' => $why,
            'rows' => self::ranked($t),
        ]);

        $node = self::node($t, $nid);
        if ($draw && $state !== 'void' && $node !== null && $node['round'] > 1) {
            // A knockout has to produce a winner, so a drawn one is simply
            // played again: same node, fresh mid, fresh roles.
            //
            // A VOID one is the exception, and must never be re-dealt: it is
            // void precisely because neither side is there to play it, so a
            // replay only deals the same unplayable node again and the round
            // never moves. It advances an EMPTY slot instead, which the next
            // node reads as a bye for whoever is still standing - and if that
            // one is empty on both sides too, it voids in turn until the
            // bracket runs out, which is the documented empty podium.
            $t['data']['results'][$nid] = self::blankResult(Util::nowMs());
            self::deal($t, $nid);
            return;
        }
        if (!$draw && $node !== null && $node['to'] !== null) {
            self::feed($t, $node['to'], $node['slot'], (int)$verdict);
        }
        self::advance($t);
    }

    /** Writes a knockout winner into the slot it advances to. */
    private static function feed(array &$t, string $nid, int $slot, int $seat): void
    {
        $at = self::locate($t, $nid);
        if ($at === null) {
            return;
        }
        $t['data'][$at[0]][$at[1]][$slot === 0 ? 'a' : 'b'] = $seat;
    }

    /**
     * Settles a node nobody can play: a walkover for the side that is still
     * present, or a void when neither is. Scores stay null, so a walkover
     * contributes nothing to the score-difference tie-break.
     */
    private static function walkover(array &$t, string $nid, ?int $goneA = null, ?int $goneB = null): void
    {
        $node = self::node($t, $nid);
        if ($node === null) {
            return;
        }
        $aGone = $goneA ?? (self::forfeited($t, $node['a']) ? $node['a'] : null);
        $bGone = $goneB ?? (self::forfeited($t, $node['b']) ? $node['b'] : null);
        // An empty slot is absent for the same reason a forfeit is: nobody
        // is going to play it. That is what makes a phantom seed a bye.
        $aPresent = $node['a'] !== null && $aGone === null;
        $bPresent = $node['b'] !== null && $bGone === null;
        if ($aPresent && $bPresent) {
            return;
        }
        if (!$aPresent && !$bPresent) {
            self::close($t, $nid, 'draw', null, 'void', 'gone');
            return;
        }
        self::close($t, $nid, $aPresent ? (int)$node['a'] : (int)$node['b'], null, 'settled');
    }

    private static function freeze(array &$t, string $nid, string $why): void
    {
        $r = $t['data']['results'][$nid] ?? self::blankResult(Util::nowMs());
        $r['state'] = 'frozen';
        $r['winner'] = null;
        $r['draw'] = false;
        $t['data']['results'][$nid] = $r;
        $tid = (string)$t['tid'];
        self::afterUnlock(static function () use ($nid, $why, $tid): void {
            Alerts::raise('tournament', "Tournament $nid frozen: $why (tournament $tid)");
        });
        self::event($t, ['event' => 'freeze', 'nid' => $nid]);
        // A freeze is closed enough for the cursor to move past it, so round 1
        // carries on: only the advancer cut waits, which nextNode() enforces.
        // In the knockout that same guard makes this advance a no-op - a
        // frozen node has no winner to send forward, so the bracket stops
        // here until an admin clears it.
        self::advance($t);
    }

    // ---- the lazy deadlines -----------------------------------------------

    /**
     * Everything that would need a timer, run on whatever request happens to
     * touch the tournament next. Cheap, bounded by the node count, and it is
     * why a tournament nobody is looking at cannot get stuck.
     */
    private static function touch(array &$t): void
    {
        if ($t['state'] !== 'running') {
            return;
        }
        self::settleHeld($t);
        self::walkoverBySilence($t);
        self::settleDeadlock($t);
        self::advance($t);
    }

    /** A one-sided report becomes the result once the opponent's window passes. */
    private static function settleHeld(array &$t): void
    {
        $grace = Settings::int('tournament_result_ms');
        foreach (array_keys($t['data']['results']) as $nid) {
            $r = $t['data']['results'][$nid];
            if ($r['state'] !== 'held' || $r['reports'] === []) {
                continue;
            }
            $one = reset($r['reports']);
            if (Util::nowMs() - (int)$one['at'] < $grace) {
                continue;
            }
            self::close($t, (string)$nid, $one['verdict'], $one['score'], 'settled');
        }
    }

    /**
     * Which of a node's two players is GONE: not heard from for
     * tournament_gone_secs. That is deliberately shorter than the online
     * window - a seat of a dealt match is on a tournament screen or in the
     * match, and a client there is polling, so a seat that stops asking is
     * a client that closed - and it is the ONE test the walkover and the
     * deadlock both judge presence by, which is what keeps them disjoint.
     * @return array{0: bool, 1: bool}
     */
    private static function gone(string $a, string $b): array
    {
        $heard = Presence::heardWithin([$a, $b], Settings::int('tournament_gone_secs'));
        return [!$heard[$a], !$heard[$b]];
    }

    /**
     * The match in flight has stood for tournament_walkover_ms AND a player
     * of it is gone (see gone): that player forfeits the node. Two players
     * who both keep asking are left strictly alone - a long match is not a
     * fault, and the freeze/admin path covers the pathological cases.
     */
    private static function walkoverBySilence(array &$t): void
    {
        $nid = $t['data']['cursor'] ?? null;
        if ($nid === null || self::isClosed($t, $nid)) {
            return;
        }
        $r = $t['data']['results'][$nid] ?? null;
        if ($r === null || Util::nowMs() - (int)$r['dealt'] < Settings::int('tournament_walkover_ms')) {
            return;
        }
        $node = self::node($t, $nid);
        if ($node === null) {
            return;
        }
        $a = self::idOfSeat($t, $node['a']);
        $b = self::idOfSeat($t, $node['b']);
        if ($a === null || $b === null) {
            return;
        }
        [$aGone, $bGone] = self::gone($a, $b);
        if (!$aGone && !$bGone) {
            return;
        }
        self::walkover($t, $nid, $aGone ? $node['a'] : null, $bGone ? $node['b'] : null);
    }

    /**
     * The match neither player can start. Presence cannot see this one: both
     * are awake and asking, and it is the link between them that never comes
     * up - a NAT that will not traverse, an ICE exchange that never
     * completes - so the walkover above rightly refuses it and the node
     * would otherwise wait for ever, a client having no business giving up
     * on its own.
     *
     * What makes it safe to act on is that NOBODY EVER PLAYED IT: both peers
     * call start.php where play begins, so a pair that got a match going has
     * a duel row younger than the deal, and a node that has one is left
     * alone however long it runs. A long match is still not a fault.
     *
     * Re-dealt ONCE first, because a fresh mid and a fresh roles sheet are a
     * real second attempt at the connection, and only then VOIDED: both
     * players turned up, so there is no winner to name, and void already
     * means a node that was not played.
     */
    private static function settleDeadlock(array &$t): void
    {
        $nid = $t['data']['cursor'] ?? null;
        if ($nid === null || self::isClosed($t, $nid)) {
            return;
        }
        $r = $t['data']['results'][$nid] ?? null;
        if ($r === null
            || Util::nowMs() - (int)$r['dealt'] < Settings::int('tournament_deadlock_ms')) {
            return;
        }
        $node = self::node($t, $nid);
        if ($node === null) {
            return;
        }
        $a = self::idOfSeat($t, $node['a']);
        $b = self::idOfSeat($t, $node['b']);
        if ($a === null || $b === null) {
            return;
        }
        // ONLY the case the walkover refuses. A player who is gone is that
        // rule's business and settles as a win for whoever stayed, which
        // this must never turn into a void - so the cheap tests run first
        // and the duel read is the last gate, reached only by a node that is
        // about to be settled.
        [$aGone, $bGone] = self::gone($a, $b);
        if ($aGone || $bGone) {
            return;
        }
        if (Presence::duelSeenSince($a, $b, intdiv((int)$r['dealt'], 1000))) {
            return;
        }
        if ($r['redealt'] ?? false) {
            self::close($t, $nid, 'draw', null, 'void', 'unplayed');
            return;
        }
        // The fresh result resets `dealt`, so the second attempt gets the
        // whole deadline again and the mark is what stops a third.
        $t['data']['results'][$nid] = self::blankResult(Util::nowMs()) + ['redealt' => true];
        self::deal($t, $nid);
    }

    // ---- walking the tournament forward -----------------------------------

    /**
     * Moves the cursor to the next node that actually has to be played,
     * settling everything on the way that cannot be: byes, and nodes whose
     * players have forfeited. Loops because one of those settlements can
     * make the next node unplayable too - a forfeit cascade.
     */
    private static function advance(array &$t): void
    {
        if ($t['state'] !== 'running') {
            return;
        }
        // Bounded by the node count; the guard is against a shape bug
        // turning a cascade into a request that never returns.
        for ($guard = 0; $guard < 256; $guard++) {
            // close() calls back into advance(), so by the time an outer
            // iteration resumes the tournament may already have finished.
            if ($t['state'] !== 'running') {
                return;
            }
            $cur = $t['data']['cursor'];
            if ($cur !== null && !self::isClosed($t, $cur)) {
                self::walkover($t, $cur);
                if (!self::isClosed($t, $cur)) {
                    return;                     // a real match is in flight
                }
            }
            $next = self::nextNode($t);
            if ($next === null) {
                return;
            }
            // The row's `round` is the stage being PLAYED, which is what the
            // admin card and the lobby projection read.
            $node = self::node($t, $next);
            if (self::gated($t, $node === null ? $t['round'] : (int)$node['round'])) {
                return;                         // the round break, see gated()
            }
            $t['data']['cursor'] = $next;
            if ($node !== null) {
                $t['round'] = (int)$node['round'];
            }
            $t['data']['results'][$next] = self::blankResult(Util::nowMs());
            self::walkover($t, $next);
            if (!self::isClosed($t, $next)) {
                self::deal($t, $next);
                return;
            }
        }
    }

    /**
     * The next node to deal, or null when there is nothing to deal: the
     * tournament is finished, or it is blocked on a frozen node an admin has
     * to clear first.
     */
    private static function nextNode(array &$t): ?string
    {
        if ($t['round'] === 1) {
            foreach ($t['data']['schedule'] as $n) {
                if (!self::isClosed($t, $n['nid'])) {
                    return $n['nid'];
                }
            }
            foreach ($t['data']['schedule'] as $n) {
                if (self::stateOf($t, $n['nid']) === 'frozen') {
                    return null;                // the cut cannot be taken yet
                }
            }
            if ($t['data']['bracket'] === []) {
                self::buildBracket($t);
            }
            $t['round'] = 2;
        }
        foreach ($t['data']['bracket'] as $n) {
            if (self::stateOf($t, $n['nid']) === 'frozen') {
                return null;                    // no winner to send forward
            }
            if (!self::isClosed($t, $n['nid'])) {
                return $n['nid'];
            }
        }
        self::finish($t);
        return null;
    }

    /** Round 1 is over: rank, cut to the advancers, fold them into a bracket. */
    private static function buildBracket(array &$t): void
    {
        $ranked = self::ranked($t);
        $a = Bracket::advancers((int)$t['data']['n']);
        $a = min($a, count($ranked));
        $seatOf = [];
        $advancers = [];
        foreach (array_slice($ranked, 0, $a) as $row) {
            $seatOf[] = $row['seat'];
            $advancers[] = $row['id'];
        }
        $t['data']['bracket'] = Bracket::build($seatOf);
        $rows = [];
        foreach ($ranked as $row) {
            $row['adv'] = in_array($row['id'], $advancers, true);
            $rows[] = $row;
        }
        self::event($t, ['event' => 'standings', 'rows' => $rows, 'advancers' => $advancers]);
    }

    // ---- the round break --------------------------------------------------

    /**
     * The pause between two rounds. Everyone has just watched the round end
     * one match at a time; the point of stopping here is that they get to
     * read where it left them before the next one starts - who was in the
     * top half, who is through, and how deep the next round is played.
     *
     * Returns true while the tournament is WAITING. The host presses on
     * (see proceed), and the wait has its own lazy deadline like every other
     * one here, because a host whose browser closed must not be able to hold
     * a tournament everyone else is still in.
     *
     * Round 1 is never gated - there is nothing to show before a ball has
     * been kicked - and `cleared` makes a break that has been passed
     * unrepeatable, so a cascade of walkovers cannot re-open one behind the
     * tournament's back.
     */
    private static function gated(array &$t, int $round): bool
    {
        if ($round <= 1 || (int)($t['data']['cleared'] ?? 1) >= $round) {
            return false;
        }
        $gate = $t['data']['gate'] ?? null;
        if ($gate !== null && (int)$gate['next'] === $round) {
            if (Util::nowMs() - (int)$gate['at'] < Settings::int('tournament_break_ttl_ms')) {
                return true;
            }
            self::clearGate($t, $round);
            return false;
        }
        // Nothing is being played during a break, and the cursor is the node
        // being played - so it is null, exactly as it is when the tournament
        // is over. The board is NOT stored with the gate: it is derived on
        // every read, so a forfeit during the break shows up on the next
        // read-back instead of freezing into the copy the event carried.
        $t['data']['cursor'] = null;
        // The row's `round` runs ahead into the break, so both boundaries
        // read the same: during a break it is already the round ABOUT to be
        // played, and the board's own `done` names the one that ended.
        $t['round'] = $round;
        $t['data']['gate'] = ['next' => $round, 'at' => Util::nowMs()];
        self::event($t, self::board($t, $round, Util::nowMs()));
        return true;
    }

    private static function clearGate(array &$t, int $round): void
    {
        $t['data']['cleared'] = $round;
        $t['data']['gate'] = null;
    }

    /**
     * The host presses on. Idempotent by design: no break open is a plain
     * {"ok": true}, because the press that cleared it may simply have been
     * this client's own, or the break may have run out its deadline while
     * the tap was in flight.
     */
    public static function proceed(string $id, string $tid): ?array
    {
        return self::mutate($tid, static function (array &$t) use ($id): array {
            self::touch($t);
            if (!self::isMember($t, $id)) {
                return ['ok' => false, 'error' => 'not a participant', 'http' => 403];
            }
            // Read AFTER touch: the deadline may just have cleared it.
            $gate = $t['data']['gate'] ?? null;
            if ($gate === null) {
                return ['ok' => true];
            }
            if ($id !== $t['host']) {
                return ['ok' => false, 'error' => 'host only', 'http' => 403];
            }
            $left = Settings::int('tournament_break_ms') - (Util::nowMs() - (int)$gate['at']);
            if ($left > 0) {
                // The scoreboard is the whole point of the break, and a press
                // that lands before anyone could have read it is a stray tap
                // carried over from the match that just ended.
                return ['ok' => false, 'error' => 'too early', 'http' => 409, 'retry_ms' => $left];
            }
            self::clearGate($t, (int)$gate['next']);
            self::advance($t);
            return ['ok' => true];
        });
    }

    /** The nodes of one round, from whichever list holds that round. */
    private static function roundNodes(array $t, int $round): array
    {
        $out = [];
        foreach ($t['data'][$round <= 1 ? 'schedule' : 'bracket'] as $n) {
            if ((int)$n['round'] === $round) {
                $out[] = $n;
            }
        }
        return $out;
    }

    /**
     * The between-rounds scoreboard: where everyone stands, who is through,
     * and what the next round is. One row per participant, ranked by the
     * same ladder the advancer cut uses, and ordered so the board reads as
     * an elimination ladder - still in at the top, then whoever went out
     * most recently, then the rank.
     *
     * `pts` and `diff` are the ROUND-1 standings and do not move again: the
     * knockout is decided by winning, not by points. What moves each round
     * is `w`/`l`/`d`, which count the round that has just ended and nothing
     * else.
     *
     * Every name here is a token, never a caption: the client owns the
     * wording, and an unknown `stage` renders as a plain round number.
     */
    private static function board(array $t, int $next, int $at): array
    {
        $done = $next - 1;
        $nodes = self::roundNodes($t, $next);
        $through = [];
        foreach ($nodes as $n) {
            foreach ([$n['a'], $n['b']] as $seat) {
                if ($seat !== null) {
                    $through[(int)$seat] = true;
                }
            }
        }
        $tally = [];
        $until = [];
        foreach (['schedule', 'bracket'] as $list) {
            foreach ($t['data'][$list] as $n) {
                $r = $t['data']['results'][$n['nid']] ?? null;
                foreach ([$n['a'], $n['b']] as $seat) {
                    if ($seat === null) {
                        continue;
                    }
                    $seat = (int)$seat;
                    $until[$seat] = max($until[$seat] ?? 1, (int)$n['round']);
                    if ((int)$n['round'] !== $done || $r === null
                        || !self::isDone($t, $n['nid']) || $r['state'] === 'void') {
                        continue;
                    }
                    $tally[$seat] = $tally[$seat] ?? ['w' => 0, 'l' => 0, 'd' => 0];
                    if ($r['draw']) {
                        $tally[$seat]['d']++;
                    } elseif ($r['winner'] === $seat) {
                        $tally[$seat]['w']++;
                    } elseif ($r['winner'] !== null) {
                        $tally[$seat]['l']++;
                    }
                }
            }
        }
        $info = Presence::infoOf(array_column($t['players'], 'id'));
        $rows = [];
        foreach (self::ranked($t) as $row) {
            $seat = (int)$row['seat'];
            $gone = self::forfeited($t, $seat);
            $adv = isset($through[$seat]) && !$gone;
            $rows[] = $row + [
                'name' => $info[$row['id']]['name'] ?? null,
                'adv' => $adv,
                'until' => $adv ? $next : ($until[$seat] ?? 1),
                'gone' => $gone,
                'w' => $tally[$seat]['w'] ?? 0,
                'l' => $tally[$seat]['l'] ?? 0,
                'd' => $tally[$seat]['d'] ?? 0,
            ];
        }
        usort($rows, static fn(array $x, array $y): int => (($y['adv'] ? 1 : 0) <=> ($x['adv'] ? 1 : 0))
            ?: ($y['until'] <=> $x['until'])
            ?: ($x['rank'] <=> $y['rank']));
        $advancers = [];
        foreach ($rows as $row) {
            if ($row['adv']) {
                $advancers[] = $row['id'];
            }
        }
        return [
            'event' => 'round',
            'done' => $done,
            'next' => $next,
            'stage' => Bracket::stage($next, count($nodes)),
            'lvl' => Bracket::level($next, Settings::int('tournament_max_level'), self::startLvl($t)),
            'hm' => Bracket::hearts($nodes === [] ? '' : (string)$nodes[0]['nid']),
            'matches' => count($nodes),
            'of' => count($advancers),
            'host' => $t['host'],
            'at' => $at,
            'wait' => Settings::int('tournament_break_ms'),
            'auto' => Settings::int('tournament_break_ttl_ms'),
            'rows' => $rows,
            'advancers' => $advancers,
        ];
    }

    private static function finish(array &$t): void
    {
        if ($t['state'] !== 'running') {
            return;
        }
        $t['state'] = 'done';
        // The cursor is the node being PLAYED, and nothing is any more.
        $t['data']['cursor'] = null;
        $podium = self::podium($t);
        self::event($t, ['event' => 'over', 'podium' => $podium]);
        self::record($t, $podium);
    }

    /**
     * The only trace a tournament leaves behind once its state expires.
     * Counted here rather than as each node closes, so a whole bracket costs
     * one database write; a walkover is not a match anybody played.
     */
    private static function record(array $t, array $podium = []): void
    {
        $played = 0;
        foreach ($t['data']['results'] as $r) {
            if (in_array($r['state'] ?? '', ['settled', 'confirmed'], true)) {
                $played++;
            }
        }
        $seats = count($t['players']);
        // An event tournament also leaves a row on its event, which is
        // what the archive is made of. Written whatever state the event
        // is in: the match began while it was live, and this is the only
        // record the evening leaves once the bracket expires.
        $eid = $t['eid'] ?? null;
        $row = null;
        if (is_string($eid) && $eid !== '' && $played > 0) {
            $row = [
                'tid' => (string)$t['tid'],
                'host' => (string)$t['host'],
                'started' => (int)($t['created'] ?? time()),
                'finished' => time(),
                'seats' => $seats,
                'played' => $played,
                'podium' => $podium,
                'standings' => $t['data']['standings'] ?? [],
            ];
        }
        self::afterUnlock(static function () use ($played, $seats, $eid, $row): void {
            Stats::bump([
                'tourney_finished' => 1,
                'tourney_matches' => $played,
                'tourney_seats' => $seats,
            ]);
            if ($row !== null) {
                Events::archive((string)$eid, $row);
            }
        });
    }

    /**
     * Winner and runner-up come from the final. Third place has no match of
     * its own - one at a time is already a long evening - so it goes to the
     * better round-1 rank among the losers of the round before it.
     *
     * @return list<string>
     */
    private static function podium(array &$t): array
    {
        $final = self::node($t, 'final');
        if ($final === null) {
            return [];
        }
        $r = $t['data']['results']['final'] ?? null;
        $winner = $r === null ? null : $r['winner'];
        if ($winner === null) {
            return [];
        }
        $loser = $winner === $final['a'] ? $final['b'] : $final['a'];
        $podium = array_values(array_filter([
            self::idOfSeat($t, (int)$winner),
            self::idOfSeat($t, $loser),
        ]));
        $semis = [];
        foreach ($t['data']['bracket'] as $n) {
            if ($n['to'] === 'final') {
                $res = $t['data']['results'][$n['nid']] ?? null;
                if ($res !== null && $res['winner'] !== null) {
                    $out = $res['winner'] === $n['a'] ? $n['b'] : $n['a'];
                    $id = self::idOfSeat($t, $out);
                    if ($id !== null) {
                        $semis[] = $id;
                    }
                }
            }
        }
        if (count($semis) === 2) {
            foreach (self::ranked($t) as $row) {
                if (in_array($row['id'], $semis, true)) {
                    $podium[] = $row['id'];
                    break;
                }
            }
        } elseif (count($semis) === 1) {
            $podium[] = $semis[0];
        }
        return $podium;
    }

    // ---- standings --------------------------------------------------------

    /** Raw points and score difference, recomputed from the round-1 results. */
    private static function standings(array &$t): void
    {
        $pts = [];
        $diff = [];
        foreach ($t['data']['seats'] as $seat) {
            $pts[$seat] = 0.0;
            $diff[$seat] = 0;
        }
        foreach ($t['data']['schedule'] as $n) {
            if (!self::isDone($t, $n['nid'])) {
                continue;
            }
            $r = $t['data']['results'][$n['nid']];
            if ($r['state'] === 'void') {
                continue;                       // both absent: nothing for either
            }
            if ($r['draw']) {
                $pts[$n['a']] += 0.5;
                $pts[$n['b']] += 0.5;
            } elseif ($r['winner'] !== null) {
                $pts[$r['winner']] += 1.0;
            }
            // A walkover has a winner but no score, so it moves no difference.
            if (is_array($r['score'])) {
                $diff[$n['a']] += (int)$r['score'][0] - (int)$r['score'][1];
                $diff[$n['b']] += (int)$r['score'][1] - (int)$r['score'][0];
            }
        }
        $rows = [];
        foreach ($t['data']['seats'] as $id => $seat) {
            $rows[] = ['seat' => $seat, 'id' => (string)$id,
                'pts' => $pts[$seat] ?? 0.0, 'diff' => $diff[$seat] ?? 0];
        }
        usort($rows, static fn(array $a, array $b): int => $a['seat'] <=> $b['seat']);
        $t['data']['standings'] = $rows;
    }

    /** The standings put through the tie-break ladder. */
    private static function ranked(array $t): array
    {
        $h2h = [];
        foreach ($t['data']['schedule'] as $n) {
            $r = $t['data']['results'][$n['nid']] ?? null;
            if ($r !== null && self::isDone($t, $n['nid']) && $r['winner'] !== null) {
                $h2h[min($n['a'], $n['b']) . ':' . max($n['a'], $n['b'])] = (int)$r['winner'];
            }
        }
        return Bracket::rank($t['data']['standings'], $h2h, $t['seed']);
    }

    // ---- roles ------------------------------------------------------------

    /**
     * Who plays, who feeds, who watches. The two seats play; players[0] is
     * the FEEDER, which is the offer-host invariant the client relies on to
     * decide which side opens the P2P connection. Everyone else who is
     * online and has not forfeited spectates, in seat order: the first two
     * are primaries (they take the feed straight from the feeder), the rest
     * are secondaries (they take it from a primary). That tree is a CLIENT
     * arrangement - every byte of it is P2P and none of it comes through
     * here; the server only says who stands where.
     */
    private static function rolesOf(array $t, string $nid): ?array
    {
        $node = self::node($t, $nid);
        if ($node === null) {
            return null;
        }
        $a = self::idOfSeat($t, $node['a']);
        $b = self::idOfSeat($t, $node['b']);
        if ($a === null || $b === null) {
            return null;
        }
        $ids = array_column($t['players'], 'id');
        $info = Presence::infoOf($ids);
        $bySeat = [];
        foreach ($t['players'] as $p) {
            $seat = self::seatOf($t, $p['id']);
            if ($seat !== null) {
                $bySeat[$seat] = $p;
            }
        }
        ksort($bySeat);
        $spectators = [];
        foreach ($bySeat as $p) {
            if ($p['id'] === $a || $p['id'] === $b || $p['forfeited']) {
                continue;
            }
            if ($info[$p['id']]['online'] ?? false) {
                $spectators[] = $p['id'];
            }
        }
        $names = [];
        foreach ($ids as $pid) {
            $names[$pid] = $info[$pid]['name'] ?? null;
        }
        [$pos, $of] = self::position($t, $nid);
        // The event's monitor (4.14), named so every client grants it a feed
        // - a private duel included - while nothing lists, draws or counts
        // it: it is in none of players, primaries, secondaries or names and
        // takes no tree slot. Absent when there is no event or no holder.
        $mon = self::monitorOf($t);
        // The walkover clock (4.15): the first instant the node may be
        // handed over for a gone seat, so every screen waiting on the
        // match counts down the same server moment. Null before the deal
        // stamped it, which is no clock.
        $dealt = $t['data']['results'][$nid]['dealt'] ?? null;
        return [
            'event' => 'roles',
            'round' => (int)$node['round'],
            'stage' => Bracket::stage((int)$node['round'], $of),
            'match' => $pos,
            'of' => $of,
            'nid' => $nid,
            'hm' => Bracket::hearts($nid),
            // The level the match STARTS at: round 1 is the host's chosen
            // level and every round after it is one deeper (see
            // Bracket::level), so the two players preset it exactly as they
            // preset the hearts.
            'lvl' => Bracket::level((int)$node['round'], Settings::int('tournament_max_level'),
                self::startLvl($t)),
            // Per match rather than once per tournament, because this is the
            // sheet the pair presets from: a tournament that plays only some
            // of its rounds fast would then need no new field.
            'speed' => self::isSpeed($t),
            'stakes' => $t['stakes'],
            // The event this tournament belongs to, null outside one - as the
            // lobby projection has always carried it, and as the contract
            // promised of this sheet: a monitor routes the sheet by it,
            // holding no tid of its own to match against.
            'eid' => $t['eid'] ?? null,
            'players' => [$a, $b],
            'feeder' => $a,
            'primaries' => array_slice($spectators, 0, 2),
            'secondaries' => array_slice($spectators, 2),
            'names' => (object)$names,
            'walkover_at' => $dealt === null ? null : (int)$dealt + Settings::int('tournament_walkover_ms'),
            'spectators' => $spectators,
        ] + ($mon === null ? [] : ['monitor' => $mon]);
    }

    /** 1-based position of a node within its stage, and that stage's total. */
    private static function position(array $t, string $nid): array
    {
        $list = self::locate($t, $nid)[0] ?? 'schedule';
        $nodes = $t['data'][$list];
        foreach ($nodes as $i => $n) {
            if ($n['nid'] === $nid) {
                return [$i + 1, count($nodes)];
            }
        }
        return [1, count($nodes)];
    }

    private static function deal(array &$t, string $nid): void
    {
        $roles = self::rolesOf($t, $nid);
        if ($roles === null) {
            return;
        }
        $spectators = $roles['spectators'];
        unset($roles['spectators']);
        foreach ($t['players'] as $p) {
            $you = in_array($p['id'], $roles['players'], true) ? 'play'
                : (in_array($p['id'], $spectators, true) ? 'spectate' : 'idle');
            self::event($t, $roles + ['you' => $you], [$p['id']]);
        }
        // The monitor's copy: it has no seat, so `you` is idle, and a holder
        // who is also seated already has theirs.
        $mon = $roles['monitor'] ?? null;
        if ($mon !== null && !self::isMember($t, $mon)) {
            self::event($t, $roles + ['you' => 'idle'], [$mon]);
        }
    }

    /**
     * Feed-tree escalation: a primary is about to background, or a
     * secondary lost both its primaries. Re-deals ROLES ONLY - never a
     * result, never a bracket move - with the caller marked unavailable for
     * this node, so the spectator tree re-forms around them. The tree is a
     * P2P arrangement between clients; nothing here relays a byte of it.
     */
    public static function redeal(string $id, string $tid, string $nid, string $kind): ?array
    {
        return self::mutate($tid, static function (array &$t) use ($id, $nid, $kind): array {
            self::touch($t);
            if (!self::isMember($t, $id)) {
                return ['ok' => false, 'error' => 'not a participant', 'http' => 403];
            }
            if ($nid !== ($t['data']['cursor'] ?? null)) {
                return ['ok' => true];          // stale escalation, harmless
            }
            if ($kind === 'orphan') {
                $last = (int)($t['data']['orphan'][$id] ?? 0);
                if (Util::nowMs() - $last < 3000) {
                    return ['ok' => true];
                }
                $t['data']['orphan'][$id] = Util::nowMs();
            }
            $roles = self::rolesOf($t, $nid);
            if ($roles === null) {
                return ['ok' => true];
            }
            $spectators = array_values(array_filter(
                $roles['spectators'],
                static fn(string $s): bool => $s !== $id
            ));
            self::event($t, [
                'event' => 'roles-patch',
                'nid' => $nid,
                'eid' => $t['eid'] ?? null,
                'primaries' => array_slice($spectators, 0, 2),
                'secondaries' => array_slice($spectators, 2),
            ] + (isset($roles['monitor']) ? ['monitor' => $roles['monitor']] : []));
            return ['ok' => true];
        });
    }

    // ---- projections ------------------------------------------------------

    public static function lobby(array $t, ?string $reason = null): array
    {
        $ids = array_column($t['players'], 'id');
        $info = Presence::infoOf($ids);
        $players = [];
        foreach ($ids as $pid) {
            $players[] = ['id' => $pid, 'name' => $info[$pid]['name'] ?? null];
        }
        $out = [
            'event' => 'lobby',
            'tid' => $t['tid'],
            'state' => $t['state'],
            'code' => $t['code'],
            'host' => $t['host'],
            'stakes' => $t['stakes'],
            'speed' => self::isSpeed($t),
            'eid' => $t['eid'] ?? null,
            'max' => Settings::int('tournament_max_players'),
            'players' => $players,
        ];
        if ($reason !== null) {
            $out['reason'] = $reason;
        }
        return $out;
    }

    /**
     * The full read-back, for a reload or a rejoin: everything a client needs
     * to draw the tournament from nothing. Events elsewhere are deltas; this
     * is the only projection that carries the whole state.
     */
    public static function view(string $id, string $tid): ?array
    {
        // A reload is by far the most common tournament request, and the
        // overwhelming majority of them change nothing at all: no deadline is
        // due, so there is nothing to write. Those must not queue behind the
        // tournament's lock, and taking it costs the same whether or not
        // anything is written in the end. So the lock is taken only once a
        // dry run says a deadline is due (see due()).
        $t = self::load($tid);
        if ($t === null) {
            return null;
        }
        if (!self::isMember($t, $id)) {
            return ['ok' => false, 'error' => 'not a participant', 'http' => 403];
        }
        if (!self::due($t)) {
            return self::project($t, $id);
        }
        return self::mutate($tid, static function (array &$t) use ($id): array {
            self::touch($t);
            if (!self::isMember($t, $id)) {
                return ['ok' => false, 'error' => 'not a participant', 'http' => 403];
            }
            return self::project($t, $id);
        });
    }

    /**
     * Whether a deadline is due: touch() run on a snapshot loaded OUTSIDE
     * the lock, compared against what was loaded. What makes the dry run
     * safe is that touch() writes nothing of its own - everything it does
     * lands in the array it is given - so a run that decides nothing is due
     * has changed nothing anywhere. $t is a copy here; the caller's stays
     * as loaded.
     */
    private static function due(array $t): bool
    {
        $before = [$t['state'], $t['round'], json_encode($t['data'])];
        self::touch($t);
        return $t['events'] !== [] || $before !== [$t['state'], $t['round'], json_encode($t['data'])];
    }

    /**
     * The deadlines, run for a mailbox drain. hello and poll.php are the
     * requests every participant makes anyway - a held poll on the
     * tournament screens, an unheld one during a match, the heartbeat
     * throughout - so they are what keeps the clock moving for a tournament
     * nobody is otherwise touching, and a client never reads `state` for
     * timekeeping. Called at request ENTRY, before the mailbox is read, so
     * whatever a deadline produces - a settled result, the next roles sheet
     * - is in the answer the same request gives.
     *
     * The common case is shared memory and nothing else: one index lookup,
     * one snapshot, one dry run. Only a deadline that is due takes the lock.
     * Not seated, not running, no shared memory: nothing happens, and the
     * drain answers exactly as it would without this.
     */
    public static function pulse(string $id): void
    {
        if (!TourneyStore::usable()) {
            return;
        }
        $tid = TourneyStore::runningFor($id);
        if ($tid === null) {
            return;
        }
        $t = self::load($tid);
        if ($t === null || $t['state'] !== 'running' || !self::isMember($t, $id)) {
            // An index entry that outlived what it named: the tournament
            // expired between two transitions, or was evicted.
            TourneyStore::forgetRunning($id, $tid);
            return;
        }
        if (!self::due($t)) {
            return;
        }
        self::mutate($tid, static function (array &$t): array {
            self::touch($t);
            return [];
        });
    }

    /** The whole-tournament projection, as $id sees it. */
    /**
     * The tournament as an EVENT MONITOR sees it: the same projection every
     * participant reads, without the membership check - a monitor is
     * authorised by its EVENT, not by a seat, and it never has one.
     *
     * INERT, deliberately. view() settles whatever deadline has come due,
     * because a participant asking is a participant still being there. A
     * monitor is a screen on a wall: reading it must never be what forfeits
     * somebody's match, for the same reason the admin dashboard is excluded
     * from the sweep. The players' own requests run the clock.
     */
    public static function monitorView(string $tid, string $id): ?array
    {
        $t = self::load($tid);
        return $t === null ? null : self::project($t, $id);
    }

    private static function project(array $t, string $id): array
    {
        $out = ['ok' => true] + self::lobby($t);
        $out['round'] = $t['round'];
        $out['cursor'] = $t['data']['cursor'];
        $out['schedule'] = self::projectNodes($t, 'schedule');
        $out['bracket'] = self::projectNodes($t, 'bracket');
        $out['standings'] = $t['data']['standings'] === [] ? [] : self::ranked($t);
        // The board is derived here rather than stored, so a read-back
        // during a break always reflects what has happened since it opened.
        $gate = $t['data']['gate'] ?? null;
        $out['break'] = $gate === null ? null
            : self::board($t, (int)$gate['next'], (int)$gate['at']) + ['tid' => $t['tid']];
        $out['roles'] = null;
        $cur = $t['data']['cursor'] ?? null;
        if ($t['state'] === 'running' && $cur !== null && !self::isClosed($t, $cur)) {
            $roles = self::rolesOf($t, $cur);
            if ($roles !== null) {
                $spectators = $roles['spectators'];
                unset($roles['spectators']);
                $out['roles'] = $roles + ['you' => in_array($id, $roles['players'], true) ? 'play'
                    : (in_array($id, $spectators, true) ? 'spectate' : 'idle')];
            }
        }
        return $out;
    }

    private static function projectNodes(array $t, string $list): array
    {
        $cap = Settings::int('tournament_max_level');
        $out = [];
        foreach ($t['data'][$list] as $n) {
            $r = $t['data']['results'][$n['nid']] ?? null;
            $out[] = [
                'nid' => $n['nid'],
                'round' => (int)$n['round'],
                'hm' => Bracket::hearts($n['nid']),
                'lvl' => Bracket::level((int)$n['round'], $cap, self::startLvl($t)),
                'players' => [self::idOfSeat($t, $n['a']), self::idOfSeat($t, $n['b'])],
                'state' => $r === null ? 'pending' : $r['state'],
                'winner' => $r === null || $r['winner'] === null ? null : self::idOfSeat($t, (int)$r['winner']),
                'draw' => $r !== null && $r['draw'],
                'score' => $r === null ? null : $r['score'],
                // Only a void has one; every other node answers null.
                'why' => $r === null ? null : ($r['why'] ?? null),
            ];
        }
        return $out;
    }

    // ---- hello.php maintenance and announce -------------------------------

    /**
     * Open lobbies whose host is on ANY network the caller is on - the whole
     * of the "announced on the local network" mechanism. Everyone else joins
     * by code, and the code is the capability. Served from the hosts'
     * presence entries in one fetch, so a hello that asks stays flat-cost.
     *
     * Three things had to be true before two devices in one room could match,
     * and each of them broke this feature on its own:
     *
     * The match is on the NETWORK, not the address. Two devices share a
     * public IPv4 address, but on IPv6 they share only the /64 they are both
     * numbered out of (see Util::ipNet).
     *
     * A player is on as many networks as the families it speaks. A
     * dual-stack client picks a family per connection, and the host and the
     * joiner in one room do not have to pick the same one - so the host is
     * matched on every network it has recently been seen on, against every
     * network the CALLER has recently been seen on (see Presence::seenOn).
     * A pair that never overlaps at all still has the join code, and a
     * device that has only ever spoken one family has exactly one network,
     * which is the old behaviour.
     *
     * And the host has to still count as present. That window is its own
     * setting rather than FOK_ONLINE_WINDOW: a host waiting in a lobby is a
     * BACKGROUND tab or a phone with the screen off as often as not, and a
     * browser throttles background timers to about one a minute, so its
     * beats arrive late and thin; the announce must not flicker with them
     * while the lobby stays perfectly joinable by code.
     */
    public static function announce(string $id, string $ip): array
    {
        $lobbies = TourneyStore::usable() ? TourneyStore::openLobbies() : [];
        if ($lobbies === []) {
            return [];
        }
        $since = time() - Settings::int('tournament_announce_window');
        // The caller's CURRENT network is included whether or not it has been
        // recorded yet: this request is the evidence for it, and a first-ever
        // hello must not have to wait for a second one to see the room.
        $nets = Presence::netsOf($id, $since);
        $nets[] = Util::ipNet($ip);
        $nets = array_values(array_unique($nets));
        // One fetch for all the open lobbies at once, and only about their
        // hosts: which of them is present and shares a network with the
        // caller, and what it is called (see Presence::hostsOn). The lobbies
        // themselves come from the OPEN index, a small card per lobby, so
        // answering this never deserialises a running bracket.
        $hosts = array_values(array_unique(array_column($lobbies, 'host')));
        $names = Presence::hostsOn($hosts, $nets, $since);
        $max = Settings::int('tournament_max_players');
        // Freshest first, and never more than a screenful.
        usort($lobbies, static fn(array $a, array $b): int => $b['updated'] <=> $a['updated']);
        // The caller's own events, read once: an event lobby is announced
        // to its AUDIENCE whatever network they are on - being in the room
        // is the thing an event replaces a shared address with - and to
        // nobody else at all, however close by they are.
        //
        // The audience is the members and the MONITOR, the same set a
        // transition is signalled to. A monitor takes no seat and cannot
        // join one - a tournament join tests Events::isMember, which is
        // members only - but a screen on a wall is there to show the room's
        // tournaments, so it is told about them.
        $mine = [];
        foreach (Events::mine($id) as $row) {
            if ($row['state'] === 'member' || $row['state'] === 'monitor') {
                $mine[$row['eid']] = true;
            }
        }
        $out = [];
        foreach ($lobbies as $l) {
            $eid = $l['eid'] ?? null;
            if (is_string($eid) && $eid !== '') {
                if (!isset($mine[$eid])) {
                    continue;
                }
            } elseif (!array_key_exists($l['host'], $names)) {
                // array_key_exists, not isset: a player who has never set a
                // name has a NULL one, and the lobby is still announceable.
                continue;
            }
            $out[] = [
                'tid' => (string)$l['tid'],
                'code' => (string)$l['code'],
                'host' => (string)$l['host'],
                'host_name' => $names[$l['host']] ?? null,
                'players' => (int)$l['players'],
                'max' => $max,
                'stakes' => (bool)$l['stakes'],
                'speed' => (bool)($l['speed'] ?? false),
                'eid' => $l['eid'] ?? null,
            ];
            if (count($out) === 10) {
                break;
            }
        }
        return $out;
    }

    /**
     * Every live tournament, for the admin card. The ONLY reader outside a
     * tournament's own transitions, and deliberately inert: it takes no lock
     * and runs no deadline, so looking at the card can never move a
     * tournament along.
     *
     * @return list<array<string, mixed>>
     */
    public static function listLive(): array
    {
        if (!TourneyStore::usable()) {
            return [];
        }
        $out = [];
        $hosts = [];
        foreach (TourneyStore::all() as $t) {
            $nodes = array_merge($t['data']['schedule'], $t['data']['bracket']);
            $done = 0;
            foreach ($nodes as $n) {
                if (self::isClosed($t, $n['nid'])) {
                    $done++;
                }
            }
            $hosts[] = (string)$t['host'];
            $out[] = [
                'tid' => (string)$t['tid'],
                'code' => (string)$t['code'],
                'host' => (string)$t['host'],
                'host_name' => null,
                'state' => (string)$t['state'],
                'round' => (int)$t['round'],
                'stakes' => (bool)$t['stakes'],
                'players' => count($t['players']),
                'done' => $done,
                'nodes' => count($nodes),
                'gated' => $t['data']['gate'] !== null,
                'since' => (int)$t['created'],
            ];
        }
        if ($out === []) {
            return [];
        }
        $st = Db::get()->prepare(
            'SELECT id, name FROM players WHERE id IN (' . self::marks($hosts) . ')'
        );
        $st->execute($hosts);
        $names = [];
        foreach ($st->fetchAll() as $r) {
            $names[(string)$r['id']] = $r['name'];
        }
        foreach ($out as &$row) {
            $row['host_name'] = $names[$row['host']] ?? null;
        }
        unset($row);
        usort($out, static fn(array $a, array $b): int => $b['since'] <=> $a['since']);
        return $out;
    }

    /**
     * One tournament in full, for the popup the admin card opens. Inert in
     * the same way listLive is - no lock, no deadline run - so the operator
     * reads what the tournament IS rather than what looking at it would make
     * it become. That is also what makes the wait worth showing: a lapsed
     * deadline says nobody has come to collect it, deadlines being settled by
     * a seated player's own request and by nothing else (see pulse).
     */
    public static function detail(string $tid): ?array
    {
        if (!TourneyStore::usable()) {
            return null;
        }
        $t = TourneyStore::get($tid);
        if ($t === null) {
            return null;
        }
        $now = time();
        $cur = $t['data']['cursor'] ?? null;
        $ids = array_column($t['players'], 'id');
        $info = Presence::infoOf($ids);
        // The name and the online verdict come from infoOf, which falls back
        // to the players table; the raw beat is only in the entry.
        $entries = Presence::entriesOf($ids);
        $playing = [];
        $node = $cur === null || self::isClosed($t, $cur) ? null : self::node($t, $cur);
        if ($node !== null) {
            foreach ([$node['a'], $node['b']] as $seat) {
                $pid = self::idOfSeat($t, $seat);
                if ($pid !== null) {
                    $playing[$pid] = true;
                }
            }
        }
        $players = [];
        foreach ($t['players'] as $p) {
            $pid = (string)$p['id'];
            $seen = $entries[$pid]['seen'] ?? null;
            $players[] = [
                'id' => $pid,
                'name' => $info[$pid]['name'] ?? null,
                'host' => $pid === $t['host'],
                // A lobby has not seated anybody yet, and carries -1.
                'seat' => (int)$p['seat'] < 0 ? null : (int)$p['seat'],
                'forfeited' => (bool)$p['forfeited'],
                'online' => (bool)($info[$pid]['online'] ?? false),
                'last_seen' => $seen === null ? null : max(0, $now - (int)$seen),
                'playing' => isset($playing[$pid]),
            ];
        }
        $nodes = [];
        foreach (['schedule', 'bracket'] as $list) {
            foreach ($t['data'][$list] as $n) {
                $nid = (string)$n['nid'];
                $r = $t['data']['results'][$nid] ?? null;
                $nodes[] = [
                    'nid' => $nid,
                    'round' => (int)$n['round'],
                    'a' => self::idOfSeat($t, $n['a']),
                    'b' => self::idOfSeat($t, $n['b']),
                    'state' => self::stateOf($t, $nid),
                    'winner' => $r === null ? null : self::idOfSeat($t, $r['winner']),
                    'draw' => $r !== null && (bool)$r['draw'],
                    'current' => $nid === $cur,
                ];
            }
        }
        return [
            'tid' => (string)$t['tid'],
            'code' => (string)$t['code'],
            'host' => (string)$t['host'],
            'state' => (string)$t['state'],
            'round' => (int)$t['round'],
            'stakes' => (bool)$t['stakes'],
            'speed' => self::isSpeed($t),
            'since' => (int)$t['created'],
            'now' => $now,
            'cursor' => $cur,
            'players' => $players,
            'nodes' => $nodes,
        ] + self::waitingOn($t);
    }

    /**
     * What the tournament is sitting on, how long it has sat there, and how
     * much of its deadline is left. A NEGATIVE wait_left_ms is the reading
     * that matters: the deadline lapsed and the tournament is still in that
     * state, so nothing has asked since. A lapsed 'match' does not settle by
     * itself either way - a walkover also needs one of the two players to be
     * gone (see gone), which the player rows' last beat says.
     */
    private static function waitingOn(array $t): array
    {
        $none = ['wait' => null, 'wait_for_ms' => null, 'wait_left_ms' => null];
        if ($t['state'] !== 'running') {
            return $none;
        }
        $nowMs = Util::nowMs();
        $waiting = static fn(string $what, int $at, int $window): array
            => ['wait' => $what, 'wait_for_ms' => $nowMs - $at,
                'wait_left_ms' => $at + $window - $nowMs];
        $gate = $t['data']['gate'] ?? null;
        if ($gate !== null) {
            return $waiting('break', (int)$gate['at'], Settings::int('tournament_break_ttl_ms'));
        }
        $cur = $t['data']['cursor'] ?? null;
        $r = $cur === null || self::isClosed($t, $cur) ? null
            : ($t['data']['results'][$cur] ?? null);
        if ($r !== null) {
            if ($r['state'] === 'held' && $r['reports'] !== []) {
                $one = reset($r['reports']);
                return $waiting('result', (int)$one['at'], Settings::int('tournament_result_ms'));
            }
            return $waiting('match', (int)$r['dealt'], Settings::int('tournament_walkover_ms'));
        }
        // The cursor is on a closed node and has not moved past it, so the
        // bracket is blocked on a frozen one - the cut cannot be taken while a
        // node has no result. A frozen node is CLOSED, which is why the cursor
        // sits on it rather than going null, and it has no deadline at all:
        // only an operator clears it.
        foreach (['schedule', 'bracket'] as $list) {
            foreach ($t['data'][$list] as $n) {
                if (self::stateOf($t, $n['nid']) === 'frozen') {
                    return ['wait' => 'frozen'] + $none;
                }
            }
        }
        return $none;
    }

    /**
     * Ends the tournaments nobody is at any more.
     *
     * Every other deadline here is settled by a SEATED player's own request
     * (see pulse), which is exactly why an abandoned tournament has none:
     * with nobody asking, the break never continues, the walkover never
     * fires, and the tournament stands until its entry expires an hour later
     * - still listed on the dashboard, still holding its host's
     * one-per-host claim. This is the one deadline ANY request may settle,
     * because it is the only one whose subject is the absence of the players.
     *
     * The test is PRESENCE, not activity. A long match transitions rarely and
     * must never be swept out from under two people sitting in front of it,
     * so what counts is the NEWEST beat among the seats: every request is a
     * beat, and tournament_idle_ttl is above the online window, so a player
     * merely between heartbeats is never gone. A tournament whose seats have
     * no entry left at all is gone by the same test, having no beat at all.
     *
     * Rate-gated by the caller (Util::bumpNow), which is also what keeps this
     * out of the request that pays for it: at most one sweep every
     * tournament_sweep_secs, and the cards it reads carry no bracket.
     */
    public static function sweep(): void
    {
        if (!TourneyStore::usable()) {
            return;
        }
        $cards = TourneyStore::liveCards();
        if ($cards === []) {
            return;
        }
        $ids = [];
        foreach ($cards as $card) {
            foreach ($card['ids'] as $pid) {
                $ids[] = (string)$pid;
            }
        }
        // One bulk fetch for every seat of every tournament, not one per
        // tournament: the same shape the friend delta holds itself to.
        $entries = Presence::entriesOf(array_values(array_unique($ids)));
        $now = time();
        $idle = Settings::int('tournament_idle_ttl');
        foreach ($cards as $card) {
            $seen = 0;
            foreach ($card['ids'] as $pid) {
                $seen = max($seen, (int)($entries[(string)$pid]['seen'] ?? 0));
            }
            if ($now - $seen < $idle) {
                continue;
            }
            self::abort((string)$card['tid'], 'everyone left');
        }
    }

    /**
     * Ends it for everyone, without being one of its players. The same
     * transition the host's own "end for all" makes (see leave), because to a
     * client it is the same thing: the tournament stops where it stands and
     * every screen says so. $reason is what those screens are told, so the two
     * callers - an operator on the dashboard, a host replacing this lobby with
     * a new one - do not both read as the first. Idempotent: a tournament
     * already over has nothing to end.
     */
    public static function abort(string $tid, string $reason = 'ended by the operator'): ?array
    {
        return self::mutate($tid, static function (array &$t) use ($reason): array {
            if ($t['state'] === 'done' || $t['state'] === 'abandoned') {
                return ['ok' => true];
            }
            $played = $t['state'] === 'running';
            $t['state'] = 'abandoned';
            $t['data']['cursor'] = null;
            self::event($t, self::lobby($t, $reason));
            if ($played) {
                // Matches were played, so it leaves the same stats trace a
                // finished tournament does; an untouched lobby does not.
                self::record($t);
            }
            return ['ok' => true];
        });
    }

    /** @param list<mixed> $values */
    private static function marks(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }

    /**
     * Join order, and TOTAL: two joins landing in the same second must still
     * sort the same way every time, because the seating is derived from it.
     */
    private static function order(array &$t): void
    {
        usort($t['players'], static fn(array $a, array $b): int
            => [$a['joined'], $a['id']] <=> [$b['joined'], $b['id']]);
    }

}
