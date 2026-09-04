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
        'score_rate_max' => [FOK_SCORE_RATE_MAX, 'Max score submissions per window'],
        'score_rate_window' => [FOK_SCORE_RATE_WINDOW, 'Score submission window (seconds)'],
        'chat_max_len' => [FOK_CHAT_MAX_LEN, 'Max chat message bytes'],
        'start_lead_min_ms' => [200, 'Min lead time for server-issued level starts (ms)'],
        'start_sync_max_age_ms' => [2000, 'Reject a start whose sync proof is older than (ms)'],
        // DEPRECATED: relay fallback (see docs/DEPRECATED-relay.md). These
        // seven relay_* settings are removed with the feature.
        'relay_max_duels' => [9, 'Max concurrent relayed duels (protects FPM workers)'],
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
        'player_ttl_days' => [180, 'Remove players not seen for N days (0 = never)'],
        'alert_req_per_min' => [600, 'Alert: total requests per minute above'],
        'alert_load_per_core' => [2, 'Alert: 1-minute load per CPU core above'],
        'alert_online' => [200, 'Alert: concurrent online players above'],
        'alert_invalid_per_min' => [30, 'Alert: invalid requests per IP per minute above'],
        'alert_cooldown' => [900, 'Alert de-duplication window (seconds)'],
        'load_sample' => [10, 'Load-gauge write sampling (1 = exact, every request)'],
        'relay_apcu' => [1, 'Relay via APCu shared memory when usable (the default; 0 = force the database)'],
        // Item registry (see Items, Ledger). match_open_max_ms is generous on
        // purpose: the server never learns a match ended, so a late but honest
        // claim must still land. claim_grace_ms is how long an unconfirmed gain
        // claim waits for the peer's tag before it settles. The ledger keeps
        // itself bounded by checkpointing above ledger_max_rows, checked on a
        // sampled one-in-ledger_sample fraction of requests. mint_max_per_hour
        // caps client-driven minting (still client-trusted; see docs/API.md).
        'match_open_max_ms' => [7200000, 'How long a match keeps accepting item claims (ms)'],
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
        'admin_stats_refresh_secs' => [1, 'Statistics card refresh interval (seconds, 0 = off)'],
    ];

    private static ?array $cache = null;

    public static function int(string $key): int
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (Db::get()->query('SELECT key, value FROM settings') as $row) {
                self::$cache[$row['key']] = (int)$row['value'];
            }
        }
        return self::$cache[$key] ?? self::DEFS[$key][0];
    }

    public static function set(string $key, int $value): void
    {
        if (!isset(self::DEFS[$key])) {
            throw new InvalidArgumentException("unknown setting $key");
        }
        Db::get()->prepare(
            'INSERT INTO settings (key, value) VALUES (?, ?)
             ON CONFLICT (key) DO UPDATE SET value = excluded.value'
        )->execute([$key, $value]);
        self::$cache = null;
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
