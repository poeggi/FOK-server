<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Caps.php';

/**
 * Tournament state, in APCu shared memory.
 *
 * A tournament is worthless the moment it ends: nothing outside Tournament
 * ever reads it, no score, item or ledger row is derived from it, and a
 * finished one is never looked at again. It is also small - a whole played-out
 * eight-player run, bracket and every result included, is about 6.6 KB - and
 * short-lived. A durable, transactional, single-writer B-tree is the wrong
 * medium for that, and it was costing roughly 76 write-lock acquisitions per
 * tournament on a database every duel and heartbeat in the server shares.
 *
 * So the whole thing lives in one APCu entry per tournament, holding exactly
 * what the old load() returned: row, players and the data blob together. The
 * lifetime totals worth keeping outlive it in the database (see Stats).
 *
 * There is NO database fallback. APCu is a hard requirement for tournament
 * mode; a host without it refuses to create one rather than carrying a second
 * implementation of a locked read-modify-write (see Tournament::create).
 *
 * The indexes are separate entries rather than a scan, because each is
 * checked on a hot path:
 *   - CODE:  the join code, unique among OPEN tournaments only (they recycle)
 *   - HOST:  the one-live-tournament-per-host guard
 *   - CD:    the per-host create cooldown, expiring on its own TTL
 *   - OPEN:  a small card per open lobby, so answering a lobby announce never
 *            deserialises a running tournament's full state
 *
 * apcu_add() is the primitive that replaces BEGIN IMMEDIATE for all of them:
 * it writes only if the key is absent, so a claim is atomic against every
 * other worker without a global lock anywhere.
 */
final class TourneyStore
{
    private const T    = 'fok:t:';
    private const CODE = 'fok:tcode:';
    private const HOST = 'fok:thost:';
    private const CD   = 'fok:tcd:';
    private const OPEN = 'fok:topen:';
    private const LOCK = 'fok:tlock:';

    /**
     * Long enough to cover a transition, short enough that a worker dying
     * mid-transition frees the tournament again rather than wedging it.
     */
    private const LOCK_TTL = 5;

    /**
     * The lock value this worker wrote, per tid. A lock is a claim by ONE
     * request, so the proof of ownership lives for exactly as long as the
     * request does (see unlock).
     *
     * @var array<string, string>
     */
    private static array $held = [];

    /** Tournament mode is unavailable without shared memory. */
    public static function usable(): bool
    {
        return Caps::apcu();
    }

    /**
     * How long a tournament in this state survives UNTOUCHED - every
     * transition re-stores it and starts the clock again, so this is a gap
     * between two of them, never a total lifetime. An open lobby expires on
     * the join TTL, which is what retires a lobby nobody ever started, with
     * no sweep to run and no 'abandoned' row left behind. A running one has
     * to outlast the longest deadline it can be waiting on. A terminal one
     * is a receipt: the podium is read off it once, by players who are
     * looking at it already, and nobody comes back to a tournament that is
     * over.
     */
    private static function ttl(array $t): int
    {
        if ($t['state'] === 'open') {
            return Settings::int('tournament_join_ttl');
        }
        if ($t['state'] === 'running') {
            return Settings::int('tournament_run_ttl');
        }
        return Settings::int('tournament_done_ttl');
    }

    /** A host's claim is held for as long as the tournament holding it. */
    private static function hostTtl(): int
    {
        return Settings::int('tournament_run_ttl');
    }

    public static function get(string $tid): ?array
    {
        $t = apcu_fetch(self::T . $tid);
        return is_array($t) ? $t : null;
    }

    /**
     * Writes the tournament back and brings its indexes with it. A code is
     * freed the moment a tournament stops being open, and the host's claim
     * the moment it stops being live, so both recycle exactly as they did
     * when a SELECT over the table decided it.
     */
    public static function put(array $t): void
    {
        unset($t['events']);
        $tid = (string)$t['tid'];
        $state = (string)$t['state'];
        apcu_store(self::T . $tid, $t, self::ttl($t));
        // The host's claim rides along with the tournament rather than
        // expiring on a clock of its own, so a long evening cannot free it
        // under a host who is still running one.
        if ($state === 'open' || $state === 'running') {
            apcu_store(self::HOST . $t['host'], $tid, self::hostTtl());
        } else {
            apcu_delete(self::HOST . $t['host']);
        }
        if ($state === 'open') {
            apcu_store(self::CODE . $t['code'], $tid, self::ttl($t));
            apcu_store(self::OPEN . $tid, [
                'tid' => $tid,
                'code' => (string)$t['code'],
                'host' => (string)$t['host'],
                'stakes' => (bool)$t['stakes'],
                'players' => count($t['players']),
                'updated' => time(),
            ], self::ttl($t));
            return;
        }
        apcu_delete(self::CODE . $t['code']);
        apcu_delete(self::OPEN . $tid);
    }

