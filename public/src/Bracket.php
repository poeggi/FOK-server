<?php
declare(strict_types=1);

/**
 * The normative tournament math: seating, the round-1 sparse schedule, the
 * standings tie-break ladder and the knockout fold.
 *
 * Pure by design - no database, no clock, no randomness beyond the seed it is
 * handed. Clients RENDER a bracket the server computed, and the client team
 * implements that rendering from docs/API.md alone; two implementations only
 * ever agree if the rules are deterministic. So they are: the same seed and
 * the same join order in, a byte-identical schedule out. That is also what
 * makes the whole thing unit-testable without a server (see test/unit.php).
 *
 * Seats, not player ids, all the way through: the schedule is computed once
 * at start and a player's identity never enters the pairing rules.
 */
final class Bracket
{
    // The LCG from Numerical Recipes, and the only randomness in the
    // scheduler. Not cryptographic and it does not need to be: `seed` is
    // fixed when the tournament is CREATED, before anyone knows who will
    // join, so nobody can steer the shuffle by choosing when to join.
    private const LCG_A = 1664525;
    private const LCG_C = 1013904223;
    private const LCG_M = 4294967296;      // 2^32
    private const LCG_FALLBACK = 0x9e3779b9;

    /** First 8 hex characters of the seed as a uint32; 0 (or junk) is the
     *  golden-ratio constant, so the generator never starts on a fixed point. */
    private static function state(string $seed): int
    {
        $head = substr($seed, 0, 8);
        $x = preg_match('/^[0-9a-f]{8}$/', $head) === 1 ? (int)hexdec($head) : 0;
        return $x === 0 ? self::LCG_FALLBACK : $x;
    }

    private static function draw(int &$x, int $k): int
    {
        $x = (self::LCG_A * $x + self::LCG_C) % self::LCG_M;
        return $x % $k;
    }

    /**
     * Assigns seats. $ids must already be in join order (joined ASC, id ASC);
     * the Fisher-Yates below is precisely what decorrelates the sparse
     * pairings from that order, so friends who join together do not
     * automatically end up meeting.
     *
     * @param list<string> $ids
     * @return list<string> index = seat number
     */
    public static function seats(array $ids, string $seed): array
    {
        $out = array_values($ids);
        $x = self::state($seed);
        for ($i = count($out) - 1; $i >= 1; $i--) {
            $j = self::draw($x, $i + 1);
            [$out[$i], $out[$j]] = [$out[$j], $out[$i]];
        }
        return $out;
    }

    /**
     * The round-1 schedule: seat pairs in play order.
     *
     * SPARSE on purpose. A dense round-robin at 8 players is 28 matches run
     * one at a time, which is an evening nobody finishes; this gives everyone
     * at most 4 matches and 8 players 16 of them.
     *
     * N <= 4: every pair (that IS at most 3 matches each already).
     * N >= 5: the circulant edges at offsets 1 and 2 on the seat circle, so
     * every seat has degree exactly 4 and the total is 2N. At N = 5 the two
     * rules agree - the circulant IS the full round-robin there.
     *
     * @return list<array{0:int,1:int}>
     */
    public static function schedule(int $n): array
    {
        $edges = [];
        if ($n <= 4) {
            for ($a = 0; $a < $n; $a++) {
                for ($b = $a + 1; $b < $n; $b++) {
                    $edges[] = [$a, $b];
                }
            }
        } else {
            foreach ([1, 2] as $d) {
                for ($i = 0; $i < $n; $i++) {
                    $j = ($i + $d) % $n;
                    $edges[] = [min($i, $j), max($i, $j)];
                }
            }
        }
        return self::spread($edges);
    }

    /**
     * Rest spread: repeatedly take the first remaining edge that shares no
     * seat with the one just taken, falling back to the first remaining edge
     * when none qualifies. Deterministic, and on the sparse schedule (N >= 5)
     * the fallback never triggers, so nobody plays two matches back to back -
     * which matters when matches run one at a time and everyone else is
     * watching. At N = 3 and N = 4 the dense round-robin does run out of
     * disjoint pairs, and there it happens twice.
     *
     * @param list<array{0:int,1:int}> $edges
     * @return list<array{0:int,1:int}>
     */
    private static function spread(array $edges): array
    {
        $out = [];
        $last = null;
        while ($edges !== []) {
            $pick = 0;
            if ($last !== null) {
                foreach ($edges as $k => $e) {
                    if (!in_array($e[0], $last, true) && !in_array($e[1], $last, true)) {
                        $pick = $k;
                        break;
                    }
                }
            }
            $last = $edges[$pick];
            $out[] = $last;
            array_splice($edges, $pick, 1);
        }
        return $out;
    }

    /**
     * How many of N players reach the knockout: the best 50%, but never
     * fewer than two - so a knockout stage always exists and N = 2 or 3
     * means round 1 and then a final.
     */
    public static function advancers(int $n): int
    {
        return max(2, (int)ceil($n / 2));
    }

