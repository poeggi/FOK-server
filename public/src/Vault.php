<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

/**
 * Per-player stats backup: an OPAQUE blob the client packs (its scores, shop
 * items, settings - whatever it wants to carry to a new device). The server
 * never interprets the payload; the format and its versioning are the
 * client's, documented as a manifest in docs/API.md. One row per player id.
 *
 * SECURITY (future): retrieval and overwrite are keyed by player id ALONE,
 * and ids are exchanged during a duel - so anyone who learns an id can read
 * or replace that player's backup. This is deliberately open for now. Before
 * clients trust it with anything sensitive, bind a backup to a shared secret:
 * store a hash of a client-supplied secret on the first backup and require it
 * for both restore and overwrite. See the matching note in api/backup.php.
 */
final class Vault
{
    /** Stores (or replaces) a player's backup; returns the stored timestamp. */
    public static function put(string $id, string $payload): int
    {
        $now = time();
        Db::get()->prepare(
            'INSERT INTO vault (id, payload, updated) VALUES (?, ?, ?)
             ON CONFLICT (id) DO UPDATE SET payload = excluded.payload, updated = excluded.updated'
        )->execute([$id, $payload, $now]);
        return $now;
    }

    /** @return array{payload:string,updated:int}|null the backup, or null if none. */
    public static function get(string $id): ?array
    {
        $st = Db::get()->prepare('SELECT payload, updated FROM vault WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        return $row === false
            ? null
            : ['payload' => $row['payload'], 'updated' => (int)$row['updated']];
    }
}
