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
    /**
     * key => [default, label, help]. The label is the one line the config
     * card shows; the help is what the operator gets on hovering it: what the
     * number bounds and what happens at the bound.
     *
     * @var array<string, array{0:int, 1:string, 2:string}>
     */
    public const DEFS = [
        'admin_max_fails' => [FOK_ADMIN_MAX_FAILS, 'Block admin IP after N failed logins',
            'Failed admin logins from one IP before that IP is locked out. A successful login clears the count.'],
        'admin_lock_seconds' => [FOK_ADMIN_LOCK_SECONDS, 'Admin IP block duration (seconds)',
            'How long a locked-out IP is refused the admin login.'],
        'mailbox_cap' => [FOK_MAILBOX_CAP, 'Max pending signals per recipient',
            'Undelivered signals one recipient\'s mailbox holds. A signal beyond it is refused with 429 and the '
            . 'spam alert names the sender.'],
        'signal_ttl' => [FOK_SIGNAL_TTL, 'Undelivered signal lifetime (seconds)',
            'How long an undelivered signal waits in the mailbox before it is swept, plus the one-second heartbeat '
            . 'grace. It equals the online window: a signal lives as long as its recipient counts as online.'],
        // The pool-wide ceiling on held long polls (see Holds). It sits above
        // the per-feature caps below rather than beside them: those bound one
        // relay or one tournament, this bounds what all of them together may
        // take from a worker pool that has to answer everything else too.
        'hold_max_workers' => [FOK_HOLD_MAX_WORKERS, 'Max FPM workers held by long polls at once (0 = unlimited)',
            'How many FPM workers all long polls together (poll.php, the relay GET) may occupy at once. A poll over '
            . 'the budget answers at once instead of waiting and the client polls again. From half spent, hello '
            . 'withdraws the hold from idle clients first (pace); all spent raises the overload alert. The host '
            . 'has about 20 workers for everything. 0 = no budget.'],
        'score_rate_max' => [FOK_SCORE_RATE_MAX, 'Max score submissions per window',
            'Scores one player may submit inside score_rate_window. The next is refused with 429 and the spam '
            . 'alert is raised.'],
        'score_rate_window' => [FOK_SCORE_RATE_WINDOW, 'Score submission window (seconds)',
            'The window score_rate_max counts over.'],
        'start_lead_ms' => [1000, 'Lead time for server-issued starts (ms)',
            'How far ahead of the moment it is minted a server-issued start is set, one flat figure for every '
            . 'pair. A second clears any round trip a playable connection has.'],
        'start_sync_max_age_ms' => [1000, 'Reject a start whose sync proof is older than (ms)',
            'A begin-play start whose clock reading (pts) is older than this is refused with 400 and logged as an '
            . 'error: the client has to re-sync its clock before it plays.'],
        // The other side of the same gate, and the one that is never refused:
        // what reaches the server is pts + one-way delay, so the trip already
        // pays for a clock that is slightly fast, and a reading that still
        // arrives ahead is an anchor off by more than the trip. Half of this
        // is a warning in the log, the whole of it an error and an alert.
        'pts_ahead_max_ms' => [200, 'Log a client event further ahead of the server than (ms)',
            'A client clock reading further ahead of the server than this is refused with 400, logged as an error '
            . 'and raises the bogus alert. Further than half of it is logged as a warning.'],
        // The client's own request gap (docs/API.md, Pacing) spaces the
        // requests a client decides to make; this spaces the ones a broadcast
        // provokes from everyone at once (after_ms, see Tournament::flush).
        'tourney_after_step_ms' => [FOK_TOURNEY_AFTER_STEP_MS, 'Stagger between seats for the follow-up calls a pushed event provokes (ms per seat, 0 = off)',
            'When a tournament event is pushed to every seat at once, each seat is told to wait this much longer '
            . 'than the seat before it before its follow-up call, so those calls arrive spread out. The client '
            . 'caps it at 1000 ms. 0 = all at once.'],
        'ices_max' => [FOK_ICES_MAX, 'Max ICE candidates in one batched signal',
            'Most ICE candidates one batched ices signal may carry; a larger batch is rejected as invalid.'],
        // DEPRECATED: relay fallback (see docs/DEPRECATED-relay.md). These
        // seven relay_* settings are removed with the feature.
        'relay_max_duels' => [4, 'Max concurrent relayed duels (protects FPM workers)',
            'Relayed duels the server carries at once (deprecated relay). A relay handshake beyond it is refused '
            . 'with 503 and the relay alert is raised. A relayed duel can hold two workers.'],
        'relay_max_payload' => [2048, 'Max relayed message bytes',
            'Largest relayed message in bytes; a larger one is rejected as invalid. 2048 fits a base64 packet of '
            . '1280 binary bytes.'],
        'relay_pending_cap' => [128, 'Max undelivered relay messages per receiver',
            'Undelivered relay messages queued for one receiver. A message beyond it is refused with 429 (the '
            . 'sender resends) and the spam alert is raised.'],
        'relay_ttl' => [30, 'Undelivered relay message lifetime (seconds)',
            'How long an undelivered relay message is kept before it is dropped.'],
        'relay_rate_max' => [128, 'Max relay messages per second per client (sustained)',
            'Sustained relay messages per second one client may send. Above it the client is blocked for '
            . 'relay_rate_block_secs.'],
        'relay_rate_block_secs' => [30, 'Relay rate-limit block duration (seconds)',
            'How long a client over relay_rate_max is refused with 429.'],
        'friend_req_max' => [15, 'Ban: unanswered friend requests per hour above',
            'Unanswered friend requests one player may have sent in the last hour. Above it the player is banned '
            . 'from requesting for friend_ban_seconds and its pending requests are dropped.'],
        'friend_ban_seconds' => [3600, 'Friend-request ban duration (seconds)',
            'How long a player over friend_req_max is refused friend requests.'],
        'friend_rate_interval' => [1, 'Min seconds between friend requests per id',
            'Least seconds between two friend requests from one player. A faster one is refused, told to retry '
            . 'after the interval, and still counts towards the burst.'],
        'friend_rate_burst' => [10, 'Friend requests in a row before a cooldown',
            'Friend requests in a row, with no pause of a full cooldown between them, before the player is put on '
            . 'cooldown.'],
        'friend_rate_cooldown' => [60, 'Friend-request cooldown after a burst (seconds)',
            'How long friend requests from a player are refused after a burst. An idle gap this long also starts '
            . 'the burst count over.'],
        'friend_rate_repeat_window' => [600, 'Re-offense window: a second burst within this escalates the cooldown (seconds)',
            'A burst tripped again within this many seconds of the last trip earns friend_rate_cooldown_hard '
            . 'instead of the short cooldown.'],
        'friend_rate_cooldown_hard' => [3600, 'Escalated friend-request cooldown after a repeat burst (seconds)',
            'The escalated cooldown after a repeat burst; raises the friend-cooldown-hard alert. A client '
            . 're-syncing a long friends list after a config restore trips it too, so a named player in that '
            . 'alert is not necessarily a prober.'],
        'friends_delta_max' => [64, 'Max friend-presence rows per response (a stamp tie is never split)',
            'Most friend-presence rows one hello or poll answer carries; the rest wait for the next cursor and '
            . 'friends_more says so. Rows sharing the last stamp travel together, never split across two answers.'],
        // Housekeeping (see Housekeeping::sweep, run hourly). All three are
        // in DAYS on purpose: every reader of these rows works in seconds or
        // minutes, so no value an operator can enter here comes close to one
        // of those windows. player_ttl_days removes the player (and their
        // friendships, not their property); duel_ttl_days the pair row a
        // finished duel leaves behind; alert_ttl_days the alerts that have
        // been read.
        'player_ttl_days' => [365, 'Remove players not seen for N days (0 = never)',
            'A player not seen for this many days is removed by the hourly housekeeping, with its friendships. '
            . 'The items it owns stay registered. 0 = never.'],
        'duel_ttl_days' => [7, 'Forget a duel pair not seen for N days (0 = never)',
            'The duels row a pair leaves behind is deleted after this many days without a beat. Item claims read '
            . 'it; nothing a client sees does. 0 = never.'],
        'alert_req_per_min' => [600, 'Alert: total requests per minute above',
            'Raise the traffic alert when the server counts more requests than this in the current minute.'],
        'alert_load_per_core' => [2, 'Alert: 1-minute load per CPU core above',
            'Raise the overload alert when the host\'s 1-minute load average per CPU core is above this. It is '
            . 'the whole shared machine\'s load, not this server\'s.'],
        'alert_online' => [200, 'Alert: concurrent online players above',
            'Raise the connections alert when more players than this are online at once.'],
        'alert_invalid_per_min' => [30, 'Alert: invalid requests per IP per minute above',
            'Raise the spam alert when one IP sends more invalid requests than this in a minute. A signal only; '
            . 'nothing is blocked.'],
        'alert_cooldown' => [60, 'Alert de-duplication window (seconds)',
            'An alert of the same type raised again within this window is suppressed. The gate lives in shared '
            . 'memory, so clearing the alert list does not reset it.'],
        'alert_ttl_days' => [30, 'Remove alerts that have been read after N days (0 = never)',
            'Alerts marked read are deleted after this many days by the hourly housekeeping; unread ones are kept '
            . 'for ever. 0 = never.'],
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
        'match_open_max_ms' => [60000, 'Grace a claim gets after its duel goes quiet (ms)',
            'After a duel\'s last heartbeat, an item claim against that match is still accepted for this long. '
            . 'It is also how long one side can move the other\'s items unwitnessed while the other is offline, '
            . 'so it is short on purpose.'],
        'claim_grace_ms' => [60000, 'Unconfirmed gain claim waits this long for the peer tag before settling (ms)',
            'A gain claim the peer has not attested is held this long, then settles by itself. A reported loss '
            . 'settles at once.'],
        'ledger_max_rows' => [200000, 'Checkpoint and truncate the item ledger above this many rows',
            'Above this many rows the item ledger (audit only, hash-chained) is checkpointed and truncated.'],
        'ledger_sample' => [200, 'One request in N may run the item-ledger truncation check',
            'One items request in this many runs the ledger-size check, in its deferred tail.'],
        'mint_max_per_hour' => [60, 'Max client-driven item mints per player per hour',
            'Item mints one player may make per hour; a mint beyond it is refused with 429. Minting stays '
            . 'client-trusted: the cap bounds the rate, not who may mint.'],
        // Tournament mode (see Tournament, api/tournament.php). The two _ms
        // deadlines are the whole of the "what if nobody answers" story, and
        // both are evaluated lazily on the next touch - there is no timer.
        // result_ms is short: it only has to outlast the losing client's own
        // report of a match that just ended. walkover_ms is long, and only
        // ever fires against a player who is also OFFLINE, so a slow match
        // between two present players is never taken away from them.
        'tournament_max_players' => [FOK_TOURNAMENT_MAX_PLAYERS, 'Max players in one tournament',
            'Seats in one tournament. A join beyond it answers full; an event monitor takes no seat. Every lobby '
            . 'announcement carries it.'],
        'tournament_join_ttl' => [900, 'Abandon a lobby nobody started after (seconds)',
            'A lobby nobody started is dropped after this long, and its join code is reserved as long.'],
        'tournament_run_ttl' => [3600, 'Forget a running tournament untouched for (seconds)',
            'A running tournament untouched for this long is dropped, and the host\'s claim to be hosting lives '
            . 'as long.'],
        'tournament_done_ttl' => [300, 'Forget a finished tournament after (seconds)',
            'How long a finished tournament stays readable.'],
        'tournament_abandoned_ttl' => [60, 'Forget an abandoned tournament after (seconds)',
            'How long an abandoned tournament stays readable.'],
        'tournament_result_ms' => [15000, 'A one-sided result settles after this long unanswered (ms)',
            'A match result reported by one side only becomes final after this long without the other side\'s '
            . 'report. A reported loss settles at once; a contradiction freezes the node.'],
        'tournament_walkover_ms' => [180000, 'An offline player forfeits the match in flight after (ms)',
            'A dealt match one of whose players reads offline is handed to the other after this long. It never '
            . 'fires while both are present.'],
        'tournament_deadlock_ms' => [150000, 'A match neither present player can connect is re-dealt, then voided, after (ms)',
            'A dealt match between two online players that never started is re-dealt after this long, and voided '
            . 'on the second lapse.'],
        'tournament_create_cooldown' => [10, 'Min seconds between one host creating tournaments',
            'Least seconds between two tournaments created by the same host.'],
        'tournament_announce_window' => [180, 'Announce a lobby while its host was seen within (seconds)',
            'A lobby is announced to players on the host\'s network while the host has been seen within this many '
            . 'seconds. A network the server observed itself outranks one the client claims for as long.'],
        'tournament_idle_ttl' => [180, 'End a tournament no player has been seen at for (seconds)',
            'The sweep ends a tournament none of whose players has been seen for this long. The test is '
            . 'presence, never activity.'],
        'tournament_sweep_secs' => [30, 'Min seconds between two sweeps for those (0 = every request)',
            'Least seconds between two runs of that sweep. It rides the deferred tail of ordinary requests, never '
            . 'of an admin request. 0 = every request.'],
        // The round ladder. The level a round is played at is its round
        // number, so a wider field reaches a deeper final; the cap is the
        // game's own last level. The break between two rounds is where the
        // scoreboard is read, so it has a floor (a continue that arrives
        // before anyone could have read it is a stray tap) and a ceiling (a
        // host that closed its browser must not wedge the tournament).
        'tournament_max_level' => [FOK_TOURNAMENT_MAX_LEVEL, 'Deepest level a tournament round is played at',
            'Deepest game level a round is played at: round N plays at the start level plus N-1, capped here. '
            . 'Must not exceed the game\'s own last level (10).'],
        'tournament_break_ms' => [1000, 'Min time a round-break scoreboard stays up before continue (ms)',
            'A host\'s continue that arrives sooner than this after the round-break scoreboard appeared is refused '
            . 'as a stray tap.'],
        'tournament_break_ttl_ms' => [120000, 'A round break continues by itself after (ms)',
            'A round break the host never continues ends by itself after this long.'],
        // Events. The pass is a code derived from the clock, so a slot needs
        // no row and a code is valid across two of them: what is on somebody
        // else's screen must still work while that screen has moved on. The
        // client reads both numbers off the `pass` answer and hard-codes
        // neither.
        'event_pass_step_secs' => [10, 'How often the event pass QR rotates (seconds)',
            'How often the event pass (the QR a member\'s screen shows) rotates. A pass is derived from the '
            . 'clock; no row is stored.'],
        'event_pass_valid_secs' => [20, 'How long an event pass stays valid (seconds)',
            'How long a pass stays accepted after its slot began; at least one step, so a code still on '
            . 'somebody\'s screen keeps working across a rotation. The client reads both numbers off the pass '
            . 'answer.'],
        'event_join_fails_per_min' => [10, 'Wrong event codes per player per minute before 429',
            'Wrong event codes one player may try in a minute; from then on the answer is 429 and the log gets a '
            . 'line. It puts the attempt on record; the codes cannot be guessed.'],
        'admin_refresh_secs' => [30, 'Admin dashboard refresh interval (seconds, 0 = off)',
            'The dashboard\'s global refresh interval, the field in the header. 0 = off.'],
        'admin_conns_refresh_secs' => [1, 'Connections card refresh interval (seconds, 0 = off)',
            'Refresh interval of the Connections card. 0 = off.'],
        'admin_duels_refresh_secs' => [5, 'Duels card refresh interval (seconds, 0 = off)',
            'Refresh interval of the Duels card. 0 = off.'],
        'admin_perf_refresh_secs' => [5, 'Server performance card refresh interval (seconds, 0 = off)',
            'Refresh interval of the Server performance card. 0 = off.'],
        'admin_stats_refresh_secs' => [10, 'Statistics card refresh interval (seconds, 0 = off)',
            'Refresh interval of the Statistics card. 0 = off.'],
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
            // A row IS the override, so the default is stored as no row at all.
            // Writing it would pin today's number and shadow every later one -
            // which is what a config export/import roundtrip does to every key
            // it carries, and how an install ends up answering a cap the code
            // no longer sets.
            if ($value === self::DEFS[$key][0]) {
                Db::get()->prepare('DELETE FROM settings WHERE key = ?')->execute([$key]);
                return;
            }
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

    /** @return array<int, array{key:string, value:int, default:int, label:string, help:string}> */
    public static function all(): array
    {
        $out = [];
        foreach (self::DEFS as $key => [$default, $label, $help]) {
            $out[] = [
                'key' => $key,
                'value' => self::int($key),
                'default' => $default,
                'label' => $label,
                'help' => $help,
            ];
        }
        return $out;
    }
}
