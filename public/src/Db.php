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
    private const SCHEMA_VERSION = 30;

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
        if ($v < 19) {
            // Per-player self-reported gameplay stats (see PStats,
            // api/stats.php): one cumulative-counter row per id, stored
            // monotonically. Kept apart from the opaque config backup (vault),
            // which is never parsed - these are PARSED, so they are queryable
            // and shown on the admin dashboard.
            $pdo->exec('CREATE TABLE IF NOT EXISTS pstats (
                id TEXT PRIMARY KEY,
                games INTEGER NOT NULL DEFAULT 0,
                levels INTEGER NOT NULL DEFAULT 0,
                best_level INTEGER NOT NULL DEFAULT 0,
                deaths INTEGER NOT NULL DEFAULT 0,
                duels INTEGER NOT NULL DEFAULT 0,
                duels_won INTEGER NOT NULL DEFAULT 0,
                play_seconds INTEGER NOT NULL DEFAULT 0,
                updated INTEGER NOT NULL DEFAULT 0
            )');
        }
        if ($v < 20) {
            // A score entry can mark that the run COMPLETED the game - cleared
            // the FINAL level - as opposed to merely reaching a level and dying
            // (see api/scores.php). The client asserts it: the server does not
            // know how many levels the game has, so it cannot derive completion
            // from the level number. Default 0 = not completed, for every
            // existing row and any submission that omits the flag.
            $pdo->exec('ALTER TABLE scores ADD COLUMN completed INTEGER NOT NULL DEFAULT 0');
        }
        if ($v < 21) {
            // A score may optionally record the device category it was played
            // on - pc, mobile, tv, console (see FOK_SCORE_PLATFORMS,
            // api/scores.php). Nullable: NULL means the client reported none.
            // The API contract stays 3.4 - the field is additive and optional.
            $pdo->exec('ALTER TABLE scores ADD COLUMN platform TEXT');
        }
        if ($v < 22) {
            // Per-id friend-request throttle state (see Friends::rateHit):
            // the last request time, the consecutive-request streak, and the
            // end of a burst cooldown. Kept on the player row next to
            // friend_ban_until, the heavier spam guard it complements.
            $pdo->exec('ALTER TABLE players ADD COLUMN friend_req_last INTEGER NOT NULL DEFAULT 0');
            $pdo->exec('ALTER TABLE players ADD COLUMN friend_req_streak INTEGER NOT NULL DEFAULT 0');
            $pdo->exec('ALTER TABLE players ADD COLUMN friend_req_cooldown_until INTEGER NOT NULL DEFAULT 0');
        }
        if ($v < 23) {
            // When the last burst cooldown was tripped (see Friends::rateHit).
            // A second burst within friend_rate_repeat_window of this marks a
            // persistent abuser and escalates from the short cooldown to the
            // long one. It outlives friend_req_streak, which resets after one
            // idle cooldown, so the window can straddle the first cooldown.
            $pdo->exec('ALTER TABLE players ADD COLUMN friend_req_last_trip INTEGER NOT NULL DEFAULT 0');
        }
        if ($v < 24) {
            // Server-authoritative item ownership (see Items, Ledger,
            // api/items.php). An item instance is ONE row here, so a
            // restored config backup can no longer grant it - ownership is
            // this row, not a client boolean. PK lookup, never a scan.
            $pdo->exec('CREATE TABLE IF NOT EXISTS items (
                uid TEXT PRIMARY KEY,
                item_id TEXT NOT NULL,
                owner TEXT NOT NULL,
                seq INTEGER NOT NULL DEFAULT 0,
                origin TEXT NOT NULL,
                minted INTEGER NOT NULL,
                frozen INTEGER NOT NULL DEFAULT 0
            )');
            $pdo->exec('CREATE INDEX IF NOT EXISTS items_owner ON items (owner)');
            // One match per duel connection, minted where play BEGINS (see
            // Starts). Per-client MAC secrets are stored in the clear - the
            // server recomputes tags with them - and NEVER exposed. A claim
            // binds to a match by its mid.
            $pdo->exec('CREATE TABLE IF NOT EXISTS matches (
                mid TEXT PRIMARY KEY,
                a TEXT NOT NULL,
                b TEXT NOT NULL,
                opened INTEGER NOT NULL,
                closed INTEGER NOT NULL DEFAULT 0,
                sec_a TEXT NOT NULL,
                sec_b TEXT NOT NULL
            )');
            $pdo->exec('CREATE INDEX IF NOT EXISTS matches_pair ON matches (a, b, opened)');
            // Append-only, hash-chained transfer ledger (see Ledger). Never
            // read to decide ownership - that is items - only for audit and
            // tamper-evidence. Truncatable via checkpoints, so it does not
            // grow without bound.
            $pdo->exec("CREATE TABLE IF NOT EXISTS ledger (
                n INTEGER PRIMARY KEY AUTOINCREMENT,
                kind TEXT NOT NULL,
                uid TEXT NOT NULL,
                from_id TEXT NOT NULL DEFAULT '',
                to_id TEXT NOT NULL DEFAULT '',
                mid TEXT NOT NULL DEFAULT '',
                tick INTEGER NOT NULL DEFAULT 0,
                at INTEGER NOT NULL,
                prev_hash TEXT NOT NULL,
                hash TEXT NOT NULL
            )");
            $pdo->exec('CREATE INDEX IF NOT EXISTS ledger_uid ON ledger (uid)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS ledger_match ON ledger (mid, uid, tick)');
            // Per-player claim tallies for statistical review (see the admin
            // items card). Bounded - three counters and a one-shot flag, no
            // growth. items_seeded guards the one-time legacy grandfathering.
            $pdo->exec('ALTER TABLE players ADD COLUMN claims_ok INTEGER NOT NULL DEFAULT 0');
            $pdo->exec('ALTER TABLE players ADD COLUMN claims_untagged INTEGER NOT NULL DEFAULT 0');
            $pdo->exec('ALTER TABLE players ADD COLUMN claims_disputed INTEGER NOT NULL DEFAULT 0');
            $pdo->exec('ALTER TABLE players ADD COLUMN items_seeded INTEGER NOT NULL DEFAULT 0');
            // The pair's current open match hangs off the start row, so match
            // minting inherits the same epoch idempotence the start does: the
            // first peer of a begin mints and stamps it here, the second reads
            // it back, and an in-run halt carries it forward (see Starts).
            $pdo->exec("ALTER TABLE starts ADD COLUMN mid TEXT NOT NULL DEFAULT ''");
        }
        if ($v < 25) {
            // Tournament mode (see Tournament, Bracket, api/tournament.php).
            // ONE row per tournament, and everything that changes during it -
            // seats, schedule, results, standings, bracket, cursor - lives in
            // the `data` JSON blob rather than in rows of its own. It is read
            // and written whole, only ever by the tournament's own
            // transitions (a few per minute), and it is never queried BY its
            // contents; splitting it into tables would buy indexes nothing
            // reads and cost a multi-statement write where one suffices.
            $pdo->exec("CREATE TABLE IF NOT EXISTS tournaments (
                tid TEXT PRIMARY KEY,
                host TEXT NOT NULL,
                code TEXT NOT NULL,
                state TEXT NOT NULL DEFAULT 'open',
                round INTEGER NOT NULL DEFAULT 0,
                seed TEXT NOT NULL,
                stakes INTEGER NOT NULL DEFAULT 0,
                data TEXT NOT NULL DEFAULT '{}',
                created INTEGER NOT NULL,
                updated INTEGER NOT NULL
            )");
            // The lazy lobby reaping sweeps by (state, updated), and the
            // one-per-host and cooldown guards look up by (host, created).
            // Both run on the hot lobby path, so neither may be a scan.
            $pdo->exec('CREATE INDEX IF NOT EXISTS tournaments_state ON tournaments (state, updated)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS tournaments_host ON tournaments (host, created)');
            // Participation is a real row rather than another JSON list: it
            // is the one part a request writes WITHOUT a full transition (a
            // join or a leave in the lobby touches nothing else), and the one
            // part that has to be countable without reading the blob.
            $pdo->exec('CREATE TABLE IF NOT EXISTS tournament_players (
                tid TEXT NOT NULL,
                id TEXT NOT NULL,
                seat INTEGER NOT NULL DEFAULT -1,
                forfeited INTEGER NOT NULL DEFAULT 0,
                joined INTEGER NOT NULL,
                PRIMARY KEY (tid, id)
            )');
            // hello announces open lobbies hosted on the CALLER's address, so
            // that lookup is by (ip, last_seen) - a scan of every player row
            // on every hello otherwise.
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_players_ip_seen ON players (ip, last_seen)');
        }
        if ($v < 26) {
            // alert_load1 (an absolute load) became alert_load_per_core. A
            // stored row for the old key is read by nothing, and left behind
            // it would still be a saved value on a dashboard that no longer
            // shows it. Dropping it also means the new key starts with no row
            // at all, so every deployment picks up the new default.
            $pdo->exec("DELETE FROM settings WHERE key = 'alert_load1'");
        }
        if ($v < 27) {
            // Lobbies are announced to the caller's NETWORK rather than to
            // its exact address (see Util::ipNet): on IPv6 the host and the
            // joiner sitting on one LAN never share an address, so the old
            // lookup could not match them. Stored beside the ip because it
            // is what the lookup compares, and indexed for the same reason
            // idx_players_ip_seen was - which nothing reads any more.
            //
            // No backfill: announce only ever considers hosts seen within
            // the last FOK_ONLINE_WINDOW seconds, and every one of those has
            // been through touch(), which fills the column.
            $pdo->exec("ALTER TABLE players ADD COLUMN ipnet TEXT NOT NULL DEFAULT ''");
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_players_net_seen ON players (ipnet, last_seen)');
            $pdo->exec('DROP INDEX IF EXISTS idx_players_ip_seen');
            // tournament_create_cooldown drops from 300s to 10s, and the
            // cooldown is charged off the host's last create whatever became
            // of it - so backing out of a lobby and opening another one, the
            // ordinary thing to do, cost five minutes. A stored row would
            // shadow the new default in silence (settings_save writes every
            // key the card shows), so the row goes and every deployment
            // picks the default up.
            $pdo->exec("DELETE FROM settings WHERE key = 'tournament_create_cooldown'");
        }
        if ($v < 28) {
            // A dual-stack client reaches us over whichever family its
            // browser picked for that connection, and the two devices in one
            // room do not have to pick the same one - so a single ipnet on
            // the player row describes the caller, not the LINE the caller is
            // on, and the announce missed exactly the pair it exists for.
            // One row per family instead: at most a v4 and a v6 network per
            // player, each overwritten in place when the player moves, so the
            // table stays two rows per player and needs no reaping of its
            // own. Indexed by (net, seen) because the announce asks "who is
            // on one of MY networks", the same lookup idx_players_net_seen
            // served for one family.
            $pdo->exec('CREATE TABLE IF NOT EXISTS player_nets (
                id     TEXT    NOT NULL,
                family INTEGER NOT NULL,
                net    TEXT    NOT NULL,
                seen   INTEGER NOT NULL,
                PRIMARY KEY (id, family)
            )');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_player_nets_net ON player_nets (net, seen)');
        }
        if ($v < 29) {
            // Where a network came from. 'o' is a network the server SAW
            // this player arrive on; 'c' is one the client told us about
            // (see Presence::seenOn). A client can only ever learn its own
            // other-family address by asking a STUN server, so the second
            // family is a CLAIM and not evidence - it has to be marked as
            // such, because a claim may not displace what we saw ourselves.
            $pdo->exec("ALTER TABLE player_nets ADD COLUMN src TEXT NOT NULL DEFAULT 'o'");
        }
        if ($v < 30) {
            // Tournament state moved to APCu shared memory (see TourneyStore).
            // Nothing outside Tournament ever read these tables, no score,
            // item or ledger row is derived from them, and a finished
            // tournament was never looked at again - so there is nothing to
            // migrate, only a durable structure to stop paying for. The few
            // totals worth keeping are counted from here on (see Stats).
            $pdo->exec('DROP TABLE IF EXISTS tournament_players');
            $pdo->exec('DROP TABLE IF EXISTS tournaments');
            // The reap that ran off this marker is gone with them: an open
            // lobby now expires on its own TTL.
            $pdo->exec("DELETE FROM counters WHERE bucket = 'meta' AND metric = 'tourney_sweep'");
        }
        // Only ever written when a step actually ran: this is a WRITE, and
        // every request goes through here - including the long polls that
        // must not touch the single SQLite writer while they idle.
        if ($v < self::SCHEMA_VERSION) {
            $pdo->exec('PRAGMA user_version = ' . self::SCHEMA_VERSION);
            // The one trace a deploy leaves in the log: which request found an
            // old database and migrated it. Plain error_log, not Alerts - that
            // would require Db back into Db while it is still opening.
            error_log("FOK schema: migrated $v -> " . self::SCHEMA_VERSION);
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
