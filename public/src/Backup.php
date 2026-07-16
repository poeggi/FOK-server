<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';

final class Backup
{
    public static function create(): string
    {
        if (!is_dir(FOK_BACKUP_DIR)) {
            mkdir(FOK_BACKUP_DIR, 0770, true);
        }
        $name = 'fok-' . gmdate('Ymd-His') . '.db';
        $dest = FOK_BACKUP_DIR . '/' . $name;
        $src = new SQLite3(FOK_DB_FILE, SQLITE3_OPEN_READONLY);
        $dst = new SQLite3($dest);
        if (!$src->backup($dst)) {
            $src->close();
            $dst->close();
            throw new RuntimeException('backup failed');
        }
        $src->close();
        $dst->close();
        return $name;
    }

    public static function list(): array
    {
        if (!is_dir(FOK_BACKUP_DIR)) {
            return [];
        }
        $out = [];
        foreach (scandir(FOK_BACKUP_DIR, SCANDIR_SORT_DESCENDING) as $f) {
            if (preg_match('/^fok-[0-9]{8}-[0-9]{6}\.db$/', $f)) {
                $out[] = ['name' => $f, 'size' => filesize(FOK_BACKUP_DIR . '/' . $f)];
            }
        }
        return $out;
    }

    public static function isValidName(string $name): bool
    {
        return preg_match('/^fok-[0-9]{8}-[0-9]{6}\.db$/', $name) === 1;
    }

    /** Replaces the live database with an uploaded SQLite file. */
    public static function restore(string $uploadedFile): void
    {
        $head = (string)file_get_contents($uploadedFile, false, null, 0, 16);
        if (!str_starts_with($head, 'SQLite format 3')) {
            throw new RuntimeException('not a SQLite database');
        }
        Db::close();
        // Drop stale WAL sidecars so the restored file is read as-is.
        @unlink(FOK_DB_FILE . '-wal');
        @unlink(FOK_DB_FILE . '-shm');
        if (!copy($uploadedFile, FOK_DB_FILE)) {
            throw new RuntimeException('restore copy failed');
        }
    }
}
