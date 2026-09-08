<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Caps.php';
require_once __DIR__ . '/Util.php';
require_once __DIR__ . '/Counters.php';
require_once __DIR__ . '/Presence.php';
require_once __DIR__ . '/ConnTrack.php';
require_once __DIR__ . '/Matchmaking.php';
require_once __DIR__ . '/FriendFeed.php';

final class Backup
{
    // The trailing counter separates two backups taken in the same second.
    private const NAME = '/^fok-[0-9]{8}-[0-9]{6}(-[0-9]+)?\.db$/';

    public static function create(): string
    {
        if (!is_dir(FOK_BACKUP_DIR)) {
            mkdir(FOK_BACKUP_DIR, 0770, true);
        }
        // A restore snapshots the live database in the second an operator's
        // own backup may already occupy, and the copy must not land on the
        // file it is about to read: seconds name a backup, they do not
        // identify one.
        $stamp = 'fok-' . gmdate('Ymd-His');
        $name = $stamp . '.db';
        for ($n = 2; is_file(FOK_BACKUP_DIR . '/' . $name); $n++) {
            $name = $stamp . '-' . $n . '.db';
        }
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
            if (preg_match(self::NAME, $f)) {
                $out[] = ['name' => $f, 'size' => filesize(FOK_BACKUP_DIR . '/' . $f)];
            }
        }
        return $out;
    }

    public static function isValidName(string $name): bool
    {
        return preg_match(self::NAME, $name) === 1;
    }

    /**
     * Replaces the live database with an uploaded SQLite file, and answers
     * with the snapshot of what it replaced.
     */
    public static function restore(string $uploadedFile): string
    {
        $head = (string)file_get_contents($uploadedFile, false, null, 0, 16);
        if (!str_starts_with($head, 'SQLite format 3')) {
            throw new RuntimeException('not a SQLite database');
        }
        self::verify($uploadedFile);
        // The undo. Taken before anything moves, so the cost of the wrong
        // file is a second restore rather than the live data.
        $snapshot = self::create();
        // The mirror of create(): copy pages in through SQLite's own backup
        // API, never swap the file on disk. SQLite takes the write lock and
        // does the WAL bookkeeping, so it is safe while a connection is open
        // on the live database - and one ALWAYS is: admin/api.php holds a
        // global Db::get() across the whole request, this call included.
        // Overwriting the file behind that handle (unlink WAL + copy) let its
        // stale committed WAL frames checkpoint back over the restored pages.
        $src = new SQLite3($uploadedFile, SQLITE3_OPEN_READONLY);
        $dst = new SQLite3(FOK_DB_FILE);
        $dst->busyTimeout(5000);
        $done = $src->backup($dst);
        $src->close();
        $dst->close();
        if (!$done) {
            // A foreign upload can fail here (e.g. an incompatible page size);
            // that is fine, as long as it fails loudly rather than corrupting.
            throw new RuntimeException('restore failed');
        }
        // Any handle opened before this now holds a stale page cache; drop the
        // shared one so the next Db::get() reopens onto the restored data.
        Db::close();
        // Same reasoning one level up: the settings and capability caches in
        // shared memory describe the database that was just replaced, and
        // every worker in the pool holds them, not only this one.
        Settings::forget();
        Caps::forget();
        self::forgetEphemeral();
        // This request queued its own bookkeeping before the restore (the
        // admin endpoint counts itself), and it runs after the response is
        // flushed - i.e. into the restored database.
        Util::cancelDeferred();
        return $snapshot;
    }

    /**
     * What the upload has to be before anything is overwritten. The
     * migration ladder only moves forward, so a snapshot from a newer
     * server would leave this release reading columns that are not there.
     */
    private static function verify(string $file): void
    {
        try {
            $db = new SQLite3($file, SQLITE3_OPEN_READONLY);
        } catch (Throwable $e) {
            throw new RuntimeException('cannot open the uploaded database');
        }
        try {
            if ((string)$db->querySingle('PRAGMA quick_check') !== 'ok') {
                throw new RuntimeException('the uploaded database is damaged');
            }
            if ($db->querySingle(
                "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'players'"
            ) === null) {
                throw new RuntimeException('not a FOK database');
            }
            $v = (int)$db->querySingle('PRAGMA user_version');
            if ($v > Db::schemaVersion()) {
                throw new RuntimeException('the uploaded database is schema ' . $v
                    . ' and this server runs ' . Db::schemaVersion());
            }
        } finally {
            $db->close();
        }
    }

    /**
     * The shared memory that mirrors the database: presence entries, tracked
     * connections and the quick-match queue all name players from the
     * replaced rows, and the unfolded counters would fold into the restored
     * history.
     *
     * Only the per-environment stores (FOK_APCU_NS) go. The bare-prefix ones
     * - mailbox, holds, tournaments - can be shared with the other
     * environment on one FPM pool and hold nothing read out of a database;
     * they lapse on their own TTLs.
     */
    private static function forgetEphemeral(): void
    {
        Presence::dropEntries();
        ConnTrack::dropEntries();
        Matchmaking::dropQueue();
        Counters::dropBuffer();
        FriendFeed::dropAll();
    }
}
