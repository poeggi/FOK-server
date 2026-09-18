<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Caps.php';
require_once __DIR__ . '/Alerts.php';
require_once __DIR__ . '/Stats.php';

/**
 * TURN credentials (API 4.22): the tap on Cloudflare's relay.
 *
 * A duel is peer to peer, and a peer behind a NAT that STUN cannot open has
 * no path. TURN is the standard way through and this host cannot run one,
 * so the relay is Cloudflare's and what this server holds is the KEY that
 * mints credentials for it (FOK_TURN_FILE). The credential is the one
 * thing on that path the server controls: it is minted here, per id and
 * short-lived, and it is revoked here. Nobody relays a byte without one.
 *
 * The relay bills bytes past a monthly free tier and this server must
 * never let it, so what it hands out is CAPPED: every mint is a row in
 * turn_mints, and past turn_max_per_30d of them in the last 30 days
 * nothing more is minted until the window frees. Past turn_warn_pct of
 * the cap the operator is alerted. The count is the whole budget - what a
 * credential then relays is bounded by its ttl and the relay's own rate,
 * not measured here. The verdict is DERIVED at every ask (refusal), never
 * latched: an operator who raises the cap or flips turn_enabled is obeyed
 * at the next ask, and a switch-off also revokes every credential still
 * out (enforce, on the deferred tail).
 *
 * Shared memory holds the credentials out (one entry per id, gone with
 * the ttl) and the enforcement gate; the table holds the mints. Without
 * APCu nothing is cached and the live list reads empty.
 */
final class Turn
{
    private const RTC_BASE = 'https://rtc.live.cloudflare.com';
    // Per-environment on purpose: a credential is judged against this
    // environment's mints, and the live count is a reading of it.
    private const PREFIX = FOK_APCU_NS . 'turn:';
    private const CRED = self::PREFIX . 'c:';
    private const ENFORCE_KEY = self::PREFIX . 'enforce';
    // How often the deferred tail checks that a switched-off relay holds
    // no credentials. A constant: it bounds how long a switch-off takes to
    // reach the credentials out, and a minute is what the help text says.
    private const ENFORCE_SECS = 60;
    // The window the cap counts over, in seconds; the setting names it.
    public const WINDOW = 30 * 86400;
    // Seconds one call to the relay's API may take: a mint runs inside a
    // player's request.
    private const HTTP_TIMEOUT = 6;
    // The heaviest ids the popup lists.
    private const TOP = 20;

    /** @var null|callable the transport, replaced by the unit tests */
    private static $http = null;
    private static bool $cfgRead = false;
    private static ?array $cfg = null;

    /**
     * Replaces the HTTP transport, or restores the real one with null. The
     * callable takes (method, url, headers, body) and answers [status, body].
     */
    public static function setTransport(?callable $fn): void
    {
        self::$http = $fn;
        self::$cfgRead = false;
    }

    /**
     * The key file's members, or null when there is no usable key. Read
     * once per request. A file that is there but wrong is said so in the
     * log: a misconfiguration must be loud, an absent file is a choice.
     *
     * @return array{key_id:string, key_token:string, rtc:string}|null
     */
    public static function config(): ?array
    {
        if (self::$cfgRead) {
            return self::$cfg;
        }
        self::$cfgRead = true;
        self::$cfg = null;
        if (!is_readable(FOK_TURN_FILE)) {
            return null;
        }
        $j = json_decode((string)file_get_contents(FOK_TURN_FILE), true);
        $cfg = [];
        foreach (['key_id', 'key_token'] as $k) {
            $v = is_array($j) ? ($j[$k] ?? '') : '';
            if (!is_string($v) || trim($v) === '' || str_starts_with($v, 'PASTE_')) {
                Alerts::warn('turn', "turn.json has no usable $k; no TURN offered");
                return null;
            }
            $cfg[$k] = trim($v);
        }
        $v = $j['rtc_base'] ?? '';
        $cfg['rtc'] = is_string($v) && $v !== '' ? rtrim($v, '/') : self::RTC_BASE;
        return self::$cfg = $cfg;
    }

