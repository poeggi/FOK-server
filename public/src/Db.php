<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';

final class Db
{
    private static ?PDO $pdo = null;

    public static function get(): PDO
    {
        if (self::$pdo === null) {
            if (!is_dir(FOK_DATA_DIR)) {
                mkdir(FOK_DATA_DIR, 0770, true);
            }
            $pdo = new PDO('sqlite:' . FOK_DB_FILE, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $pdo->exec('PRAGMA foreign_keys = ON');
            self::migrate($pdo);
            self::$pdo = $pdo;
        }
        return self::$pdo;
    }

    // Restore replaces the database file, so the handle must be droppable.
    public static function close(): void
    {
        self::$pdo = null;
    }

    private static function migrate(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS players (
            id TEXT PRIMARY KEY,
            ip TEXT NOT NULL,
            first_seen INTEGER NOT NULL,
            last_seen INTEGER NOT NULL,
            hello_count INTEGER NOT NULL DEFAULT 0
        )');
        $pdo->exec("CREATE TABLE IF NOT EXISTS scores (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            player_id TEXT NOT NULL,
            name TEXT NOT NULL,
            score INTEGER NOT NULL,
            level INTEGER NOT NULL,
            diff INTEGER NOT NULL DEFAULT 1,
            color INTEGER NOT NULL DEFAULT 0,
            shop_items TEXT NOT NULL DEFAULT '{}',
            seed INTEGER,
            inputs TEXT,
            validated INTEGER NOT NULL DEFAULT 0,
            created INTEGER NOT NULL
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_scores_rank ON scores (score DESC, created ASC)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS signals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            from_id TEXT NOT NULL,
            to_id TEXT NOT NULL,
            type TEXT NOT NULL,
            payload TEXT NOT NULL,
            created INTEGER NOT NULL
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_signals_to ON signals (to_id, id)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS duels (
            a TEXT NOT NULL,
            b TEXT NOT NULL,
            started INTEGER NOT NULL,
            last_seen INTEGER NOT NULL,
            PRIMARY KEY (a, b)
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS mm_queue (
            id TEXT PRIMARY KEY,
            since INTEGER NOT NULL,
            last_poll INTEGER NOT NULL,
            matched_with TEXT,
            role TEXT
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS counters (
            bucket TEXT NOT NULL,
            metric TEXT NOT NULL,
            value INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (bucket, metric)
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS admin_fails (
            ip TEXT PRIMARY KEY,
            fails INTEGER NOT NULL DEFAULT 0,
            locked_until INTEGER NOT NULL DEFAULT 0
        )');
    }
}
