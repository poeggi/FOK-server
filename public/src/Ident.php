<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Util.php';
require_once __DIR__ . '/Caps.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Alerts.php';
require_once __DIR__ . '/Presence.php';

/**
 * Proof that the caller owns the id it names (docs/API.md, "Identity
 * token"). An id is public - it is the friend code, it is on every roster
 * - so on its own it proves nothing. The token is what proves it: 16
 * random bytes this server mints on the first hello that asks, answered
 * once, presented on every request that names the id from then on. Only
 * its SHA-256 is stored (the ident table). The presence entry carries that
 * hash from the session open, so the steady-state check is the
 * shared-memory fetch the beat does anyway and poll.php reads no row while
 * the player is here; a cold entry reads the row once, beside the session
 * write it costs already.
 *
 * An id is unbound (no row) or bound. hello binds an unbound id - trust on
 * first use: the first hello that carries a `tok` member at all, null or
 * not, is answered a freshly minted token. Every other request needs the
 * bound id and its token, and is refused otherwise.
 *
 * The admin API is outside all of this: it has a session of its own.
 */
final class Ident
{
    /**
     * Wrong tokens per (id, address) pair in a fixed window, the
     * Events::noteFail shape. The PAIR on purpose: per id alone a stranger
     * could lock an owner out from anywhere, per address alone one phone
     * on a venue's WiFi would lock out everybody behind that NAT. Counted
     * AFTER the check failed, never before it, so the right token is never
     * refused, from any address. Namespaced: it is judged against the
     * ident table, which is per environment.
     */
    private const FAILS = FOK_APCU_NS . 'if:';
    private const FAILS_WINDOW = 60;

    /** A bind onto an id first seen longer ago than this is worth a log line. */
    private const LATE_BIND_AFTER = 86400;

    /**
     * What this request learned about each id's binding, so an endpoint
     * whose gate read the row hands the same answer to the session open
     * without a second query. The token's hash, or null: unbound.
     * @var array<string, ?string>
     */
    private static array $memo = [];

    public static function hashOf(string $tok): string
    {
        return hash('sha256', $tok);
    }

    /**
     * The token a request carries, as [$sent, $tok]: whether the `tok`
     * member was there at all - which is what lets a hello bind - and the
     * string it held, null for an absent or null member. A member of any
     * other shape is a malformed request, not a wrong token.
     * @return array{0: bool, 1: ?string}
     */
    public static function read(array $src): array
    {
        if (!array_key_exists('tok', $src)) {
            return [false, null];
        }
        $t = $src['tok'];
        if ($t === null || $t === '') {
            return [true, null];
        }
        if (!is_string($t) || strlen($t) > 64) {
            Util::fail('invalid tok');
        }
        return [true, $t];
    }

    /**
     * The gate every player-facing endpoint but hello passes right after
     * the id is validated (hello has its own, below: it is where an id is
     * bound). Returns for a caller that proved the id; answers 401 or 429
     * and never returns otherwise, so nothing else runs for an impostor -
     * no beat, no row, no counter.
     */
    public static function require(string $id, ?string $tok, string $ip): void
    {
        self::answer(self::verify($id, $tok, $ip));
    }

    /**
     * hello's gate, and the ONE place an id is bound. Returns the token to
     * answer - just minted, to be stored by the client - or null when the
     * caller is simply proven; refuses exactly like require() otherwise.
     */
    public static function hello(string $id, bool $sent, ?string $tok, string $ip): ?string
    {
        $r = self::register($id, $sent, $tok, $ip);
        self::answer($r['code']);
        return $r['tok'];
    }

    /**
     * The decision behind require(), as the status it answers: 200 when
     * $tok proves $id, 401 when it does not, 429 for a wrong token from a
     * pair over ident_fails_per_min. Only a wrong token is counted; a
     * missing one, or any token for an unbound id, is a plain 401.
     */
    public static function verify(string $id, ?string $tok, string $ip): int
    {
        $hash = self::bindingOf($id);
        if ($hash === null || $tok === null) {
            return 401;
        }
        return hash_equals($hash, self::hashOf($tok)) ? 200 : self::noteFail($id, $ip);
    }

    /**
     * The decision behind hello(): the status, and the token to answer when
     * this hello bound the id. An unbound id is bound by a hello that
     * carries the member; a hello without it binds nothing and is refused.
     * @return array{code: int, tok: ?string}
     */
    public static function register(string $id, bool $sent, ?string $tok, string $ip): array
    {
        if (!$sent || self::bindingOf($id) !== null) {
            return ['code' => self::verify($id, $tok, $ip), 'tok' => null];
        }
        $tok = self::bind($id, $ip);
        return ['code' => $tok === null ? 401 : 200, 'tok' => $tok];
    }

    /**
     * Whether $tok is the proof of $id: bound, and this is its token. The
     * strict question the vault, the account delete and the moderation
     * actions ask beside the gate, and nothing is counted here.
     */
    public static function proves(string $id, ?string $tok): bool
    {
        $hash = self::bindingOf($id);
        return $hash !== null && $tok !== null && hash_equals($hash, self::hashOf($tok));
    }

