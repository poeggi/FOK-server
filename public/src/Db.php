<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
// The connection is a thin PDO subclass that counts write queries for the
// admin load gauges (see Load). Requiring it here keeps Load available in
// every request (endpoint -> Util -> Db -> Load), the cycle is load-safe:
// nothing extends across it.
require_once __DIR__ . '/Load.php';

final class Db
{
    // Highest step of the migration ladder below.
    private const SCHEMA_VERSION = 18;

    private static ?PDO $pdo = null;
    private static float $bootUs = 0.0;

    /**
     * What opening the database cost this request, in microseconds. Every
     * request pays it before doing any work, which makes it the biggest
     * single cost of a short endpoint - and the one number that cannot be
     * measured on a developer box, because it is dominated by the host's
     * file system. The Properties card reports it from the real server.
     * The first request after a deploy also carries the migration.
     */
    public static function bootUs(): float
    {
        return self::$bootUs;
    }

    public static function get(): PDO
    {
        if (self::$pdo === null) {
            $t = microtime(true);
            if (!is_dir(FOK_DATA_DIR)) {
                mkdir(FOK_DATA_DIR, 0770, true);
            }
            $pdo = new LoadPDO('sqlite:' . FOK_DB_FILE, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_STATEMENT_CLASS => [LoadStatement::class],
            ]);
            $pdo->exec('PRAGMA journal_mode = WAL');
            // NORMAL (vs the WAL default FULL) drops the fsync on every
            // commit, syncing only at checkpoint: it shortens how long each
            // write holds the single writer, which is the contention ceiling
            // behind the FPM worker pool. Safe here - a power loss can lose
            // only the last transaction (monitoring counters), never corrupt
            // the file, and never on a mere application crash.
            $pdo->exec('PRAGMA synchronous = NORMAL');
            // A contended write waits at most this long for the lock, then
            // throws SQLITE_BUSY. Nothing catches that per-statement, so it
            // surfaces as the generic 500 "server fault" and the write is
            // simply lost - a dropped signal or game message. Hence both the
            // higher ceiling here and Db::retry() on the writes that matter;
            // still low enough that a real database stall frees workers
            // rather than pinning the whole pool.
            $pdo->exec('PRAGMA busy_timeout = 4000');
            $pdo->exec('PRAGMA foreign_keys = ON');
            self::migrate($pdo);
            self::$pdo = $pdo;
            self::$bootUs = (microtime(true) - $t) * 1e6;
        }
        return self::$pdo;
    }

    // Restore replaces the database file, so the handle must be droppable.
    public static function close(): void
    {
        self::$pdo = null;
    }

    /**
     * READ CURSORS MUST BE CLOSED BEFORE WRITING. A fetch that stops early -
     * fetchColumn(), fetch() - leaves the statement open, which keeps this
     * connection on a read snapshot. If another connection commits while that
     * snapshot is held, the next write here fails with SQLITE_BUSY IMMEDIATELY
     * (measured: 0.3 ms with busy_timeout at 4000). The busy handler is
     * deliberately not called, because waiting cannot refresh a stale
     * snapshot - only ending the read can. So neither busy_timeout nor
     * retry() below can do anything about it, and a duel where both peers
     * commit constantly hits it on nearly every request. Every early fetch
     * is followed by closeCursor(); fetchAll() finishes the statement itself.
     *
     * Runs a write through a transient lock. WAL leaves exactly one writer,
     * so two requests writing at the same moment - a relayed duel sends from
     * both ends at once - can still collide after busy_timeout is spent.
     * Losing that write means a dropped signal or a dropped game message,
     * i.e. a broken duel, which is worth a few ms of backoff rather than the
     * 500 the caller would otherwise get. Read paths do not need this: in
     * WAL a reader never blocks and is never blocked.
     */
    public static function retry(callable $fn, int $tries = 3): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $fn();
            } catch (PDOException $e) {
                if ($attempt >= $tries || !self::isLocked($e)) {
                    throw $e;
                }
                // Jittered and growing: two writers that just collided must
                // not line up again on the retry.
                usleep(random_int(2000, 8000) * $attempt);
            }
        }
    }

    // SQLITE_BUSY (5) and SQLITE_LOCKED (6) arrive as driver-specific codes;
    // the SQLSTATE is the generic HY000, so it cannot be matched on.
    private static function isLocked(PDOException $e): bool
    {
        $code = (int)($e->errorInfo[1] ?? 0);
        return $code === 5 || $code === 6;
    }

    /**
     * Versioned migration ladder on SQLite's user_version pragma.
     * Rules: never edit an existing step, only append a new
     * "if ($v < N)" block; each step must be safe on live data.
     */
    private static function migrate(PDO $pdo): void
    {
        $v = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
        if ($v < 1) {
            self::schemaV1($pdo);
            self::seed($pdo);
        }
        if ($v < 2) {
            self::schemaV2($pdo);
        }
        if ($v < 3) {
            $pdo->exec('ALTER TABLE players ADD COLUMN latency INTEGER');
        }
        if ($v < 4) {
            $pdo->exec('CREATE TABLE IF NOT EXISTS starts (
                a TEXT NOT NULL,
                b TEXT NOT NULL,
                start_pts INTEGER NOT NULL,
                created INTEGER NOT NULL,
                PRIMARY KEY (a, b)
            )');
        }
        if ($v < 5) {
            $pdo->exec('ALTER TABLE players ADD COLUMN name TEXT');
            $pdo->exec('CREATE TABLE IF NOT EXISTS friends (
                a TEXT NOT NULL,
                b TEXT NOT NULL,
                state TEXT NOT NULL,
                requester TEXT NOT NULL,
                created INTEGER NOT NULL,
                updated INTEGER NOT NULL,
                PRIMARY KEY (a, b)
            )');
        }
        if ($v < 6) {
            $pdo->exec('CREATE TABLE IF NOT EXISTS relay (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                pair TEXT NOT NULL,
                from_id TEXT NOT NULL,
                to_id TEXT NOT NULL,
                payload TEXT NOT NULL,
                created INTEGER NOT NULL
            )');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_relay_to ON relay (to_id, from_id, id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_relay_pair ON relay (pair, created)');
        }
        if ($v < 7) {
            $pdo->exec('ALTER TABLE players ADD COLUMN accept_until INTEGER NOT NULL DEFAULT 0');
        }
        if ($v < 8) {
            $pdo->exec('ALTER TABLE players ADD COLUMN friend_ban_until INTEGER NOT NULL DEFAULT 0');
        }
        if ($v < 9) {
            $pdo->exec('CREATE TABLE IF NOT EXISTS conn (
                id TEXT PRIMARY KEY,
                peer TEXT,
                state TEXT NOT NULL,
                mode TEXT,
                updated INTEGER NOT NULL
            )');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_conn_mode ON conn (mode, updated)');
        }
        if ($v < 10) {
            // Last REAL traffic through the hub: only that holds a relay
            // slot, a client's claim cannot (see ConnTrack).
            $pdo->exec('ALTER TABLE conn ADD COLUMN relay_seen INTEGER NOT NULL DEFAULT 0');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_conn_relay ON conn (relay_seen)');
        }
        if ($v < 11) {
            // Each of these backed a full scan on a per-heartbeat or
            // per-relayed-message path: cost per request must be flat in
            // the number of players, and it was linear.
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_players_seen ON players (last_seen)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_duels_seen ON duels (last_seen)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_signals_created ON signals (created)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_relay_created ON relay (created)');
            // Cached presence counters, see Presence::counts().
            $pdo->exec('CREATE TABLE IF NOT EXISTS stats (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                online INTEGER NOT NULL,
                playing INTEGER NOT NULL,
                registered INTEGER NOT NULL,
                updated INTEGER NOT NULL
            )');
        }
        if ($v < 12) {
            // Peers now NAME the start they mean (see Starts). Keyed by
            // pair alone, a peer whose request landed after the moment it
            // was asking about silently got a DIFFERENT start; the epoch
            // makes the answer independent of when either peer asks.
            $pdo->exec('ALTER TABLE starts ADD COLUMN epoch INTEGER NOT NULL DEFAULT 0');
            $pdo->exec("ALTER TABLE starts ADD COLUMN reason TEXT NOT NULL DEFAULT 'first'");
            // debug is what the ADMIN asked for, debug_active is what the
            // client REPORTS it is actually doing. They are independent: a
            // client can enter debug mode on its own, and a freshly set
            // flag is not honoured until the client's next hello - seeing
            // both is how the admin knows it landed.
            $pdo->exec('ALTER TABLE players ADD COLUMN debug INTEGER NOT NULL DEFAULT 0');
            $pdo->exec('ALTER TABLE players ADD COLUMN debug_active INTEGER NOT NULL DEFAULT 0');
        }
        if ($v < 13) {
            // Per-client relay send-rate guard (see RelayRate). The relay
            // table is drained on delivery, so the send rate cannot be read
            // off it; this keeps a running total per client plus the mark
            // to diff the per-slice increase against.
            $pdo->exec('CREATE TABLE IF NOT EXISTS relay_rate (
                id TEXT PRIMARY KEY,
                total INTEGER NOT NULL DEFAULT 0,
                mark_total INTEGER NOT NULL DEFAULT 0,
                mark_time INTEGER NOT NULL DEFAULT 0,
                blocked_until INTEGER NOT NULL DEFAULT 0
            )');
        }
        if ($v < 14) {
            // Per-minute load gauges for the admin dashboard (see Load).
            // Self-pruning: only the last few minutes are ever kept.
            $pdo->exec('CREATE TABLE IF NOT EXISTS loadmin (
                bucket TEXT NOT NULL,
                metric TEXT NOT NULL,
                value INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (bucket, metric)
            )');
        }
        if ($v < 15) {
            // Per-player stats backup (see Vault, api/backup.php): one opaque
            // client-defined blob per id, restorable on a new device.
            $pdo->exec('CREATE TABLE IF NOT EXISTS vault (
                id TEXT PRIMARY KEY,
                payload TEXT NOT NULL,
                updated INTEGER NOT NULL
            )');
        }
        if ($v < 16) {
            // Secret token (SHA-256 hash) that binds a backup to its owner:
            // minted on the first backup, required for restore and overwrite
            // (see Vault). Empty for any pre-token row, which the next backup
            // re-secures.
            $pdo->exec("ALTER TABLE vault ADD COLUMN token_hash TEXT NOT NULL DEFAULT ''");
        }
        if ($v < 17) {
            // Debug datasets (see Debug, debug/submit.php): a log/snapshot
            // bundle under a 4-digit pin, pruned after FOK_DEBUG_TTL.
            $pdo->exec('CREATE TABLE IF NOT EXISTS debug (
                pin TEXT PRIMARY KEY,
                payload TEXT NOT NULL,
                bytes INTEGER NOT NULL,
                created INTEGER NOT NULL
            )');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_debug_created ON debug (created)');
        }
        if ($v < 18) {
            // Host capability assessment (see Caps): probed once per release
            // and read from here afterwards, so no request pays for it.
            $pdo->exec('CREATE TABLE IF NOT EXISTS caps (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                version TEXT NOT NULL,
                checked INTEGER NOT NULL,
                data TEXT NOT NULL
            )');
        }
        // Only ever written when a step actually ran: this is a WRITE, and
        // every request goes through here - including the long polls that
        // must not touch the single SQLite writer while they idle.
        if ($v < self::SCHEMA_VERSION) {
            $pdo->exec('PRAGMA user_version = ' . self::SCHEMA_VERSION);
        }
    }

    // A database commissioned from scratch starts with the same default
    // entry the FOK-snake local top 10 ships with, but with 82 points.
    private static function seed(PDO $pdo): void
    {
        if ((int)$pdo->query('SELECT COUNT(*) FROM scores')->fetchColumn() > 0) {
            return;
        }
        $pdo->prepare(
            'INSERT INTO scores (player_id, name, score, level, diff, color, shop_items, validated, created)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)'
        )->execute(['00000000', 'SNAKE PLISSKEN', 82, 1, 1, 0, '{}', gmmktime(0, 0, 0, 11, 26, 1997)]);
    }

    private static function schemaV2(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value INTEGER NOT NULL
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS alerts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            type TEXT NOT NULL,
            message TEXT NOT NULL,
            created INTEGER NOT NULL,
            seen INTEGER NOT NULL DEFAULT 0
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS ipcount (
            ip TEXT NOT NULL,
            bucket TEXT NOT NULL,
            value INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (ip, bucket)
        )');
    }

    private static function schemaV1(PDO $pdo): void
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
