<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';

/**
 * Admin-configurable integer settings, stored in the database with the
 * Config constants as defaults. Everything listed in DEFS shows up in
 * the admin config card automatically.
 */
final class Settings
{
    /** @var array<string, array{0:int, 1:string}> key => [default, label] */
    public const DEFS = [
        'admin_max_fails' => [FOK_ADMIN_MAX_FAILS, 'Block admin IP after N failed logins'],
        'admin_lock_seconds' => [FOK_ADMIN_LOCK_SECONDS, 'Admin IP block duration (seconds)'],
        'mailbox_cap' => [FOK_MAILBOX_CAP, 'Max pending signals per recipient'],
        'signal_ttl' => [FOK_SIGNAL_TTL, 'Undelivered signal lifetime (seconds)'],
        // The pool-wide ceiling on held long polls (see Holds). It sits above
        // the per-feature caps below rather than beside them: those bound one
        // relay or one tournament, this bounds what all of them together may
        // take from a worker pool that has to answer everything else too.
        'hold_max_workers' => [FOK_HOLD_MAX_WORKERS, 'Max FPM workers held by long polls at once (0 = unlimited)'],
        'score_rate_max' => [FOK_SCORE_RATE_MAX, 'Max score submissions per window'],
        'score_rate_window' => [FOK_SCORE_RATE_WINDOW, 'Score submission window (seconds)'],
        'chat_max_len' => [FOK_CHAT_MAX_LEN, 'Max chat message bytes'],
        'start_lead_min_ms' => [200, 'Min lead time for server-issued level starts (ms)'],
        'start_sync_max_age_ms' => [2000, 'Reject a start whose sync proof is older than (ms)'],
        // The pair cross-check (see Skew). A tolerance, not a gate: past it
        // the pair is ASKED to re-anchor, never refused. 0 turns it off.
        'start_pair_skew_ms' => [FOK_START_PAIR_SKEW_MS, 'Ask a pair to resync when their two clock proofs disagree by more than (ms, 0 = off)'],
        // Client pacing (see Pace). hold_max_workers above is the budget these
        // are measured against: pacing is what the server says BEFORE that
        // budget runs out, and Holds is what happens when it does.
        'pace_hello_ms' => [FOK_PACE_HELLO_MS, 'Heartbeat interval handed to an unpressured client (ms)'],
        'pace_hello_max_ms' => [FOK_PACE_HELLO_MAX_MS, 'Longest heartbeat interval pacing may ask for (ms)'],
        'pace_gap_ms' => [FOK_PACE_GAP_MS, 'Minimum spacing asked between any two requests one client has in flight (ms, 0 = off)'],
        // The same idea one step out: gap_ms spaces the requests a client
        // decides to make, this spaces the ones a broadcast provokes from
        // everyone at once (after_ms, see Tournament::flush).
        'tourney_after_ms' => [FOK_TOURNEY_AFTER_MS, 'Budget a pushed event staggers its follow-up calls over (ms, 0 = off)'],
        'ices_max' => [FOK_ICES_MAX, 'Max ICE candidates in one batched signal'],
        // DEPRECATED: relay fallback (see docs/DEPRECATED-relay.md). These
        // seven relay_* settings are removed with the feature.
        'relay_max_duels' => [4, 'Max concurrent relayed duels (protects FPM workers)'],
        'relay_max_payload' => [2048, 'Max relayed message bytes'],
        'relay_pending_cap' => [128, 'Max undelivered relay messages per receiver'],
        'relay_ttl' => [30, 'Undelivered relay message lifetime (seconds)'],
        'relay_rate_max' => [128, 'Max relay messages per second per client (sustained)'],
        'relay_rate_block_secs' => [30, 'Relay rate-limit block duration (seconds)'],
        'friend_req_max' => [15, 'Ban: unanswered friend requests per hour above'],
        'friend_ban_seconds' => [3600, 'Friend-request ban duration (seconds)'],
        'friend_rate_interval' => [1, 'Min seconds between friend requests per id'],
        'friend_rate_burst' => [10, 'Friend requests in a row before a cooldown'],
        'friend_rate_cooldown' => [60, 'Friend-request cooldown after a burst (seconds)'],
        'friend_rate_repeat_window' => [600, 'Re-offense window: a second burst within this escalates the cooldown (seconds)'],
        'friend_rate_cooldown_hard' => [3600, 'Escalated friend-request cooldown after a repeat burst (seconds)'],
        // Housekeeping (see Housekeeping::sweep, run hourly). All three are
        // in DAYS on purpose: every reader of these rows works in seconds or
        // minutes, so no value an operator can enter here comes close to one
        // of those windows. player_ttl_days removes the player (and their
        // friendships, not their property); duel_ttl_days the pair row a
        // finished duel leaves behind; alert_ttl_days the alerts that have
        // been read.
        'player_ttl_days' => [180, 'Remove players not seen for N days (0 = never)'],
        'duel_ttl_days' => [7, 'Forget a duel pair not seen for N days (0 = never)'],
        'alert_req_per_min' => [600, 'Alert: total requests per minute above'],
        'alert_load_per_core' => [2, 'Alert: 1-minute load per CPU core above'],
        'alert_online' => [200, 'Alert: concurrent online players above'],
        'alert_invalid_per_min' => [30, 'Alert: invalid requests per IP per minute above'],
        'alert_cooldown' => [900, 'Alert de-duplication window (seconds)'],
        'alert_ttl_days' => [30, 'Remove alerts that have been read after N days (0 = never)'],
        // Item registry (see Items, Ledger). match_open_max_ms is the grace a
        // claim gets AFTER its duel stops reporting in - the window while the
        // duel is running is FOK_DUEL_WINDOW and is not part of it (see
        // Items::matchDeadline). Short on purpose: it is also how long one
        // side can move the other's items unwitnessed while the other is
        // offline to contradict it. claim_grace_ms is how long an unconfirmed
        // gain claim waits for the peer's tag before it settles. The ledger
        // keeps itself bounded by checkpointing above ledger_max_rows, checked
        // on a sampled one-in-ledger_sample fraction of requests.
        // mint_max_per_hour caps client-driven minting (still client-trusted;
        // see docs/API.md).
        'match_open_max_ms' => [60000, 'Grace a claim gets after its duel goes quiet (ms)'],
        'claim_grace_ms' => [60000, 'Unconfirmed gain claim waits this long for the peer tag before settling (ms)'],
        'ledger_max_rows' => [200000, 'Checkpoint and truncate the item ledger above this many rows'],
        'ledger_sample' => [200, 'One request in N may run the item-ledger truncation check'],
        'mint_max_per_hour' => [60, 'Max client-driven item mints per player per hour'],
        // Tournament mode (see Tournament, api/tournament.php). The two _ms
        // deadlines are the whole of the "what if nobody answers" story, and
        // both are evaluated lazily on the next touch - there is no timer.
        // result_ms is short: it only has to outlast the losing client's own
        // report of a match that just ended. walkover_ms is long, and only
        // ever fires against a player who is also OFFLINE, so a slow match
        // between two present players is never taken away from them.
        'tournament_max_players' => [FOK_TOURNAMENT_MAX_PLAYERS, 'Max players in one tournament'],
        'tournament_join_ttl' => [900, 'Abandon a lobby nobody started after (seconds)'],
        'tournament_run_ttl' => [3600, 'Forget a running tournament untouched for (seconds)'],
        'tournament_done_ttl' => [300, 'Forget a finished or abandoned tournament after (seconds)'],
        'tournament_result_ms' => [15000, 'A one-sided result settles after this long unanswered (ms)'],
        'tournament_walkover_ms' => [180000, 'An offline player forfeits the match in flight after (ms)'],
        'tournament_create_cooldown' => [10, 'Min seconds between one host creating tournaments'],
        'tournament_announce_window' => [180, 'Announce a lobby while its host was seen within (seconds)'],
        // The round ladder. The level a round is played at is its round
        // number, so a wider field reaches a deeper final; the cap is the
        // game's own last level. The break between two rounds is where the
        // scoreboard is read, so it has a floor (a continue that arrives
        // before anyone could have read it is a stray tap) and a ceiling (a
        // host that closed its browser must not wedge the tournament).
        'tournament_max_level' => [FOK_TOURNAMENT_MAX_LEVEL, 'Deepest level a tournament round is played at'],
        'tournament_break_ms' => [1000, 'Min time a round-break scoreboard stays up before continue (ms)'],
        'tournament_break_ttl_ms' => [120000, 'A round break continues by itself after (ms)'],
        'admin_refresh_secs' => [30, 'Admin dashboard refresh interval (seconds, 0 = off)'],
        'admin_conns_refresh_secs' => [1, 'Connections card refresh interval (seconds, 0 = off)'],
        'admin_duels_refresh_secs' => [1, 'Duels card refresh interval (seconds, 0 = off)'],
        'admin_perf_refresh_secs' => [5, 'Server performance card refresh interval (seconds, 0 = off)'],
        'admin_stats_refresh_secs' => [5, 'Statistics card refresh interval (seconds, 0 = off)'],
    ];