    /**
     * The tie-break ladder, applied in order: points, then head-to-head,
     * then score difference, then a seeded coin toss.
     *
     * Head-to-head applies ONLY to a tied pair, and only when their round-1
     * meeting exists and was decisive. The schedule is SPARSE: most pairs
     * never meet at all, and a group of three tied players usually has no
     * complete sub-tournament between them to read - so a head-to-head rule
     * that tried to cover those cases would be arbitrary, not fair.
     *
     * The final tie-break is a coin toss the seed fixes: deterministic,
     * reproducible from public information, and decided before any result
     * existed, so it can never be tuned to a standing it would change.
     *
     * @param list<array{seat:int,id:string,pts:float,diff:int}> $rows
     * @param array<string,int> $h2h "lo:hi" seat key => winning seat, for
     *        DECISIVE round-1 meetings only
     * @return list<array{seat:int,id:string,pts:float,diff:int,rank:int}>
     */
    public static function rank(array $rows, array $h2h, string $seed): array
    {
        $groups = [];
        foreach ($rows as $r) {
            $groups[(string)$r['pts']][] = $r;
        }
        krsort($groups, SORT_NUMERIC);

        $out = [];
        foreach ($groups as $g) {
            if (count($g) === 2) {
                $key = min($g[0]['seat'], $g[1]['seat']) . ':' . max($g[0]['seat'], $g[1]['seat']);
                if (isset($h2h[$key])) {
                    if ($g[1]['seat'] === $h2h[$key]) {
                        $g = [$g[1], $g[0]];
                    }
                    array_push($out, ...$g);
                    continue;
                }
            }
            usort($g, static fn(array $a, array $b): int => ($b['diff'] <=> $a['diff'])
                ?: strcmp(self::coin($seed, $a['id']), self::coin($seed, $b['id'])));
            array_push($out, ...$g);
        }
        foreach ($out as $i => &$r) {
            $r['rank'] = $i + 1;
        }
        unset($r);
        return $out;
    }

    /** The seeded coin toss of tie-break 4: lowest hex wins. */
    public static function coin(string $seed, string $id): string
    {
        return hash('sha256', $seed . '|' . $id);
    }

    /** Bracket size: the next power of two at or above the advancer count. */
    public static function size(int $a): int
    {
        $b = 1;
        while ($b < $a) {
            $b *= 2;
        }
        return $b;
    }

    /**
     * Seed placement by the standard recursive fold: P(1) = [1], and P(2k)
     * interleaves each s of P(k) with (2k+1-s). B = 8 gives
     * [1,8,4,5,2,7,3,6]. Adjacent pairs are the first-round matches, and
     * their winners pair by adjacency again - which is what keeps the top
     * two seeds apart until the final.
     *
     * @return list<int> seed numbers, 1-based, in bracket position order
     */
    public static function positions(int $b): array
    {
        $p = [1];
        while (count($p) < $b) {
            $k = count($p) * 2;
            $next = [];
            foreach ($p as $s) {
                $next[] = $s;
                $next[] = $k + 1 - $s;
            }
            $p = $next;
        }
        return $p;
    }

    /**
     * The whole knockout as a flat list of nodes in play order. $seatOf maps
     * a 1-based seed number to a seat; seeds above A are PHANTOMS whose slot
     * is null, which is exactly what makes their opponent's bye.
     *
     * A first-round node can never be two phantoms: the fold pairs seed s
     * with B+1-s, the low half is s <= B/2, and B/2 < A always - so every
     * low-half seed is real.
     *
     * Later rounds start empty and are filled by `to`/`slot` as their
     * feeders settle. `round` is the stage number the roles sheet reports:
     * 1 is the sparse round, so the knockout starts at 2.
     *
     * @param list<int> $seatOf index 0 = seed 1
     * @return list<array{nid:string,a:?int,b:?int,to:?string,slot:int,round:int}>
     */
    public static function build(array $seatOf): array
    {
        $a = count($seatOf);
        $slots = [];
        foreach (self::positions(self::size($a)) as $s) {
            $slots[] = $s <= $a ? $seatOf[$s - 1] : null;
        }
        $rounds = [];
        $stage = 1;
        while (count($slots) > 1) {
            $half = intdiv(count($slots), 2);
            $r = [];
            for ($i = 0; $i < $half; $i++) {
                $r[] = [
                    // The last node is 'final', never 'koN.1': it is the one
                    // node clients name, and the one played at 3 hearts.
                    'nid' => $half === 1 ? 'final' : 'ko' . $stage . '.' . ($i + 1),
                    'a' => $slots[$i * 2],
                    'b' => $slots[$i * 2 + 1],
                    'to' => null,
                    'slot' => 0,
                    'round' => $stage + 1,
                ];
            }
            $rounds[] = $r;
            $slots = array_fill(0, $half, null);
            $stage++;
        }
        $out = [];
        foreach ($rounds as $ri => $r) {
            foreach ($r as $i => $node) {
                if (isset($rounds[$ri + 1])) {
                    $node['to'] = $rounds[$ri + 1][intdiv($i, 2)]['nid'];
                    $node['slot'] = $i % 2;
                }
                $out[] = $node;
            }
        }
        return $out;
    }

    /**
     * Hearts for a node. Everything is played at 2 - a tournament of
     * 3-heart duels is far too long - except the final, which is a normal
     * duel because that is the match everyone is watching.
     */
    public static function hearts(string $nid): int
    {
        return $nid === 'final' ? 3 : 2;
    }
}
