<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Util.php';
require_once __DIR__ . '/Caps.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Alerts.php';
require_once __DIR__ . '/Presence.php';
require_once __DIR__ . '/Ident.php';
require_once __DIR__ . '/Events.php';

/**
 * What a player does TO the id rather than with it (docs/API.md, "POST
 * /api/account.php"): removing it, and moving it to another device. Both
 * exist because a game installed from a store has to offer them - a
 * player must be able to delete the account it created, and a new phone
 * must be able to take the id over without an operator in the loop.
 *
 * REMOVAL is the one transaction the operator's delete_player and the
 * owner's delete share, so the two cannot clean up different halves of a
 * player; what differs is how much goes (see remove()).
 *
 * A MOVE is a short code the old device asks for and the new device
 * presents: 8 characters of the poster alphabet, valid CODE_TTL seconds,
 * used once. Claiming it REBINDS the id - a fresh token for the new
 * device, the old one retired in the same write - so two devices never
 * share an id and a stolen code cannot clone one. The codes live in shared
 * memory and nowhere else: like the mailbox, a host without it has no
 * move (503), never a slower one. Namespaced, because a code names a row
 * in a per-environment database.
 */
final class Account
{
    public const CODE_LEN = 8;
    public const CODE_TTL = 300;

    /** code -> id */
    private const CODES = FOK_APCU_NS . 'xf:';
    /** address -> wrong claims in the running minute (the Events::noteFail shape) */
    private const FAILS = FOK_APCU_NS . 'xff:';
    private const FAILS_WINDOW = 60;

    /**
     * Removes a player in ONE transaction. Always: the player row, the
     * friendships (each friend is told), the presence, the connection
     * state, the event memberships (Presence::forget), the item instances
     * they own and the identity binding - an operator removing a client
     * takes its property, and the owner leaving takes their own. With
     * $everything, the owner's delete: the scores under the id and the
     * config backup go too, because "delete my account" means the name on
     * the top list and the blob in the vault as much as the row. The
     * operator's delete keeps both: vault_export is manual recovery, and a
     * score is the record of a game somebody watched. The ledger is
     * append-only audit either way and stays.
     */
    public static function remove(string $id, bool $everything): void
    {
        Db::retry(static function () use ($id, $everything): void {
            $db = Db::get();
            $db->exec('BEGIN IMMEDIATE');
            try {
                Presence::forget($id);
                $db->prepare('DELETE FROM items WHERE owner = ?')->execute([$id]);
                $db->prepare('DELETE FROM ident WHERE id = ?')->execute([$id]);
                if ($everything) {
                    $db->prepare('DELETE FROM scores WHERE player_id = ?')->execute([$id]);
                    $db->prepare('DELETE FROM vault WHERE id = ?')->execute([$id]);
                }
                $db->exec('COMMIT');
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->exec('ROLLBACK');
                }
                throw $e;
            }
        });
    }

    /**
     * The old device's ask: a fresh code for $id. null without shared memory.
     * @return ?array{code: string, valid: int}
     */
    public static function transfer(string $id): ?array
    {
        if (!Caps::apcu()) {
            return null;
        }
        $A = Events::ALPHABET;
        do {
            $code = '';
            for ($i = 0; $i < self::CODE_LEN; $i++) {
                $code .= $A[random_int(0, strlen($A) - 1)];
            }
            // apcu_add arbitrates: a code somebody else holds is not reused.
        } while (!apcu_add(self::CODES . $code, $id, self::CODE_TTL));
        return ['code' => $code, 'valid' => self::CODE_TTL];
    }

    /**
     * The new device's claim. The code is CLAIMED by delete, the mailbox's
     * shape, so two claims racing on one code hand it to one of them; the
     * winner is answered the id and the token rebind() minted for it. null
     * is unknown, expired or already used alike - one answer, so a guess
     * learns nothing - and is counted against the address.
     * @return ?array{id: string, tok: string}
     */
    public static function claim(string $code, string $ip): ?array
    {
        $id = apcu_fetch(self::CODES . $code, $ok);
        if (!$ok || !is_string($id) || !apcu_delete(self::CODES . $code)) {
            self::noteFail($ip);
            return null;
        }
        $tok = Ident::rebind($id, $ip);
        $name = Presence::namesFor([$id])[$id] ?? '';
        $name = $name === '' ? '' : ' ' . $name;
        Alerts::note('account', "id $id$name moved to a new device from $ip");
        return ['id' => $id, 'tok' => $tok];
    }

    /** Whether $ip has guessed wrong too often this minute (see claim). */
    public static function failsOver(string $ip): bool
    {
        if (!Caps::apcu()) {
            return false;
        }
        $n = apcu_fetch(self::FAILS . $ip, $ok);
        return $ok && is_int($n) && $n >= Settings::int('claim_fails_per_min');
    }

    /** Whether $code has the shape a claim may carry (see Events::ALPHABET). */
    public static function isCode(string $code): bool
    {
        return preg_match('/^[' . Events::ALPHABET . ']{' . self::CODE_LEN . '}$/', $code) === 1;
    }

    private static function noteFail(string $ip): void
    {
        if (!Caps::apcu()) {
            return;
        }
        $key = self::FAILS . $ip;
        if (apcu_add($key, 1, self::FAILS_WINDOW)) {
            $n = 1;
        } else {
            $n = apcu_inc($key);
            $n = is_int($n) ? $n : 1;
        }
        // Once per window, as the count crosses the line: the codes cannot
        // be guessed, so this puts the attempt on record.
        if ($n === Settings::int('claim_fails_per_min')) {
            Alerts::warn('account', "wrong transfer codes from $ip: $n in a minute");
        }
    }
}