    public static function byCode(string $code): ?array
    {
        $tid = apcu_fetch(self::CODE . strtoupper($code));
        return is_string($tid) ? self::get($tid) : null;
    }

    /**
     * Claims the caller as a host, atomically. False means they already have
     * a live tournament. Held for the running TTL and refreshed with it,
     * so a host is never locked out by a lobby that quietly expired.
     */
    public static function claimHost(string $host, string $tid): bool
    {
        if (apcu_add(self::HOST . $host, $tid, self::hostTtl())) {
            return true;
        }
        // A claim whose tournament is gone is stale, not busy: the tournament
        // expired without a transition to clear it. Take it over.
        $held = apcu_fetch(self::HOST . $host);
        if (!is_string($held) || self::get($held) !== null) {
            return false;
        }
        apcu_store(self::HOST . $host, $tid, self::hostTtl());
        return true;
    }

    public static function releaseHost(string $host): void
    {
        apcu_delete(self::HOST . $host);
    }

    /** Claims a join code, atomically. False means it is taken. */
    public static function claimCode(string $code, string $tid): bool
    {
        return apcu_add(self::CODE . $code, $tid, Settings::int('tournament_join_ttl'));
    }

    /** Seconds the host must still wait before creating again, 0 if none. */
    public static function createWait(string $host): int
    {
        $last = apcu_fetch(self::CD . $host);
        if (!is_int($last)) {
            return 0;
        }
        return max(0, $last + Settings::int('tournament_create_cooldown') - time());
    }

    /** The entry expires on the cooldown itself, so nothing has to sweep it. */
    public static function markCreate(string $host): void
    {
        $cd = Settings::int('tournament_create_cooldown');
        if ($cd > 0) {
            apcu_store(self::CD . $host, time(), $cd);
        }
    }

    /**
     * Takes the tournament's lock, or returns false. Jittered and growing on
     * the same reasoning as Db::retry: two workers that just collided must
     * not line up again on the retry.
     */
    public static function lock(string $tid, int $tries = 40): bool
    {
        $token = bin2hex(random_bytes(8));
        for ($attempt = 1; $attempt <= $tries; $attempt++) {
            if (apcu_add(self::LOCK . $tid, $token, self::LOCK_TTL)) {
                self::$held[$tid] = $token;
                return true;
            }
            usleep(random_int(2000, 8000));
        }
        return false;
    }

    /**
     * Releases the lock, and ONLY if it is still the one this worker took.
     * LOCK_TTL is deliberately short - a worker that dies must not strand a
     * tournament - so a lease can expire while its holder is still running
     * and another worker can legitimately take the lock next. A blind delete
     * would then release somebody else's, handing the same tournament to a
     * third worker while the second is mid-transition. Whoever wrote the
     * entry is the only one allowed to remove it.
     */
    public static function unlock(string $tid): void
    {
        $token = self::$held[$tid] ?? null;
        unset(self::$held[$tid]);
        if ($token !== null && apcu_fetch(self::LOCK . $tid) === $token) {
            apcu_delete(self::LOCK . $tid);
        }
    }

    /**
     * The open lobbies, as small cards. Deliberately a scan of the OPEN
     * index and not of the tournaments themselves: hello asks for this, and
     * deserialising every running bracket to answer it would be worse than
     * the join it replaced.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function openLobbies(): array
    {
        $out = [];
        foreach (new APCUIterator(self::rx(self::OPEN)) as $e) {
            if (is_array($e['value'])) {
                $out[] = $e['value'];
            }
        }
        return $out;
    }

    /**
     * Every live tournament, for the admin view only - this one does pay the
     * full deserialise, and nothing on a client path calls it.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        $out = [];
        foreach (new APCUIterator(self::rx(self::T)) as $e) {
            if (is_array($e['value'])) {
                $out[] = $e['value'];
            }
        }
        return $out;
    }

    /** Anchored prefix match for APCUIterator, with the prefix quoted. */
    private static function rx(string $prefix): string
    {
        return '/^' . preg_quote($prefix, '/') . '/';
    }
}
