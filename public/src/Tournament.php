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

/**
 * Tournament orchestration: lobbies, the schedule, per-match role sheets,
 * results, standings, the round breaks and the bracket. The server is the ONLY
 * authority on all of them and clients render what it says (see docs/API.md
 * "Tournament mode").
 *
 * The tournament also gets HARDER as it narrows: a round is played at the
 * level of its own number (see Bracket::level), so the size of the lobby is
 * what decides how deep the final gets. Between two rounds it stops on a
 * scoreboard and waits for the host to press on (see gated/proceed).
 *
 * IT CARRIES NO GAME TRAFFIC. Every match in a tournament is an ordinary P2P
 * duel between the two players the roles sheet names, and every spectator feed
 * is P2P as well; the deprecated relay hub is not involved and nothing here
 * references it. The server deals the roles, waits, and settles - the play
 * itself never touches it. The pair also calls start.php themselves like any
 * other duel, so mid/secret issuance and the items attestation chain are
 * unchanged: a tournament node merely RECORDS the mid the pair reports.
 *
 * ONE writer row per tournament. Every transition reads `data`, mutates it in
 * PHP and writes the whole row back inside BEGIN IMMEDIATE - the shape that
 * keeps the single SQLite writer out of trouble elsewhere. Transitions are a
 * few per minute per tournament, so this is never a hot path.
 *
 * NOTHING HERE RUNS ON A TIMER. Shared hosting has no cron, so every deadline
 * (a one-sided result settling, a silent player's walkover, a lobby nobody
 * started) is evaluated lazily on the next touch of the tournament - the same
 * approach as the item registry's claim grace.
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

    // How often the lobby reap is allowed to actually run (see reapLobbies).
    private const SWEEP_INTERVAL = 60;

    // ---- Store ------------------------------------------------------------

    /** @return ?array the whole tournament, players and decoded data included */
    public static function load(string $tid): ?array
    {
        $db = Db::get();
        $st = $db->prepare('SELECT * FROM tournaments WHERE tid = ?');
        $st->execute([$tid]);
        $row = $st->fetch();
        $st->closeCursor();
        return $row === false ? null : self::hydrate($db, $row);
    }

    /** Join codes are unique among OPEN tournaments only, so they recycle. */
    public static function loadByCode(string $code): ?array
    {
        $db = Db::get();
        $st = $db->prepare("SELECT * FROM tournaments WHERE code = ? AND state = 'open'
                            ORDER BY created DESC LIMIT 1");
        $st->execute([strtoupper($code)]);
        $row = $st->fetch();
        $st->closeCursor();
        return $row === false ? null : self::hydrate($db, $row);
    }

    private static function hydrate(PDO $db, array $row): array
    {
        // Join order is the scheduler's input and must be total, so the id
        // breaks a same-second tie rather than leaving it to the storage.
        $st = $db->prepare('SELECT id, seat, forfeited, joined FROM tournament_players
                            WHERE tid = ? ORDER BY joined ASC, id ASC');
        $st->execute([$row['tid']]);
        $players = [];
        foreach ($st->fetchAll() as $p) {
            $players[] = [
                'id' => (string)$p['id'],
                'seat' => (int)$p['seat'],
                'forfeited' => (int)$p['forfeited'] === 1,
                'joined' => (int)$p['joined'],
            ];
        }
        $data = json_decode((string)$row['data'], true);
        return [
            'tid' => (string)$row['tid'],
            'host' => (string)$row['host'],
            'code' => (string)$row['code'],
            'state' => (string)$row['state'],
            'round' => (int)$row['round'],
            'seed' => (string)$row['seed'],
            'stakes' => (int)$row['stakes'] === 1,
            'created' => (int)$row['created'],
            'data' => is_array($data) ? $data : self::emptyData(),
            'players' => $players,
            // Queued here, sent only after the transaction commits.
            'events' => [],
        ];
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

    private static function store(PDO $db, array $t): void
    {
        $db->prepare('UPDATE tournaments SET state = ?, round = ?, data = ?, updated = ? WHERE tid = ?')
            ->execute([$t['state'], $t['round'], (string)json_encode($t['data']), time(), $t['tid']]);
    }

    /**
     * Read-modify-write of the one row, under the writer lock. $fn receives
     * the loaded tournament BY REFERENCE and returns the caller's response.
     *
     * Events are queued inside and flushed only after the COMMIT: a signal
     * announcing a transition that then rolled back is a lie no client can
     * undo, and signals are not part of this transaction.
     */
    private static function mutate(string $tid, callable $fn): ?array
    {
        $db = Db::get();
        $pending = [];
        $out = Db::retry(static function () use ($db, $tid, $fn, &$pending): ?array {
            // Reset per attempt: a retried transaction re-derives its own
            // events, and the abandoned attempt's must not go out as well.
            $pending = [];
            $db->exec('BEGIN IMMEDIATE');
            try {
                $t = self::load($tid);
                if ($t === null) {
                    $db->exec('ROLLBACK');
                    return null;
                }
                $before = [$t['state'], $t['round'], json_encode($t['data'])];
                $res = $fn($t);
                // A pure read (a reload calling `state` on a tournament with
                // nothing due) must not write the row back: it would put a
                // page write on the single writer for a request that changed
                // nothing at all.
                if ($t['events'] !== [] || $before !== [$t['state'], $t['round'], json_encode($t['data'])]) {
                    self::store($db, $t);
                }
                $db->exec('COMMIT');
            } catch (Throwable $e) {
                $db->exec('ROLLBACK');
                throw $e;
            }
            $pending = [$t['host'], $t['events']];
            return $res;
        });
        if ($pending !== []) {
            self::flush($pending[0], $pending[1]);
        }
        return $out;
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
    private static function flush(string $from, array $events): void
    {
        foreach ($events as [$to, $payload]) {
            Signals::send($from, $to, 'tourney', (string)json_encode($payload));
        }
    }

    /** Queues one event per participant, or per id in $only. */
    private static function event(array &$t, array $payload, ?array $only = null): void
    {
        $payload['tid'] = $t['tid'];
        foreach ($only ?? array_column($t['players'], 'id') as $pid) {
            $t['events'][] = [$pid, $payload];
        }
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

    /** Done, or frozen - either way the cursor may move past it. */
    private static function isClosed(array $t, string $nid): bool
    {
        return self::isDone($t, $nid) || self::stateOf($t, $nid) === 'frozen';
    }

    // ---- create / join / leave --------------------------------------------

    /**
     * @return array{ok:bool,...} the endpoint's response, error and http status included
     *
     * ALL of it runs inside BEGIN IMMEDIATE. The one-per-host guard, the
     * cooldown and the join code are each a read followed by a write, and
     * outside the writer lock two simultaneous creates can pass all three
     * reads before either writes - handing out the same join code twice,
     * which is the one thing a lobby's whole identity rests on.
     */
    public static function create(string $host, bool $stakes): array
    {
        $db = Db::get();
        // Minted before the transaction on purpose: both are random and
        // derived from nothing the transaction reads, so a retried attempt
        // reusing them is exactly right. The seed in particular is fixed
        // BEFORE anyone knows who will join, which is what makes the seating
        // shuffle and the final coin-toss tie-break fair rather than merely
        // deterministic.
        $tid = bin2hex(random_bytes(16));
        $seed = bin2hex(random_bytes(16));
        return Db::retry(static function () use ($db, $tid, $host, $seed, $stakes): array {
            $db->exec('BEGIN IMMEDIATE');
            try {
                $out = self::mint($db, $tid, $host, $seed, $stakes);
                $db->exec($out['ok'] === true ? 'COMMIT' : 'ROLLBACK');
                return $out;
            } catch (Throwable $e) {
                $db->exec('ROLLBACK');
                throw $e;
            }
        });
    }

    /** The body of create(), and only ever called with the writer lock held. */
    private static function mint(PDO $db, string $tid, string $host, string $seed, bool $stakes): array
    {
        $st = $db->prepare("SELECT tid FROM tournaments WHERE host = ? AND state IN ('open','running') LIMIT 1");
        $st->execute([$host]);
        $busy = $st->fetchColumn();
        $st->closeCursor();
        if ($busy !== false) {
            return ['ok' => false, 'error' => 'already hosting', 'http' => 409];
        }
        // Creating is cheap for the host and costly for everyone it can
        // announce to, so it is rate-limited off the host's own last create.
        $st = $db->prepare('SELECT created FROM tournaments WHERE host = ? ORDER BY created DESC LIMIT 1');
        $st->execute([$host]);
        $last = $st->fetchColumn();
        $st->closeCursor();
        $wait = $last === false ? 0 : (int)$last + Settings::int('tournament_create_cooldown') - time();
        if ($wait > 0) {
            return ['ok' => false, 'error' => 'create cooldown', 'http' => 429, 'retry_after' => $wait];
        }
        $code = self::newCode($db);
        if ($code === null) {
            return ['ok' => false, 'error' => 'no join code available', 'http' => 503];
        }
        $now = time();
        $db->prepare('INSERT INTO tournaments (tid, host, code, state, round, seed, stakes, data, created, updated)
                      VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, ?)')
            ->execute([$tid, $host, $code, 'open', $seed, $stakes ? 1 : 0,
                (string)json_encode(self::emptyData()), $now, $now]);
        $db->prepare('INSERT INTO tournament_players (tid, id, seat, forfeited, joined)
                      VALUES (?, ?, -1, 0, ?)')->execute([$tid, $host, $now]);
        return [
            'ok' => true,
            'tid' => $tid,
            'code' => $code,
            'stakes' => $stakes,
            'max' => Settings::int('tournament_max_players'),
        ];
    }

    private static function newCode(PDO $db): ?string
    {
        $st = $db->prepare("SELECT 1 FROM tournaments WHERE code = ? AND state = 'open' LIMIT 1");
        for ($try = 0; $try < 12; $try++) {
            $code = '';
            for ($i = 0; $i < self::CODE_LEN; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
            $st->execute([$code]);
            $taken = $st->fetchColumn() !== false;
            $st->closeCursor();
            if (!$taken) {
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
            // Joining twice is a no-op, not an error: a client that lost the
            // response to its first join must be able to simply ask again.
            if (!self::isMember($t, $id)) {
                if (count($t['players']) >= Settings::int('tournament_max_players')) {
                    return ['ok' => false, 'error' => 'full', 'http' => 409];
                }
                $now = time();
                Db::get()->prepare('INSERT INTO tournament_players (tid, id, seat, forfeited, joined)
                                    VALUES (?, ?, -1, 0, ?)')->execute([$t['tid'], $id, $now]);
                $t['players'][] = ['id' => $id, 'seat' => -1, 'forfeited' => false, 'joined' => $now];
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
                    // The host owns the LOBBY, and only the lobby. Leaving
                    // one that never started ends it; leaving a running
                    // tournament (below) merely forfeits, like anyone else.
                    $t['state'] = 'abandoned';
                    self::event($t, self::lobby($t, 'host left'));
                    return ['ok' => true];
                }
                Db::get()->prepare('DELETE FROM tournament_players WHERE tid = ? AND id = ?')
                    ->execute([$t['tid'], $id]);
                foreach ($t['players'] as $i => $p) {
                    if ($p['id'] === $id) {
                        array_splice($t['players'], $i, 1);
                        break;
                    }
                }
                self::event($t, self::lobby($t));
                return ['ok' => true];
            }
            // Running: forfeit. The bracket belongs to everyone now, so it
            // continues without the leaver - every node they were still due
            // to play becomes a walkover as the cursor reaches it, and the
            // one in flight right now is settled by the advance below.
            Db::get()->prepare('UPDATE tournament_players SET forfeited = 1 WHERE tid = ? AND id = ?')
                ->execute([$t['tid'], $id]);
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
            // players is already ordered (joined ASC, id ASC) by hydrate.
            $seated = Bracket::seats(array_column($t['players'], 'id'), $t['seed']);
            $seats = [];
            $st = Db::get()->prepare('UPDATE tournament_players SET seat = ? WHERE tid = ? AND id = ?');
            foreach ($seated as $seat => $pid) {
                $seats[$pid] = $seat;
                $st->execute([$seat, $t['tid'], $pid]);
            }
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
     */
    private static function close(array &$t, string $nid, int|string $verdict, ?array $score, string $state): void
    {
        $r = $t['data']['results'][$nid] ?? self::blankResult(Util::nowMs());
        $draw = $verdict === 'draw';
        $r['state'] = $state;
        $r['winner'] = $draw ? null : $verdict;
        $r['draw'] = $draw;
        $r['score'] = $score;
        $t['data']['results'][$nid] = $r;
        self::standings($t);
        self::event($t, [
            'event' => 'result',
            'nid' => $nid,
            'winner' => $draw ? null : self::idOfSeat($t, (int)$verdict),
            'draw' => $draw,
            'score' => $score,
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
            self::close($t, $nid, 'draw', null, 'void');
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
        Alerts::raise('tournament', "Tournament $nid frozen: $why (tournament {$t['tid']})");
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
     * The match in flight has gone nowhere for a long time AND a player of it
     * is offline: that player forfeits the node. Online but silent is left
     * strictly alone - a long match is not a fault, and the freeze/admin path
     * covers the pathological cases.
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
        $info = Presence::infoOf([$a, $b]);
        $aOff = !($info[$a]['online'] ?? false);
        $bOff = !($info[$b]['online'] ?? false);
        if (!$aOff && !$bOff) {
            return;
        }
        self::walkover($t, $nid, $aOff ? $node['a'] : null, $bOff ? $node['b'] : null);
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
            'lvl' => Bracket::level($next, Settings::int('tournament_max_level')),
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
        self::event($t, ['event' => 'over', 'podium' => self::podium($t)]);
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
        return [
            'event' => 'roles',
            'round' => (int)$node['round'],
            'stage' => Bracket::stage((int)$node['round'], $of),
            'match' => $pos,
            'of' => $of,
            'nid' => $nid,
            'hm' => Bracket::hearts($nid),
            // The level the match STARTS at: round 1 is level 1 and every
            // round after it is one deeper (see Bracket::level), so the two
            // players preset it exactly as they preset the hearts.
            'lvl' => Bracket::level((int)$node['round'], Settings::int('tournament_max_level')),
            'stakes' => $t['stakes'],
            'players' => [$a, $b],
            'feeder' => $a,
            'primaries' => array_slice($spectators, 0, 2),
            'secondaries' => array_slice($spectators, 2),
            'names' => (object)$names,
            'spectators' => $spectators,
        ];
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
                'primaries' => array_slice($spectators, 0, 2),
                'secondaries' => array_slice($spectators, 2),
            ]);
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
        // due, so there is nothing to write. Those must not take the writer
        // lock, and BEGIN IMMEDIATE takes it whether or not the row is
        // written in the end.
        //
        // So the deadlines are run on a snapshot loaded OUTSIDE any
        // transaction, and only when that dry run actually moves something is
        // the whole thing redone under the lock, where it counts. What makes
        // this safe is that touch() writes no rows of its own - everything it
        // does lands in $t - so a dry run that decides nothing is due has
        // changed nothing anywhere.
        $t = self::load($tid);
        if ($t === null) {
            return null;
        }
        if (!self::isMember($t, $id)) {
            return ['ok' => false, 'error' => 'not a participant', 'http' => 403];
        }
        $before = [$t['state'], $t['round'], json_encode($t['data'])];
        self::touch($t);
        if ($t['events'] === [] && $before === [$t['state'], $t['round'], json_encode($t['data'])]) {
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

    /** The whole-tournament projection, as $id sees it. */
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
                'lvl' => Bracket::level((int)$n['round'], $cap),
                'players' => [self::idOfSeat($t, $n['a']), self::idOfSeat($t, $n['b'])],
                'state' => $r === null ? 'pending' : $r['state'],
                'winner' => $r === null || $r['winner'] === null ? null : self::idOfSeat($t, (int)$r['winner']),
                'draw' => $r !== null && $r['draw'],
                'score' => $r === null ? null : $r['score'],
            ];
        }
        return $out;
    }

    // ---- hello.php maintenance and announce -------------------------------

    /**
     * Open lobbies whose host is on ANY network the caller is on - the whole
     * of the "announced on the local network" mechanism. Everyone else joins
     * by code, and the code is the capability. Served by idx_player_nets_net,
     * so a hello that asks stays flat-cost.
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
     * browser throttles background timers to about one a minute - right at
     * the edge of the 60s presence window, so the lobby flickered in and out
     * of the announce while it stayed perfectly joinable by code.
     */
    public static function announce(string $id, string $ip): array
    {
        $since = time() - Settings::int('tournament_announce_window');
        // The caller's CURRENT network is included whether or not it has been
        // recorded yet: this request is the evidence for it, and a first-ever
        // hello must not have to wait for a second one to see the room.
        $nets = Presence::netsOf($id, $since);
        $nets[] = Util::ipNet($ip);
        $nets = array_values(array_unique($nets));
        $st = Db::get()->prepare(
            "SELECT t.tid, t.code, t.host, t.stakes, p.name AS host_name,
                    (SELECT COUNT(*) FROM tournament_players tp WHERE tp.tid = t.tid) AS players
             FROM player_nets hn
             JOIN players p ON p.id = hn.id
             JOIN tournaments t ON t.host = hn.id
             WHERE hn.net IN (" . implode(',', array_fill(0, count($nets), '?')) . ")
               AND hn.seen > ? AND p.last_seen > ? AND t.state = 'open'
             GROUP BY t.tid
             ORDER BY t.updated DESC LIMIT 10"
        );
        $st->execute([...$nets, $since, $since]);
        $max = Settings::int('tournament_max_players');
        $out = [];
        foreach ($st->fetchAll() as $row) {
            $out[] = [
                'tid' => (string)$row['tid'],
                'code' => (string)$row['code'],
                'host' => (string)$row['host'],
                'host_name' => $row['host_name'],
                'players' => (int)$row['players'],
                'max' => $max,
                'stakes' => (int)$row['stakes'] === 1,
            ];
        }
        return $out;
    }

    /**
     * A lobby nobody ever started is abandoned rather than left to linger.
     *
     * Marker-gated to once a minute, the same shape as the player sweep:
     * hello calls this whenever a client asks for lobbies, and an
     * unconditional UPDATE there puts a write on the single writer for every
     * one of those requests - a sweep that finds nothing, over and over,
     * competing with the game traffic. The TTL is 15 minutes, so a minute of
     * slack costs a lobby nothing.
     */
    public static function reapLobbies(): void
    {
        $db = Db::get();
        $st = $db->prepare("SELECT value FROM counters WHERE bucket = 'meta' AND metric = 'tourney_sweep'");
        $st->execute();
        $last = (int)$st->fetchColumn();
        $st->closeCursor();
        $now = time();
        if ($last > $now - self::SWEEP_INTERVAL) {
            return;
        }
        $cut = $now - Settings::int('tournament_join_ttl');
        Db::retry(static function () use ($db, $now, $cut): void {
            // The marker is written first and unconditionally: if the sweep
            // itself finds nothing there is still no reason for the next
            // hello to come straight back and look again.
            $db->prepare("INSERT INTO counters (bucket, metric, value) VALUES ('meta', 'tourney_sweep', ?)
                          ON CONFLICT (bucket, metric) DO UPDATE SET value = excluded.value")
                ->execute([$now]);
            $db->prepare("UPDATE tournaments SET state = 'abandoned' WHERE state = 'open' AND updated < ?")
                ->execute([$cut]);
        });
    }
}