    /**
     * Credentials for $id, or null when none are offered. The answer is
     * {ice, ttl}: the relay's own iceServers list and the seconds left.
     *
     * An id that still holds credentials with at least half their life
     * left gets the same ones back - a rematch, a retry, a second attempt
     * in one sitting all cost one mint and count once against the cap.
     */
    public static function mint(string $id, ?int $now = null): ?array
    {
        $now ??= time();
        $cfg = self::config();
        if ($cfg === null) {
            return null;
        }
        // The switch refuses everything, a credential already out included:
        // off means off. The cap below only refuses a MINT - an id holding
        // a credential with half its life left is answered it either way,
        // because that costs the count nothing.
        if (Settings::int('turn_enabled') !== 1) {
            Alerts::note('turn', "credentials refused for $id: switched off");
            return null;
        }
        // The relay caps a credential at 48 h; below a minute nothing could
        // finish its handshake on it.
        $ttl = min(172800, max(60, Settings::int('turn_ttl_secs')));
        $held = self::held($id);
        if ($held !== null && $held['exp'] - $now >= intdiv($ttl, 2)) {
            return ['ice' => $held['ice'], 'ttl' => $held['exp'] - $now];
        }
        $n = self::recent($now);
        $why = self::refusal($n);
        if ($why !== '') {
            // On record every time: the log answers how often, the alert
            // below (raised once, when the cap was reached) that it was.
            Alerts::note('turn', "credentials refused for $id: " . self::reason($why, $n));
            return null;
        }
        $r = self::call('POST',
            $cfg['rtc'] . '/v1/turn/keys/' . rawurlencode($cfg['key_id']) . '/credentials/generate-ice-servers',
            ['Authorization: Bearer ' . $cfg['key_token'], 'Content-Type: application/json'],
            json_encode(['ttl' => $ttl, 'customIdentifier' => $id]));
        $ice = $r[0] === 201 || $r[0] === 200 ? self::iceOf($r[1]) : null;
        if ($ice === null) {
            Alerts::raise('turn-error', 'TURN credentials could not be minted: ' . self::said($r), 'error');
            return null;
        }
        $entry = ['u' => $ice['username'], 'ice' => $ice['ice'], 'at' => $now, 'exp' => $now + $ttl];
        if (Caps::apcu()) {
            apcu_store(self::CRED . $id, $entry, $ttl);
        }
        Db::retry(static function () use ($id, $now): void {
            $db = Db::get();
            $db->prepare('INSERT INTO turn_mints (at, id) VALUES (?, ?)')->execute([$now, $id]);
            Stats::bumpIn($db, ['turn_mints' => 1]);
        });
        // The lines are crossed by THIS mint or not at all, so each alert
        // fires once per crossing and a stream of asks past the cap raises
        // nothing more - the note above is their record.
        $n++;
        $cap = Settings::int('turn_max_per_30d');
        $warn = self::warnLine();
        if ($n === $warn && $warn < $cap) {
            Alerts::raise('turn', "TURN credentials at $n of $cap in the last 30 days ("
                . Settings::int('turn_warn_pct') . '% of the cap)');
        } elseif ($n === $cap) {
            Alerts::raise('turn-stop', "TURN stopped: $cap credentials handed out in the last 30 days; "
                . 'none until the window frees or turn_max_per_30d is raised');
        }
        return ['ice' => $ice['ice'], 'ttl' => $ttl];
    }

    /**
     * The deferred tail's share: once a minute across the pool, and
     * nothing at all - not even a stat of the key file - for the requests
     * that lose the gate. A switched-off relay must hold no credentials.
     */
    public static function tick(): void
    {
        if (!Caps::apcu() || !apcu_add(self::ENFORCE_KEY, 1, self::ENFORCE_SECS)) {
            return;
        }
        if (self::config() !== null && Settings::int('turn_enabled') !== 1) {
            self::enforce();
        }
    }

    /**
     * Revokes every credential out. What the operator's switch means, and
     * what the smoke calls directly. Returns how many went.
     */
    public static function enforce(): int
    {
        return self::revokeAll(self::config());
    }

    /**
     * Why an ask would be refused with $n mints in the window: '' when
     * credentials are offered. Derived from the settings, latched nowhere.
     */
    private static function refusal(int $n): string
    {
        if (Settings::int('turn_enabled') !== 1) {
            return 'off';
        }
        if ($n >= Settings::int('turn_max_per_30d')) {
            return 'cap';
        }
        return '';
    }

