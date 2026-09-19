<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Presence.php';

/**
 * Removing a player (docs/API.md, "POST /api/account.php"). The ONE
 * transaction the operator's delete_player and the owner's delete share, so
 * the two cannot clean up different halves of a player; what differs is
 * how much goes (see remove()). The owner's form exists because a game
 * installed from a store has to offer it.
 */
final class Account
{
    /**
     * Removes a player in ONE transaction. Always: the player row, the
     * friendships (each friend is told), the blocks, the presence, the
     * connection state, the event memberships (Presence::forget), the item
     * instances they own and the identity binding - an operator removing a
     * client takes its property, and the owner leaving takes their own.
     * With $everything, the owner's delete: the scores under the id and
     * the config backup go too, because "delete my account" means the name
     * on the top list and the blob in the vault as much as the row. The
     * operator's delete keeps both: vault_export is manual recovery, and a
     * score is the record of a game somebody watched. The ledger and the
     * reports are the operator's record either way and stay.
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
}
