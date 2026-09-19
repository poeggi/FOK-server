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
 * - so on its own it proves nothing, and before this every request was
 * believed. The token is what proves it: 16 random bytes this server
 * mints on the first hello that asks, answered once, presented on every
 * request that names the id from then on. Only its SHA-256 is stored
 * (the ident table). The presence entry carries that hash from the
 * session open, so the steady-state check is the shared-memory fetch the
 * beat does anyway and poll.php reads no row while the player is here; a
 * cold entry reads the row once, beside the session write it costs
 * already.
 *
 * The states an id can be in:
 *   unbound     no row. hello binds it - trust on first use: the first
 *               hello that carries a `tok` member at all, null or not,
 *               is a client that speaks 4.20, and it is answered a
 *               freshly minted token.
 *   copied      a row schema 49 copied off the config vault (bound_ip
 *               ''). The owner's client holds exactly that token and
 *               sends it on backup.php, and on nothing else until it
 *               updates; the first hello presenting it CONFIRMS the row.
 *   confirmed   bound_ip set: bound by a hello, or copied and confirmed.
 *
 * TEMPORARY(ident), until LEGACY_UNTIL: a request that carries no `tok`
 * passes on an id nothing proves yet - unbound, or copied and not yet
 * confirmed - so a client from before the token keeps working until it
 * updates. A request that CARRIES a token is checked in full from day
 * one, and a bound id refuses a wrong one whatever the date. From the
 * cutoff on, no proof is no entry (docs/PLAN-identity.md, step 9).
 *
 * The admin API is outside all of this: it has a session of its own.
 */
final class Ident
{
    /** 2026-10-01 00:00 UTC: the day the legacy paths close. TEMPORARY(ident). */
    public const LEGACY_UNTIL = 1790812800;

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

    /** Test-suite only: the cutoff as the tests see it (see legacyOpen). */
    private static ?int $legacyUntil = null;

    /**
     * What this request learned about each id's binding, so an endpoint
     * whose gate read the row hands the same answer to the session open
     * without a second query. null is a real answer: unbound.
     * @var array<string, ?array{hash: string, confirmed: bool}>
     */
    private static array $memo = [];

    public static function hashOf(string $tok): string
    {
        return hash('sha256', $tok);
    }

    /**
     * The token a request carries, as [$sent, $tok]: whether the `tok`
     * member was there at all - which is the mark of a client that speaks
     * 4.20 - and the string it held, null for an absent or null member.
     * Reads a JSON body and a query string alike; on the query string an
     * empty value is null. A member of any other shape is a malformed
     * request, not a wrong token.
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
     * bound). Returns for a caller that proved the id, or that may pass
     * without proof for now; answers 401 and never returns otherwise, so
     * nothing else runs for an impostor - no beat, no row, no counter.
     */
    public static function require(string $id, ?string $tok, string $ip): void
    {
        if (!self::verify($id, $tok, $ip)) {
            Util::fail('bad token', 401);
        }
    }

    /**
     * hello's gate, and the ONE place an id is bound. Returns the token to
     * answer - just minted, to be stored by the client - or null when the
     * caller is simply proven (or passes for now); answers 401 and never
     * returns otherwise, exactly like require().
     */
    public static function hello(string $id, bool $sent, ?string $tok, string $ip): ?string
    {
        $r = self::register($id, $sent, $tok, $ip);
        if (!$r['ok']) {
            Util::fail('bad token', 401);
        }
        return $r['tok'];
    }

    /**
     * The decision behind require(): whether this caller may act as $id.
     * A wrong token is put on record here (see noteFail).
     */
    public static function verify(string $id, ?string $tok, string $ip): bool
    {
        $b = self::bindingOf($id);
        if ($b !== null && $tok !== null && hash_equals($b['hash'], self::hashOf($tok))) {
            return true;
        }
        // TEMPORARY(ident): an unbound id passes with whatever it sent -
        // there is nothing to check it against, and a token it may carry
        // is one the next hello replaces - and a copied one passes when
        // it sent nothing, which is the client that has not updated yet.
        if (self::legacyOpen() && ($b === null || (!$b['confirmed'] && $tok === null))) {
            return true;
        }
        if ($b !== null && $tok !== null) {
            self::noteFail($id, $ip);
        }
        return false;
    }