    /**
     * Operator-only: drops the binding, so the next hello that asks mints
     * again. How a hijacked or lost id is handed back to its owner - and
     * briefly claimable by anyone who knows the id, which is why it is a
     * deliberate step behind the admin login.
     * @return bool false when the id was not bound
     */
    public static function reset(string $id): bool
    {
        $gone = (bool)Db::retry(static function () use ($id): bool {
            $st = Db::get()->prepare('DELETE FROM ident WHERE id = ?');
            $st->execute([$id]);
            return $st->rowCount() > 0;
        });
        self::$memo[$id] = null;
        Presence::setTok($id, null);
        return $gone;
    }

    /**
     * The row as the admin reads it, or null when unbound. bound_ip is ''
     * on a row bound by no hello (copied off the vault by schema 49).
     * @return ?array{bound_at: int, bound_ip: string}
     */
    public static function infoOf(string $id): ?array
    {
        $st = Db::get()->prepare('SELECT bound_at, bound_ip FROM ident WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        $st->closeCursor();
        return $row === false ? null
            : ['bound_at' => (int)$row['bound_at'], 'bound_ip' => (string)$row['bound_ip']];
    }

    /**
     * The identity field a presence entry carries (see Presence::touch):
     * the token's hash, null while unbound.
     * @return array{tok: ?string}
     */
    public static function entryFields(string $id): array
    {
        return ['tok' => self::bindingOf($id)];
    }

    /** Test-suite only: forgets what this process learned about bindings. */
    public static function flush(): void
    {
        self::$memo = [];
    }

    /**
     * What binds the id right now: the presence entry where it carries the
     * answer, the row otherwise - and an entry without the answer is given
     * it, so the next request reads no row. Memoised per request.
     */
    private static function bindingOf(string $id): ?string
    {
        if (array_key_exists($id, self::$memo)) {
            return self::$memo[$id];
        }
        $e = Presence::entryOf($id);
        if ($e !== null && array_key_exists('tok', $e)) {
            $hash = $e['tok'] === null ? null : (string)$e['tok'];
        } else {
            $st = Db::get()->prepare('SELECT tok_hash FROM ident WHERE id = ?');
            $st->execute([$id]);
            $row = $st->fetch();
            $st->closeCursor();
            $hash = $row === false ? null : (string)$row['tok_hash'];
            if ($e !== null) {
                Presence::setTok($id, $hash);
            }
        }
        self::$memo[$id] = $hash;
        return $hash;
    }

    /**
     * Mints and stores; null when the id turned out to be bound after all.
     * The insert arbitrates: two devices binding one id in the same moment
     * both mint, one row lands, and the other caller is an impostor to it
     * from that moment - refused, never handed a token that proves
     * nothing. A bind onto an id somebody registered long ago is worth a
     * line: it is the first hello after a long absence, or a stranger.
     */
    private static function bind(string $id, string $ip): ?string
    {
        $tok = bin2hex(random_bytes(16));
        $hash = self::hashOf($tok);
        $now = time();
        $won = (bool)Db::retry(static function () use ($id, $hash, $now, $ip): bool {
            $st = Db::get()->prepare(
                'INSERT OR IGNORE INTO ident (id, tok_hash, bound_at, bound_ip) VALUES (?, ?, ?, ?)'
            );
            $st->execute([$id, $hash, $now, $ip]);
            return $st->rowCount() > 0;
        });
        if (!$won) {
            unset(self::$memo[$id]);
            return null;
        }
        self::$memo[$id] = $hash;
        Presence::setTok($id, $hash);
        $st = Db::get()->prepare('SELECT name, first_seen FROM players WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        $st->closeCursor();
        if ($row !== false && $now - (int)$row['first_seen'] > self::LATE_BIND_AFTER) {
            $days = intdiv($now - (int)$row['first_seen'], 86400);
            $name = $row['name'] === null ? '' : ' ' . $row['name'];
            Alerts::note('ident', "id $id$name bound $days days after first sight from $ip");
        }
        return $tok;
    }

    /** Turns a refusal into its answer; returns for 200. */
    private static function answer(int $code): void
    {
        if ($code === 429) {
            Util::jsonOut(['ok' => false, 'error' => 'too many attempts',
                'retry_after' => self::FAILS_WINDOW], 429);
        }
        if ($code !== 200) {
            Util::fail('bad token', 401);
        }
    }

    /**
     * Counts a wrong token for the pair and says how to refuse it: 401 up
     * to ident_fails_per_min in the window, 429 past it. Without shared
     * memory nothing is counted and every wrong token is a 401.
     */
    private static function noteFail(string $id, string $ip): int
    {
        if (!Caps::apcu()) {
            return 401;
        }
        $key = self::FAILS . $id . '@' . $ip;
        if (apcu_add($key, 1, self::FAILS_WINDOW)) {
            $n = 1;
        } else {
            $n = apcu_inc($key);
            $n = is_int($n) ? $n : 1;
        }
        $cap = Settings::int('ident_fails_per_min');
        // Once per window, as the count crosses the line: a stolen id
        // being tried, or a second device on a stale backup.
        if ($n === $cap) {
            Alerts::warn('ident', "wrong token for $id from $ip: $n in a minute");
        }
        return $n > $cap ? 429 : 401;
    }
}