    // Only the rows that OVERRIDE a default are stored and cached; a key at
    // its default has no row, and DEFS answers for it.
    private const CACHE_KEY = FOK_APCU_NS . 'cfg';
    // A safety net, not the invalidation: set() drops the entry. This only
    // bounds how long a cache could outlive a row written by something that
    // never went through set() (a restored backup that also lost the drop).
    private const CACHE_TTL = 600;

    private static ?array $cache = null;

    public static function int(string $key): int
    {
        if (self::$cache === null) {
            self::$cache = self::load();
        }
        return self::$cache[$key] ?? self::DEFS[$key][0];
    }

    /**
     * The overrides table. Every request reads settings, most of them read
     * nothing else, so this is the query that decided whether a long poll
     * had to open the database at all - hence shared memory in front of it.
     *
     * @return array<string, int>
     */
    private static function load(): array
    {
        $apcu = self::apcu();
        if ($apcu) {
            $hit = apcu_fetch(self::CACHE_KEY);
            if (is_array($hit)) {
                return $hit;
            }
        }
        $rows = [];
        foreach (Db::get()->query('SELECT key, value FROM settings') as $row) {
            $rows[$row['key']] = (int)$row['value'];
        }
        if ($apcu) {
            apcu_store(self::CACHE_KEY, $rows, self::CACHE_TTL);
        }
        return $rows;
    }