    /**
     * The decision behind hello(): whether the caller may act as $id, and
     * the token to answer it when this hello bound the id.
     * @return array{ok: bool, tok: ?string}
     */
    public static function register(string $id, bool $sent, ?string $tok, string $ip): array
    {
        $b = self::bindingOf($id);
        if ($b !== null) {
            if ($tok !== null && hash_equals($b['hash'], self::hashOf($tok))) {
                if (!$b['confirmed']) {
                    self::confirm($id, $ip);
                }
                return ['ok' => true, 'tok' => null];
            }
            // TEMPORARY(ident): the owner's client on a copied id, before
            // it updates. A client that SENT the member and did not match
            // is a different device, whatever the date.
            if (!$b['confirmed'] && !$sent && self::legacyOpen()) {
                return ['ok' => true, 'tok' => null];
            }
            if ($tok !== null) {
                self::noteFail($id, $ip);
            }
            return ['ok' => false, 'tok' => null];
        }
        if (!$sent) {
            // TEMPORARY(ident): a client from before the token. Not bound:
            // it would never store the answer, and a token nobody holds
            // would lock the owner out on the day it updates.
            return ['ok' => self::legacyOpen(), 'tok' => null];
        }
        $tok = self::bind($id, $ip, true);
        return ['ok' => $tok !== null, 'tok' => $tok];
    }

    /**
     * Whether $tok is the proof of $id: bound, and this is its token. What
     * the vault asks after the gate let a request through, because the
     * gate's leniency for an id nothing proves yet (TEMPORARY(ident)) must
     * not extend to reading or replacing what a bound id owns.
     */
    public static function proves(string $id, ?string $tok): bool
    {
        $b = self::bindingOf($id);
        return $b !== null && $tok !== null && hash_equals($b['hash'], self::hashOf($tok));
    }

    /**
     * TEMPORARY(ident): the vault's own mint, for a client from before the
     * token backing up for the first time. Until the cutoff a first backup
     * of an unbound id still mints, through the one binding path, so the
     * token such a client stores IS its identity token - and the row is
     * left unconfirmed, like a copy, because that client sends it on
     * backup.php and nowhere else until it updates. null when the id is
     * bound already or the cutoff has passed: nothing is minted then.
     */
    public static function mintLegacy(string $id, string $ip): ?string
    {
        if (!self::legacyOpen() || self::bindingOf($id) !== null) {
            return null;
        }
        return self::bind($id, $ip, false);
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
        Presence::setTok($id, null, false);
        return $gone;
    }

    /**
     * The row as the admin reads it, or null when unbound. bound_ip is ''
     * for a copy off the vault the owner has not confirmed yet.
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
     * The two identity fields a presence entry carries (see Presence::touch):
     * the hash, null while unbound, and whether the row is confirmed.
     * @return array{tok: ?string, tokc: bool}
     */
    public static function entryFields(string $id): array
    {
        $b = self::bindingOf($id);
        return ['tok' => $b === null ? null : $b['hash'], 'tokc' => $b !== null && $b['confirmed']];
    }

    /** Test-suite only: moves the cutoff, null puts the constant back. */
    public static function setLegacyUntil(?int $t): void
    {
        self::$legacyUntil = $t;
    }

    /** Test-suite only: forgets what this process learned about bindings. */
    public static function flush(): void
    {
        self::$memo = [];
    }