    private static function reason(string $why, int $n): string
    {
        return match ($why) {
            'off' => 'switched off',
            'cap' => "$n of " . Settings::int('turn_max_per_30d') . ' in the last 30 days',
            'unconfigured' => 'no key',
            default => $why,
        };
    }

    private static function warnLine(): int
    {
        return (int)ceil(Settings::int('turn_max_per_30d') * Settings::int('turn_warn_pct') / 100);
    }

    /** Mints in the last 30 days. */
    private static function recent(int $now): int
    {
        $st = Db::get()->prepare('SELECT COUNT(*) FROM turn_mints WHERE at > ?');
        $st->execute([$now - self::WINDOW]);
        $n = (int)$st->fetchColumn();
        $st->closeCursor();
        return $n;
    }

    /** What the stats card shows: the two figures on the bubble, and whether anything is offered. */
    public static function gauge(?int $now = null): array
    {
        $now ??= time();
        $n = self::recent($now);
        $cap = Settings::int('turn_max_per_30d');
        $why = self::config() === null ? 'unconfigured' : self::refusal($n);
        return [
            'live' => count(self::live()),
            'sessions' => Stats::all()['turn_mints'] ?? 0,
            'recent' => $n,
            'cap' => $cap,
            'offered' => $why === '',
            'why' => $why,
        ];
    }

    /** Everything the popup shows. Never the key, never a username. */
    public static function detail(?int $now = null): array
    {
        $now ??= time();
        $n = self::recent($now);
        $why = self::config() === null ? 'unconfigured' : self::refusal($n);
        $live = [];
        foreach (self::live() as $id => $e) {
            $live[] = ['id' => (string)$id, 'at' => $e['at'], 'exp' => $e['exp']];
        }
        $st = Db::get()->prepare(
            'SELECT id, COUNT(*) AS n, MAX(at) AS last FROM turn_mints WHERE at > ?
             GROUP BY id ORDER BY n DESC, last DESC LIMIT ?'
        );
        $st->execute([$now - self::WINDOW, self::TOP]);
        $top = [];
        foreach ($st->fetchAll() as $r) {
            $top[] = ['id' => (string)$r['id'], 'n' => (int)$r['n'], 'last' => (int)$r['last']];
        }
        return [
            'now' => $now,
            'configured' => self::config() !== null,
            'enabled' => Settings::int('turn_enabled') === 1,
            'offered' => $why === '',
            'why' => $why,
            'recent' => $n,
            'cap' => Settings::int('turn_max_per_30d'),
            'warn' => self::warnLine(),
            'ttl_secs' => Settings::int('turn_ttl_secs'),
            'sessions' => Stats::all()['turn_mints'] ?? 0,
            'live' => $live,
            'top' => $top,
        ];
    }

    /**
     * The hourly reaping's share: a mint older than the window counts
     * against nothing and is read by nothing. Returns rows removed.
     */
    public static function prune(PDO $db, int $now): int
    {
        $st = $db->prepare('DELETE FROM turn_mints WHERE at <= ?');
        $st->execute([$now - self::WINDOW]);
        return $st->rowCount();
    }

    /** Ids holding credentials, id => entry. Empty without shared memory. */
    private static function live(): array
    {
        if (!Caps::apcu() || !class_exists('APCUIterator')) {
            return [];
        }
        $out = [];
        foreach (new APCUIterator('/^' . preg_quote(self::CRED, '/') . '/') as $e) {
            if (is_array($e['value']) && isset($e['value']['u'])) {
                $out[substr((string)$e['key'], strlen(self::CRED))] = $e['value'];
            }
        }
        return $out;
    }

    private static function held(string $id): ?array
    {
        if (!Caps::apcu()) {
            return null;
        }
        $e = apcu_fetch(self::CRED . $id);
        return is_array($e) && isset($e['u'], $e['exp'], $e['ice']) ? $e : null;
    }

