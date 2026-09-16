<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

/**
 * Per-player config backup: an OPAQUE blob (the client's whole config; see
 * docs/API.md), one row per id. Who may read or replace it is the identity
 * token's question, answered in front of every call here (see Ident and
 * api/backup.php): the vault stores and hands back, and never judges.
 *
 * The token_hash column is the vault's own token from before the identity
 * existed, copied into the ident table by schema 49 and dead since; it is
 * left in place, written by nothing, until the host's SQLite is known to
 * drop columns.
 */
final class Vault
{
    /** Stores (or replaces) a player's backup; the write is one upsert. */
    public static function backup(string $id, string $payload): int
    {
        $now = time();
        Db::retry(static function () use ($id, $payload, $now): void {
            Db::get()->prepare(
                'INSERT INTO vault (id, payload, token_hash, updated) VALUES (?, ?, \'\', ?)
                 ON CONFLICT (id) DO UPDATE SET payload = excluded.payload, updated = excluded.updated'
            )->execute([$id, $payload, $now]);
        });
        return $now;
    }

    /**
     * A player's backup, or null when there is none.
     * @return ?array{payload: string, updated: int}
     */
    public static function restore(string $id): ?array
    {
        $st = Db::get()->prepare('SELECT payload, updated FROM vault WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        $st->closeCursor();
        return $row === false ? null : ['payload' => $row['payload'], 'updated' => (int)$row['updated']];
    }
}
