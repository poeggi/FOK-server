<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

/**
 * Per-player config backup: an OPAQUE blob (the client's whole config; see
 * docs/API.md), one row per id. Bound to its owner by a SECRET TOKEN: the
 * first backup mints a 128-bit token (only its SHA-256 hash is stored) and
 * every later backup and restore must present it - so knowing an id (they
 * are shared during a duel) is not enough to read or overwrite a backup.
 * Lose the token, lose access. Constant-time compare.
 */
final class Vault
{
    /**
     * Stores (or replaces) a player's backup. The first backup for an id (or
     * a pre-token row) mints a new token; a later one must present the
     * matching token, which is returned unchanged.
     * @return array{token:string,updated:int}|null null = missing/wrong token.
     */
    public static function backup(string $id, string $payload, ?string $token): ?array
    {
        $row = self::fetch($id);
        if ($row === null || $row['token_hash'] === '') {
            $token = bin2hex(random_bytes(16));
            $hash = hash('sha256', $token);
        } elseif ($token !== null && hash_equals($row['token_hash'], hash('sha256', $token))) {
            $hash = $row['token_hash'];
        } else {
            return null;
        }
        $now = time();
        Db::get()->prepare(
            'INSERT INTO vault (id, payload, token_hash, updated) VALUES (?, ?, ?, ?)
             ON CONFLICT (id) DO UPDATE SET payload = excluded.payload,
                 token_hash = excluded.token_hash, updated = excluded.updated'
        )->execute([$id, $payload, $hash, $now]);
        return ['token' => $token, 'updated' => $now];
    }

    /**
     * Restores a player's backup; the token must match the one minted on the
     * first backup.
     * @return array{payload:string,updated:int}|false|null
     *   array = ok, false = wrong token, null = no backup for this id.
     */
    public static function restore(string $id, string $token): array|false|null
    {
        $row = self::fetch($id);
        if ($row === null) {
            return null;
        }
        if (!hash_equals($row['token_hash'], hash('sha256', $token))) {
            return false;
        }
        return ['payload' => $row['payload'], 'updated' => $row['updated']];
    }

    /**
     * Admin-only read: the raw backup for an id WITHOUT the token, for a
     * MANUAL recovery (an operator retrieving a config for a client that lost
     * its token). Never exposed on the client API - only behind /admin.
     * 'enrolled' is false once the token has been reset (see resetToken).
     * @return array{payload:string,updated:int,enrolled:bool}|null
     */
    public static function peek(string $id): ?array
    {
        $row = self::fetch($id);
        return $row === null ? null : [
            'payload' => $row['payload'],
            'updated' => $row['updated'],
            'enrolled' => $row['token_hash'] !== '',
        ];
    }

    /**
     * Admin-only: clears a backup's token so the owner re-enrolls on its next
     * backup (which mints a fresh one); the payload is kept. Recovery for a
     * client that lost its token. Briefly claimable by anyone who knows the
     * id, so it is a deliberate operator step.
     * @return bool false if there is no backup for the id.
     */
    public static function resetToken(string $id): bool
    {
        $st = Db::get()->prepare("UPDATE vault SET token_hash = '' WHERE id = ?");
        $st->execute([$id]);
        return $st->rowCount() > 0;
    }

    /** @return array{payload:string,token_hash:string,updated:int}|null */
    private static function fetch(string $id): ?array
    {
        $st = Db::get()->prepare('SELECT payload, token_hash, updated FROM vault WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        $st->closeCursor();
        return $row === false ? null : [
            'payload' => $row['payload'],
            'token_hash' => (string)$row['token_hash'],
            'updated' => (int)$row['updated'],
        ];
    }
}
