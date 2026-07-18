<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

/**
 * Per-player stats backup: an OPAQUE blob the client packs (its settings,
 * friends, high scores - its whole config; see docs/API.md). The server
 * never interprets the payload. One row per player id.
 *
 * A backup is bound to its owner by a SECRET TOKEN: the first backup mints a
 * 128-bit token, returns it once, and stores only its SHA-256 hash. Every
 * later backup of the same id AND every restore must present that token; it
 * never changes. A client that loses its token loses access to its backup -
 * that is the price of not letting anyone who knows an id read or overwrite
 * it (ids are exchanged during a duel). The token is high-entropy, so a fast
 * hash is enough; comparisons are constant-time.
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
     * Admin-only: clears a backup's token so the owner can re-enroll - its
     * NEXT backup (sent without a token) mints a fresh one. The payload is
     * kept, so a client that lost its token recovers: operator exports the
     * data, resets the token, then the client re-backs-up with a new token.
     * Briefly leaves the row claimable by anyone who knows the id, so it is a
     * deliberate operator step taken as the client is about to re-enroll.
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
        return $row === false ? null : [
            'payload' => $row['payload'],
            'token_hash' => (string)$row['token_hash'],
            'updated' => (int)$row['updated'],
        ];
    }
}