    public static function set(string $key, int $value): void
    {
        if (!isset(self::DEFS[$key])) {
            throw new InvalidArgumentException("unknown setting $key");
        }
        // Row first, then the cache: a worker that reads between the two
        // gets the new value, never a cached old one over a written row.
        Db::retry(static function () use ($key, $value): void {
            Db::get()->prepare(
                'INSERT INTO settings (key, value) VALUES (?, ?)
                 ON CONFLICT (key) DO UPDATE SET value = excluded.value'
            )->execute([$key, $value]);
        });
        self::forget();
    }

    /** Drop the caches; the next read re-loads from the table. */
    public static function forget(): void
    {
        if (self::apcu()) {
            apcu_delete(self::CACHE_KEY);
        }
        self::$cache = null;
    }

    // Deliberately not Caps::apcu(): answering that opens the database, and
    // keeping requests off the database is the whole point of this cache.
    private static function apcu(): bool
    {
        return function_exists('apcu_fetch') && apcu_enabled();
    }

    /** @return array<int, array{key:string, value:int, default:int, label:string}> */
    public static function all(): array
    {
        $out = [];
        foreach (self::DEFS as $key => [$default, $label]) {
            $out[] = [
                'key' => $key,
                'value' => self::int($key),
                'default' => $default,
                'label' => $label,
            ];
        }
        return $out;
    }
}