    /**
     * What binds the id right now: the presence entry where it carries the
     * answer, the row otherwise - and an entry that predates the answer is
     * given it, so the next request reads no row. Memoised per request.
     * @return ?array{hash: string, confirmed: bool}
     */
    private static function bindingOf(string $id): ?array
    {
        if (array_key_exists($id, self::$memo)) {
            return self::$memo[$id];
        }
        $e = Presence::entryOf($id);
        if ($e !== null && array_key_exists('tok', $e)) {
            $b = $e['tok'] === null ? null
                : ['hash' => (string)$e['tok'], 'confirmed' => !empty($e['tokc'])];
        } else {
            $st = Db::get()->prepare('SELECT tok_hash, bound_ip FROM ident WHERE id = ?');
            $st->execute([$id]);
            $row = $st->fetch();
            $st->closeCursor();
            $b = $row === false ? null
                : ['hash' => (string)$row['tok_hash'], 'confirmed' => (string)$row['bound_ip'] !== ''];
            if ($e !== null) {
                Presence::setTok($id, $b['hash'] ?? null, $b !== null && $b['confirmed']);
            }
        }
        self::$memo[$id] = $b;
        return $b;
    }

    /** TEMPORARY(ident): whether the pre-token paths are still open. */
    private static function legacyOpen(): bool
    {
        return time() < (self::$legacyUntil ?? self::LEGACY_UNTIL);
    }

    /**
     * Mints and stores; null when the id turned out to be bound after all.
     * The insert arbitrates: two devices binding one id in the same moment
     * both mint, one row lands, and the other caller is an impostor to it
     * from that moment - refused, never handed a token that proves
     * nothing. A bind by hello is confirmed; the vault's
     * legacy mint is not (see mintLegacy), and writes no late-bind line
     * either, because it is not a hello a stranger could have sent. That
     * line is permanent, not temporary: after the cutoff a bind onto an id
     * somebody registered long ago is exactly the row worth reading.
     */
    private static function bind(string $id, string $ip, bool $confirmed): ?string
    {
        $tok = bin2hex(random_bytes(16));
        $hash = self::hashOf($tok);
        $now = time();
        $bip = $confirmed ? $ip : '';
        $won = (bool)Db::retry(static function () use ($id, $hash, $now, $bip): bool {
            $st = Db::get()->prepare(
                'INSERT OR IGNORE INTO ident (id, tok_hash, bound_at, bound_ip) VALUES (?, ?, ?, ?)'
            );
            $st->execute([$id, $hash, $now, $bip]);
            return $st->rowCount() > 0;
        });
        if (!$won) {
            unset(self::$memo[$id]);
            return null;
        }
        self::$memo[$id] = ['hash' => $hash, 'confirmed' => $confirmed];
        Presence::setTok($id, $hash, $confirmed);
        if (!$confirmed) {
            return $tok;
        }
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

    /**
     * A copied row, presented by its owner's updated client: bound for
     * good. One log line per id, ever - the migration converging, readable
     * beside the late binds.
     */
    private static function confirm(string $id, string $ip): void
    {
        Db::retry(static fn() => Db::get()
            ->prepare("UPDATE ident SET bound_ip = ? WHERE id = ? AND bound_ip = ''")
            ->execute([$ip, $id]));
        $hash = self::$memo[$id]['hash'];
        self::$memo[$id] = ['hash' => $hash, 'confirmed' => true];
        Presence::setTok($id, $hash, true);
        $st = Db::get()->prepare('SELECT name FROM players WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        $st->closeCursor();
        $name = $row === false || $row['name'] === null ? '' : ' ' . $row['name'];
        Alerts::note('ident', "id $id$name confirmed its vault token from $ip");
    }

    private static function noteFail(string $id, string $ip): void
    {
        if (!Caps::apcu()) {
            return;
        }
        $key = self::FAILS . $id . '@' . $ip;
        if (apcu_add($key, 1, self::FAILS_WINDOW)) {
            $n = 1;
        } else {
            $n = apcu_inc($key);
            $n = is_int($n) ? $n : 1;
        }
        // Once per window, as the count crosses the line: a stolen id
        // being tried, or a second device on a stale backup.
        if ($n === Settings::int('ident_fails_per_min')) {
            Alerts::warn('ident', "wrong token for $id from $ip: $n in a minute");
        }
    }
}
