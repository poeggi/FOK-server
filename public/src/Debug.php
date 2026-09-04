<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

/**
 * Debug datasets: a client submits a bundle (logs, debug info, up to two
 * image snapshots) and gets a short 4-digit PIN naming it. The user reads
 * the PIN to support, who looks it up in the admin dashboard. Stored
 * VERBATIM, kept FOK_DEBUG_TTL (a day) then purged - the PIN space is only
 * 10000, so the short retention keeps it usable. The PIN is a handle, not a
 * secret: retrieval is admin-only.
 */
final class Debug
{
    /** The driver code for a constraint violation - here, a PIN already in use. */
    private const SQLITE_CONSTRAINT = 19;

    /**
     * Stores a bundle under a fresh PIN and returns it. Prunes expired
     * datasets first so their PINs come free.
     * @throws RuntimeException if a free PIN cannot be found (space full).
     * @throws PDOException if the write itself fails - that is not a full space.
     */
    public static function submit(string $payload): string
    {
        $db = Db::get();
        $now = time();
        $db->prepare('DELETE FROM debug WHERE created < ?')->execute([$now - FOK_DEBUG_TTL]);
        $ins = $db->prepare('INSERT INTO debug (pin, payload, bytes, created) VALUES (?, ?, ?, ?)');
        for ($try = 0; $try < 30; $try++) {
            $pin = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            try {
                Db::retry(static fn(): bool => $ins->execute([$pin, $payload, strlen($payload), $now]));
                return $pin;
            } catch (PDOException $e) {
                // A PIN already taken (PRIMARY KEY, SQLITE_CONSTRAINT) is the
                // only reason to draw again. Everything else - a database
                // locked by a writer that never let go, a full disk - has
                // nothing to do with the PIN space, and swallowing it 30
                // times to report "space full" over a table holding two rows
                // sends whoever reads that message somewhere there is nothing
                // to find. Say what actually happened instead.
                if ((int)($e->errorInfo[1] ?? 0) !== self::SQLITE_CONSTRAINT) {
                    throw $e;
                }
            }
        }
        throw new RuntimeException('debug pin space full');
    }

    /** @return array{payload:string}|null the dataset, or null if unknown/expired. */
    public static function get(string $pin): ?array
    {
        $st = Db::get()->prepare('SELECT payload FROM debug WHERE pin = ? AND created > ?');
        $st->execute([$pin, time() - FOK_DEBUG_TTL]);
        $row = $st->fetch();
        $st->closeCursor();
        return $row === false ? null : ['payload' => $row['payload']];
    }

    /**
     * Recent datasets for the operator, newest first (no payloads).
     * @return array<array{pin:string,bytes:int,created:int}>
     */
    public static function recent(int $limit = 100): array
    {
        $st = Db::get()->prepare(
            'SELECT pin, bytes, created FROM debug WHERE created > ? ORDER BY created DESC LIMIT ' . $limit
        );
        $st->execute([time() - FOK_DEBUG_TTL]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[] = ['pin' => $r['pin'], 'bytes' => (int)$r['bytes'], 'created' => (int)$r['created']];
        }
        return $out;
    }

    /**
     * Removes the named datasets, freeing their PINs. Unknown PINs are ignored.
     * @param string[] $pins
     * @return int datasets removed
     */
    public static function delete(array $pins): int
    {
        if ($pins === []) {
            return 0;
        }
        $marks = implode(',', array_fill(0, count($pins), '?'));
        $st = Db::get()->prepare('DELETE FROM debug WHERE pin IN (' . $marks . ')');
        $st->execute($pins);
        return $st->rowCount();
    }
}