    /**
     * Revokes every credential out and forgets the ones the relay confirmed
     * gone; one it did not confirm stays listed, so the next enforcement
     * tries it again. Without a key nothing can be revoked and the list is
     * dropped: what is out expires on its own.
     */
    private static function revokeAll(?array $cfg): int
    {
        $n = 0;
        foreach (self::live() as $id => $e) {
            $ok = $cfg === null;
            if (!$ok) {
                $r = self::call('POST', $cfg['rtc'] . '/v1/turn/keys/' . rawurlencode($cfg['key_id'])
                    . '/credentials/' . rawurlencode((string)$e['u']) . '/revoke',
                    ['Authorization: Bearer ' . $cfg['key_token']], null);
                // Gone is gone: a credential the relay no longer knows is as
                // revoked as one it just dropped.
                $ok = $r[0] === 204 || $r[0] === 200 || $r[0] === 404;
                if (!$ok) {
                    Alerts::warn('turn', "revoking the credential of $id failed: " . self::said($r));
                }
            }
            if ($ok) {
                apcu_delete(self::CRED . (string)$id);
                $n++;
            }
        }
        if ($n > 0) {
            Alerts::note('turn', "$n credential(s) revoked");
        }
        return $n;
    }

    /**
     * The iceServers list out of a mint answer, port-53 urls dropped (the
     * relay's own note: a browser times out on them), and the username
     * that names the credential for a revoke. Null when the answer holds
     * no credential.
     *
     * @return array{ice:array, username:string}|null
     */
    private static function iceOf(string $body): ?array
    {
        $j = json_decode($body, true);
        $list = $j['iceServers'] ?? null;
        if (is_array($list) && isset($list['urls'])) {
            $list = [$list];
        }
        if (!is_array($list)) {
            return null;
        }
        $ice = [];
        $username = '';
        foreach ($list as $srv) {
            if (!is_array($srv)) {
                continue;
            }
            $urls = is_string($srv['urls'] ?? null) ? [$srv['urls']] : ($srv['urls'] ?? []);
            $urls = array_values(array_filter(is_array($urls) ? $urls : [],
                static fn($u): bool => is_string($u) && !preg_match('/:53(\?|$)/', $u)));
            if ($urls === []) {
                continue;
            }
            $one = ['urls' => $urls];
            if (isset($srv['username'], $srv['credential']) && is_string($srv['username']) && is_string($srv['credential'])) {
                $one['username'] = $srv['username'];
                $one['credential'] = $srv['credential'];
                $username = $srv['username'];
            }
            $ice[] = $one;
        }
        return $username === '' ? null : ['ice' => $ice, 'username' => $username];
    }

    /** One line saying what the relay answered, for a log line. Never a body that could carry a secret. */
    private static function said(array $r): string
    {
        if ($r[0] === 0) {
            return 'no answer (' . $r[1] . ')';
        }
        $j = json_decode($r[1], true);
        $msg = '';
        if (is_array($j)) {
            $first = $j['errors'][0] ?? null;
            $msg = is_array($first) ? (string)($first['message'] ?? '') : (string)($j['error'] ?? $j['message'] ?? '');
        }
        return 'HTTP ' . $r[0] . ($msg !== '' ? ' ' . substr($msg, 0, 120) : '');
    }

    /**
     * The HTTP call, through the unit tests' transport when one is set.
     * Answers [status, body]; status 0 and the reason when the call itself
     * failed. curl when the host has it, the stream wrapper otherwise.
     *
     * @return array{0:int, 1:string}
     */
    private static function call(string $method, string $url, array $headers, ?string $body): array
    {
        if (self::$http !== null) {
            return (self::$http)($method, $url, $headers, $body);
        }
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $body ?? '',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => self::HTTP_TIMEOUT,
                CURLOPT_TIMEOUT => self::HTTP_TIMEOUT,
            ]);
            $out = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            return is_string($out) && $status > 0 ? [$status, $out] : [0, $err !== '' ? $err : 'curl failed'];
        }
        $ctx = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body ?? '',
            'timeout' => self::HTTP_TIMEOUT,
            'ignore_errors' => true,
        ]]);
        $out = @file_get_contents($url, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int)$m[1];
            }
        }
        return is_string($out) && $status > 0 ? [$status, $out] : [0, error_get_last()['message'] ?? 'request failed'];
    }

    /** Drops every credential and the gate; the unit tests start clean with it. */
    public static function forget(): void
    {
        Caps::dropKeys(self::PREFIX);
        self::$cfgRead = false;
    }
}
