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
    /**
     * Stores a bundle under a fresh PIN and returns it. Prunes expired
     * datasets first so their PINs come free.
     * @throws RuntimeException if a free PIN cannot be found (space full).
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
                $ins->execute([$pin, $payload, strlen($payload), $now]);
                return $pin;
            } catch (PDOException $e) {
                // PIN already taken (PRIMARY KEY): draw another.
            }
        }
        throw new RuntimeException('debug pin space full');
    }

    /** @return array{payload:string,created:int}|null the dataset, or null if unknown/expired. */
    public static function get(string $pin): ?array
    {
        $st = Db::get()->prepare('SELECT payload, created FROM debug WHERE pin = ? AND created > ?');
        $st->execute([$pin, time() - FOK_DEBUG_TTL]);
        $row = $st->fetch();
        return $row === false ? null : ['payload' => $row['payload'], 'created' => (int)$row['created']];
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
