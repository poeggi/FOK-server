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
        'poll_wait_max' => [8, 'Max long-poll hold (seconds)'],
        'start_lead_min_ms' => [200, 'Min lead time for server-issued level starts (ms)'],
        'start_sync_max_age_ms' => [2000, 'Reject a start whose sync proof is older than (ms)'],
        'relay_max_duels' => [3, 'Max concurrent relayed duels (protects FPM workers)'],
        'relay_max_payload' => [2048, 'Max relayed message bytes'],
        'relay_pending_cap' => [128, 'Max undelivered relay messages per receiver'],
        'relay_ttl' => [30, 'Undelivered relay message lifetime (seconds)'],
        'relay_rate_max' => [128, 'Max relay messages per second per client (sustained)'],
        'relay_rate_block_secs' => [30, 'Relay rate-limit block duration (seconds)'],
        'auto_accept_window' => [60, 'Auto-accept flag validity after a hello (seconds)'],
        'friend_req_max' => [15, 'Ban: unanswered friend requests per hour above'],
        'friend_ban_seconds' => [3600, 'Friend-request ban duration (seconds)'],
        'player_ttl_days' => [180, 'Remove players not seen for N days (0 = never)'],
        'alert_req_per_min' => [600, 'Alert: total requests per minute above'],
        'alert_load1' => [8, 'Alert: 1-minute system load above'],
        'alert_online' => [200, 'Alert: concurrent online players above'],
        'alert_invalid_per_min' => [30, 'Alert: invalid requests per IP per minute above'],
        'alert_cooldown' => [900, 'Alert de-duplication window (seconds)'],
        'admin_refresh_secs' => [30, 'Admin dashboard refresh interval (seconds, 0 = off)'],
        'admin_conns_refresh_secs' => [1, 'Connections card refresh interval (seconds, 0 = off)'],
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
