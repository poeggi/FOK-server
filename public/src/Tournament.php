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
 * results, standings and the bracket. The server is the ONLY authority on all
 * of them and clients render what it says (see docs/API.md "Tournament mode").
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

    /** @return array{ok:bool,...} the endpoint's response, error and http status included */
    public static function create(string $host, bool $stakes): array
    {
        $db = Db::get();
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

        $tid = bin2hex(random_bytes(16));
        // The seed is minted HERE, before anyone knows who will join, which
        // is what makes the seating shuffle and the final coin-toss
        // tie-break fair rather than merely deterministic.
        $seed = bin2hex(random_bytes(16));
        $code = self::newCode($db);
        if ($code === null) {
            return ['ok' => false, 'error' => 'no join code available', 'http' => 503];
        }
        $now = time();
        Db::retry(static function () use ($db, $tid, $host, $code, $seed, $stakes, $now): void {
            $db->prepare('INSERT INTO tournaments (tid, host, code, state, round, seed, stakes, data, created, updated)
                          VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, ?)')
                ->execute([$tid, $host, $code, 'open', $seed, $stakes ? 1 : 0,
                    (string)json_encode(self::emptyData()), $now, $now]);
            $db->prepare('INSERT INTO tournament_players (tid, id, seat, forfeited, joined)
                          VALUES (?, ?, -1, 0, ?)')->execute([$tid, $host, $now]);
        });
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
            $closed = $r !== null && in_array($r['state'], ['settled', 'confirmed', 'frozen', 'void'], true);
            if ($nid !== $t['data']['cursor'] && !$closed) {
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
        if ($draw && $node !== null && $node['round'] > 1) {
            // A knockout has to produce a winner, so a drawn one is simply
            // played again: same node, fresh mid, fresh roles.
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
            $t['data']['cursor'] = $next;
            // The row's `round` is the stage being PLAYED, which is what the
            // admin card and the lobby projection read.
            $node = self::node($t, $next);
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
            'match' => $pos,
            'of' => $of,
            'nid' => $nid,
            'hm' => Bracket::hearts($nid),
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
        return self::mutate($tid, static function (array &$t) use ($id): array {
            self::touch($t);
            if (!self::isMember($t, $id)) {
                return ['ok' => false, 'error' => 'not a participant', 'http' => 403];
            }
            $out = ['ok' => true] + self::lobby($t);
            $out['round'] = $t['round'];
            $out['cursor'] = $t['data']['cursor'];
            $out['schedule'] = self::projectNodes($t, 'schedule');
            $out['bracket'] = self::projectNodes($t, 'bracket');
            $out['standings'] = $t['data']['standings'] === [] ? [] : self::ranked($t);
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
        });
    }

    private static function projectNodes(array $t, string $list): array
    {
        $out = [];
        foreach ($t['data'][$list] as $n) {
            $r = $t['data']['results'][$n['nid']] ?? null;
            $out[] = [
                'nid' => $n['nid'],
                'round' => (int)$n['round'],
                'hm' => Bracket::hearts($n['nid']),
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
     * Open lobbies whose host is on the CALLER's address - the whole of the
     * "announced on the local network" mechanism. Everyone else joins by
     * code, and the code is the capability. Served by idx_players_ip_seen,
     * so a hello that asks stays flat-cost.
     */
    public static function announce(string $ip): array
    {
        $st = Db::get()->prepare(
            "SELECT t.tid, t.code, t.host, t.stakes, p.name AS host_name,
                    (SELECT COUNT(*) FROM tournament_players tp WHERE tp.tid = t.tid) AS players
             FROM players p JOIN tournaments t ON t.host = p.id
             WHERE p.ip = ? AND p.last_seen > ? AND t.state = 'open'
             ORDER BY t.updated DESC LIMIT 10"
        );
        $st->execute([$ip, time() - FOK_ONLINE_WINDOW]);
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

    /** A lobby nobody ever started is abandoned rather than left to linger. */
    public static function reapLobbies(): void
    {
        $cut = time() - Settings::int('tournament_join_ttl');
        Db::retry(static fn() => Db::get()
            ->prepare("UPDATE tournaments SET state = 'abandoned' WHERE state = 'open' AND updated < ?")
            ->execute([$cut]));
    }
}
