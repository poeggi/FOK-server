<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Caps.php';
require_once __DIR__ . '/Util.php';
require_once __DIR__ . '/Settings.php';

/**
 * Events (API 4.11, see docs/API.md): a room an operator opens on the
 * server, entered by scanning its QR - the long-lived key printed on a
 * poster, or the 20-second pass a member shows on screen.
 *
 * THE SERVER IS THE ROSTER. event_members is the only truth about who is
 * in an event; the organizer's client and the operator's dashboard are
 * two remote controls for the same rows, and neither keeps a copy. That
 * is deliberately not the friends-list pattern, whose local copy plus
 * startup reconciliation is what makes a restored config fire a burst of
 * requests.
 *
 * The rows are the durability and the truth; the READS run out of shared
 * memory, the way presence, the tournament store and the friend delta do.
 * Unlike the state that MOVED to APCu (Signals, ConnTrack, Matchmaking)
 * this is a CACHE, so every path falls through to SQLite when APCu is
 * unusable - the Settings/Caps rule, not the no-fallback rule.
 *
 * State is DERIVED at read time and never swept: there is no cron here, so
 * a scheduled event's start and end are a pure function of its two stamps
 * and the clock. Nothing fires at either moment and nothing needs to.
 */
final class Events
{
    /**
     * The same alphabet tournament codes use: no 0/O/1/I/L, because these
     * are read off a poster and typed back in.
     */
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public const EID_LEN = 4;

    /** Four characters of the alphabet, the shape every eid has. */
    public static function isEid(mixed $v): bool
    {
        return is_string($v)
            && preg_match('/^[' . self::ALPHABET . ']{' . self::EID_LEN . '}$/', $v) === 1;
    }

    /**
     * The printed key: a standalone token that names its own event, so the
     * poster URL is `#event=<key>` with no eid beside it. Eleven characters
     * because that is the whole budget - the game's own QR decoder is a
     * fixed version 3 at level L (53 text bytes) and the URL prefix takes
     * 42 of them. 31^11 is about 2^54, which against the per-player wrong-
     * code throttle is not a keyspace anybody walks.
     */
    public const KEY_LEN = 11;

    public const PASS_LEN = 6;

    public const MAX_NAME = 40;
    public const MAX_DESCR = 500;

    /** The event row, minus nothing: it carries the key and the secret. */
    private const CARD = FOK_APCU_NS . 'ev:';

    /** The caller's own rows: [['eid' => ..., 'state' => ...], ...]. */
    private const MINE = FOK_APCU_NS . 'em:';

    /** Member and pending counts per event. */
    private const COUNT = FOK_APCU_NS . 'en:';

    /** Wrong-code attempts, per player id. */
    private const FAILS = FOK_APCU_NS . 'ex:';

    /** Who is holding an event's one monitor slot right now. */
    private const MON = FOK_APCU_NS . 'emon:';

    /**
     * How long a monitor claim outlives its last request. The same window
     * everything else here reads a player through, so a TV that loses its
     * network for a moment does not lose its slot to the next person who
     * asks - and one that is switched off frees it without pressing
     * anything, which is the whole point of a screen nobody attends.
     */
    private const MON_TTL = FOK_ONLINE_WINDOW;

    /**
     * Every cache here is dropped at the write that invalidates it, so a
     * TTL is a backstop against a write path nobody remembered, not the
     * mechanism.
     */
    private const CACHE_TTL = 300;

    /** Per-request memo, so one request reads an event card once. */
    private static array $memo = [];

    // ---------------------------------------------------------------
    // State
    // ---------------------------------------------------------------

    /**
     * The effective state of an event: a pure function of its stored mode,
     * its two schedule stamps and the clock. Caching the CARD is safe;
     * caching this would be putting a clock in shared memory.
     *
     * @param array $card an events row
     */
    public static function stateOf(array $card, ?int $now = null): string
    {
        $now ??= time();
        $mode = (string)$card['mode'];
        if ($mode === 'ended') {
            return 'ended';
        }
        $ends = $card['ends'] === null ? null : (int)$card['ends'];
        if ($ends !== null && $now >= $ends) {
            return 'ended';
        }
        // A pause outranks a schedule that says the event is under way:
        // somebody pressed it, and only they can undo it.
        if ($mode === 'paused') {
            return 'paused';
        }
        $starts = $card['starts'] === null ? null : (int)$card['starts'];
        if ($starts !== null) {
            return $now < $starts ? 'upcoming' : 'active';
        }
        return $mode === 'active' ? 'active' : 'upcoming';
    }

    /** An event whose moments are the clock's, not its organizer's. */
    public static function isScheduled(array $card): bool
    {
        return $card['starts'] !== null || $card['ends'] !== null;
    }

    // ---------------------------------------------------------------
    // Reading
    // ---------------------------------------------------------------

    /**
     * One event row, from shared memory where possible. Carries `ekey` and
     * `secret`: NEVER hand this to a projection without stripping them.
     */
    public static function card(string $eid): ?array
    {
        if (array_key_exists($eid, self::$memo)) {
            return self::$memo[$eid];
        }
        if (Caps::apcu()) {
            $hit = apcu_fetch(self::CARD . $eid, $ok);
            if ($ok && is_array($hit)) {
                return self::$memo[$eid] = $hit;
            }
        }
        $st = Db::get()->prepare('SELECT * FROM events WHERE eid = ?');
        $st->execute([$eid]);
        $row = $st->fetch();
        $st->closeCursor();
        if (!$row) {
            return self::$memo[$eid] = null;
        }
        $card = [
            'eid' => (string)$row['eid'],
            'name' => (string)$row['name'],
            'descr' => (string)$row['descr'],
            'organizer' => $row['organizer'] === null ? null : (string)$row['organizer'],
            'ekey' => (string)$row['ekey'],
            'secret' => (string)$row['secret'],
            'closed' => (int)$row['closed'] === 1,
            'starts' => $row['starts'] === null ? null : (int)$row['starts'],
            'ends' => $row['ends'] === null ? null : (int)$row['ends'],
            'mode' => (string)$row['mode'],
            'ach_name' => $row['ach_name'] === null ? null : (string)$row['ach_name'],
            'ach_desc' => $row['ach_desc'] === null ? null : (string)$row['ach_desc'],
            'ach_icon' => $row['ach_icon'] === null ? null : (string)$row['ach_icon'],
            'monitor_allowed' => (int)($row['monitor_allowed'] ?? 1) === 1,
            'monitor' => ($row['monitor'] ?? null) === null ? null : (string)$row['monitor'],
            'created' => (int)$row['created'],
            'ended_at' => $row['ended_at'] === null ? null : (int)$row['ended_at'],
        ];
        if (Caps::apcu()) {
            apcu_store(self::CARD . $eid, $card, self::CACHE_TTL);
        }
        return self::$memo[$eid] = $card;
    }

    /**
     * The event a printed key names, or null. The key is the capability AND
     * the address: a poster carries nothing else, because nothing else fits
     * beside it in a version 3 code.
     */
    public static function byKey(string $key): ?array
    {
        $st = Db::get()->prepare('SELECT eid FROM events WHERE ekey = ?');
        $st->execute([$key]);
        $eid = $st->fetchColumn();
        $st->closeCursor();
        return $eid === false ? null : self::card((string)$eid);
    }

    /** Dropped at every write to the events row. */
    public static function forgetCard(string $eid): void
    {
        unset(self::$memo[$eid]);
        if (Caps::apcu()) {
            apcu_delete(self::CARD . $eid);
        }
    }

    /**
     * One roster row, or null. Reads the caller's cached membership first,
     * so the common "am I in this event" question costs one apcu_fetch.
     */
    public static function rowOf(string $eid, string $id): ?array
    {
        foreach (self::mine($id) as $row) {
            if ($row['eid'] === $eid) {
                return $row;
            }
        }
        return null;
    }

    /** True when this player may see the event's members-only half. */
    public static function isMember(string $eid, string $id): bool
    {
        $row = self::rowOf($eid, $id);
        return $row !== null && $row['state'] === 'member';
    }

    public static function isOrganizer(array $card, string $id): bool
    {
        return $card['organizer'] !== null && $card['organizer'] === $id;
    }

    /**
     * The caller's own event rows, cached. This is what answers `events` on
     * hello and poll without a query, and what a tournament join checks
     * membership against.
     *
     * @return list<array{eid: string, state: string}>
     */
    public static function mine(string $id): array
    {
        if (Caps::apcu()) {
            $hit = apcu_fetch(self::MINE . $id, $ok);
            if ($ok && is_array($hit)) {
                return $hit;
            }
        }
        $st = Db::get()->prepare(
            'SELECT eid, state, joined FROM event_members WHERE id = ?'
        );
        $st->execute([$id]);
        $rows = [];
        foreach ($st->fetchAll() as $row) {
            $rows[] = [
                'eid' => (string)$row['eid'],
                'state' => (string)$row['state'],
                'joined' => $row['joined'] === null ? null : (int)$row['joined'],
            ];
        }
        $st->closeCursor();
        if (Caps::apcu()) {
            apcu_store(self::MINE . $id, $rows, self::CACHE_TTL);
        }
        return $rows;
    }

    /** Dropped at every event_members write touching this player. */
    public static function forgetMine(string $id): void
    {
        if (Caps::apcu()) {
            apcu_delete(self::MINE . $id);
        }
    }

    /**
     * How many have joined, how many are waiting, and the screen. Four
     * figures because a monitor is counted APART from the members rather
     * than among them - it is in the event without being at it.
     * @return array{members: int, pending: int, banned: int, monitor: int}
     */
    public static function counts(string $eid): array
    {
        if (Caps::apcu()) {
            $hit = apcu_fetch(self::COUNT . $eid, $ok);
            if ($ok && is_array($hit)) {
                return $hit;
            }
        }
        $st = Db::get()->prepare(
            'SELECT state, COUNT(*) AS n FROM event_members WHERE eid = ? GROUP BY state'
        );
        $st->execute([$eid]);
        $out = ['members' => 0, 'pending' => 0, 'banned' => 0, 'monitor' => 0];
        // The row states are singular ('member'), the figures plural:
        // these are two vocabularies and the map is what joins them.
        $as = ['member' => 'members', 'pending' => 'pending', 'banned' => 'banned',
               'monitor' => 'monitor'];
        foreach ($st->fetchAll() as $row) {
            $key = $as[(string)$row['state']] ?? null;
            if ($key !== null) {
                $out[$key] = (int)$row['n'];
            }
        }
        $st->closeCursor();
        if (Caps::apcu()) {
            apcu_store(self::COUNT . $eid, $out, self::CACHE_TTL);
        }
        return $out;
    }

    public static function forgetCounts(string $eid): void
    {
        if (Caps::apcu()) {
            apcu_delete(self::COUNT . $eid);
        }
    }

    /**
     * Who a transition is told about: the members, and the MONITOR. A
     * monitor is absent from every roster and every count, but it is a
     * screen whose whole job is showing what just changed, so it is told.
     * A pending row is not: it is standing at a door, and a door tells you
     * nothing about the room behind it.
     *
     * @return list<string>
     */
    public static function audience(string $eid): array
    {
        $st = Db::get()->prepare(
            "SELECT id FROM event_members WHERE eid = ? AND state IN ('member', 'monitor')");
        $st->execute([$eid]);
        $ids = [];
        foreach ($st->fetchAll() as $row) {
            $ids[] = (string)$row['id'];
        }
        $st->closeCursor();
        return $ids;
    }

    /**
     * The roster. Pending and banned rows are the organizer's business, so
     * a plain member asks with $all false and never learns they exist.
     * @return list<array<string, mixed>>
     */
    public static function members(string $eid, bool $all, bool $withMonitor = false): array
    {
        $sql = 'SELECT id, state, asked, joined, via FROM event_members WHERE eid = ?';
        if (!$all) {
            $sql .= " AND state = 'member'";
        } elseif (!$withMonitor) {
            // A MONITOR is in the event without being at it: it is a screen
            // on a wall, so it is absent from the roster the organizer and
            // the players read. The operator's popup asks for it by name.
            $sql .= " AND state != 'monitor'";
        }
        $st = Db::get()->prepare($sql . ' ORDER BY joined IS NULL DESC, joined, asked');
        $st->execute([$eid]);
        $rows = [];
        foreach ($st->fetchAll() as $row) {
            $rows[] = [
                'id' => (string)$row['id'],
                'state' => (string)$row['state'],
                'asked' => (int)$row['asked'],
                'joined' => $row['joined'] === null ? null : (int)$row['joined'],
                'via' => (string)$row['via'],
            ];
        }
        $st->closeCursor();
        return $rows;
    }

    /**
     * The caller's events as hello and poll answer them. Cheap by design:
     * one apcu_fetch for the membership, one per event for the card.
     * @return list<array<string, mixed>>
     */
    public static function listFor(string $id, ?int $now = null): array
    {
        $now ??= time();
        $out = [];
        foreach (self::mine($id) as $row) {
            if ($row['state'] === 'banned') {
                continue;
            }
            $card = self::card($row['eid']);
            if ($card === null) {
                continue;
            }
            $entry = [
                'eid' => $card['eid'],
                'name' => $card['name'],
                'closed' => $card['closed'],
                'state' => self::stateOf($card, $now),
                'starts' => $card['starts'] === null ? null : $card['starts'] * 1000,
                'ends' => $card['ends'] === null ? null : $card['ends'] * 1000,
                'monitor_allowed' => $card['monitor_allowed'],
                'you' => [
                    'state' => $row['state'],
                    'organizer' => self::isOrganizer($card, $id),
                ],
            ];
            // A pending row is told nothing about the size of the room it is
            // waiting outside of.
            if ($row['state'] === 'member' || $row['state'] === 'monitor') {
                $entry['members'] = self::counts($card['eid'])['members'];
            }
            $out[] = $entry;
        }
        return $out;
    }

    // ---------------------------------------------------------------
    // Passes
    // ---------------------------------------------------------------

    /**
     * The code for one slot: an HMAC over the event's secret, mapped onto
     * the code alphabet. Stateless, so showing a pass stores nothing and
     * the server never learns who showed it.
     */
    public static function passFor(string $secretHex, string $eid, int $slot): string
    {
        $raw = hash_hmac('sha256', $eid . '|' . $slot, (string)hex2bin($secretHex), true);
        $n = strlen(self::ALPHABET);
        $code = '';
        for ($i = 0; $i < self::PASS_LEN; $i++) {
            $code .= self::ALPHABET[ord($raw[$i]) % $n];
        }
        return $code;
    }

    /**
     * The next slots, so a QR screen asks once a minute and rotates locally
     * on the synced clock.
     * @return list<array{at: int, code: string}>
     */
    public static function mintPasses(array $card, int $count = 6, ?int $now = null): array
    {
        $now ??= time();
        $step = max(1, Settings::int('event_pass_step_secs'));
        $slot = intdiv($now, $step);
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $s = $slot + $i;
            $out[] = ['at' => $s * $step * 1000, 'code' => self::passFor($card['secret'], $card['eid'], $s)];
        }
        return $out;
    }

    /**
     * A pass verifies for every slot whose validity window still contains
     * now - two of them by default. The overlap is the point: a code read
     * off a screen must still work while the screen has moved on.
     */
    public static function verifyPass(array $card, string $code, ?int $now = null): bool
    {
        $now ??= time();
        $step = max(1, Settings::int('event_pass_step_secs'));
        $valid = max($step, Settings::int('event_pass_valid_secs'));
        $newest = intdiv($now, $step);
        // Accepted: every slot S with S*step <= now < S*step + valid.
        $oldest = intdiv($now - $valid, $step) + 1;
        $hit = false;
        for ($s = $oldest; $s <= $newest; $s++) {
            // No early return: every candidate is compared, so the answer
            // costs the same whatever the code was.
            $hit = hash_equals(self::passFor($card['secret'], $card['eid'], $s), $code) || $hit;
        }
        return $hit;
    }

    // ---------------------------------------------------------------
    // The wrong-code throttle
    // ---------------------------------------------------------------

    /**
     * A fixed window per player, started by its first failure. apcu_inc
     * does not refresh a TTL, which is exactly what a fixed window wants:
     * the key ages out on its own and the next failure opens a new one.
     */
    public static function noteFail(string $id): int
    {
        if (!Caps::apcu()) {
            return 0;
        }
        $key = self::FAILS . $id;
        if (apcu_add($key, 1, 60)) {
            return 1;
        }
        $n = apcu_inc($key);
        return is_int($n) ? $n : 1;
    }

    public static function failsOver(string $id): bool
    {
        if (!Caps::apcu()) {
            return false;
        }
        $n = apcu_fetch(self::FAILS . $id, $ok);
        return $ok && is_int($n) && $n >= Settings::int('event_join_fails_per_min');
    }

    // ---------------------------------------------------------------
    // The monitor slot
    // ---------------------------------------------------------------

    /**
     * Who currently holds an event's one monitor slot, or null: the holder
     * of the LEASE in shared memory, whoever that is. A reservation (the
     * `monitor` column) is not a hold - it says whose the seat is whenever
     * that screen asks, never that it is showing anything - so a dark
     * reserved screen holds nothing, and whoever stands in for it is who is
     * named here. That is what the feed grant on the roles sheet and the
     * operator's popup need: the screen that shows the picture.
     */
    public static function monitorHolder(array $card): ?string
    {
        if (!Caps::apcu()) {
            return null;
        }
        $held = apcu_fetch(self::MON . $card['eid'], $ok);
        return $ok && is_string($held) ? $held : null;
    }

    /**
     * Takes or renews the slot for this caller. Renewing is the same call:
     * the monitor asks on its own cadence and the lease follows it, so there
     * is nothing to press at either end.
     *
     * THE RESERVED SCREEN HAS RIGHT OF WAY. Its claim always succeeds and
     * displaces whoever was standing in, who is named in $displaced so the
     * caller can tell them. Anybody else may TAKE the seat only while the
     * reserved screen is away - not heard from within the online window -
     * and keeps renewing a seat they hold until the screen asks again: a
     * stand-in is dropped by the screen returning, not by its owner merely
     * opening the game. So the seat is never empty while somebody wants
     * it, and it is the reserved screen's the moment that screen asks.
     *
     * @return bool false when somebody else holds it
     */
    public static function claimMonitor(array $card, string $id, ?string &$displaced = null): bool
    {
        $displaced = null;
        if (!Caps::apcu()) {
            return true;
        }
        $key = self::MON . $card['eid'];
        if ($card['monitor'] === $id) {
            $was = apcu_fetch($key);
            if (is_string($was) && $was !== $id) {
                $displaced = $was;
            }
            apcu_store($key, $id, self::MON_TTL);
            return true;
        }
        if (apcu_fetch($key) === $id) {
            // Ours already: this is the renewal.
            apcu_store($key, $id, self::MON_TTL);
            return true;
        }
        if ($card['monitor'] !== null
            && Presence::heardWithin([$card['monitor']], FOK_ONLINE_WINDOW)[$card['monitor']]) {
            return false;
        }
        // apcu_add is the test-and-set, the shape TourneyStore uses for the
        // host claim: two screens asking in the same instant both read an
        // empty slot, and only one of them may be told it has it.
        return apcu_add($key, $id, self::MON_TTL);
    }

    /** The slot itself, dropped with the event that offered it. */
    public static function forgetMonitor(string $eid): void
    {
        if (Caps::apcu()) {
            apcu_delete(self::MON . $eid);
        }
    }

    /** Gives the slot up at once, rather than waiting out the lease. */
    public static function releaseMonitor(string $eid, string $id): void
    {
        if (Caps::apcu() && apcu_fetch(self::MON . $eid) === $id) {
            apcu_delete(self::MON . $eid);
        }
    }

    // ---------------------------------------------------------------
    // Writing
    // ---------------------------------------------------------------

    /** A code of $len characters from the shared alphabet. */
    public static function randomCode(int $len): string
    {
        $n = strlen(self::ALPHABET);
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= self::ALPHABET[random_int(0, $n - 1)];
        }
        return $out;
    }

    /**
     * Opens an event. The operator's call; the eid is retried until it is
     * free, which at 31^4 it is on the first try. An eid the operator
     * chose is the one candidate, and a taken one is a refusal.
     * @return array the new card
     */
    public static function create(array $f): array
    {
        $db = Db::get();
        $now = time();
        $custom = self::isEid($f['eid'] ?? null) ? (string)$f['eid'] : null;
        $eid = '';
        for ($try = 0; $try < ($custom === null ? 20 : 1); $try++) {
            $cand = $custom ?? self::randomCode(self::EID_LEN);
            $st = $db->prepare('SELECT 1 FROM events WHERE eid = ?');
            $st->execute([$cand]);
            $taken = (bool)$st->fetchColumn();
            $st->closeCursor();
            if (!$taken) {
                $eid = $cand;
                break;
            }
        }
        if ($eid === '') {
            throw new RuntimeException($custom === null ? 'no free event id' : 'eid taken');
        }
        // The key IS the lookup now, so it has to be unique. At 2^54 a
        // collision is not going to happen; being sure costs one SELECT.
        $key = '';
        for ($try = 0; $try < 20; $try++) {
            $cand = self::randomCode(self::KEY_LEN);
            $st = $db->prepare('SELECT 1 FROM events WHERE ekey = ?');
            $st->execute([$cand]);
            $taken = (bool)$st->fetchColumn();
            $st->closeCursor();
            if (!$taken) {
                $key = $cand;
                break;
            }
        }
        if ($key === '') {
            throw new RuntimeException('no free event key');
        }
        $row = [
            'eid' => $eid,
            'name' => self::clip((string)($f['name'] ?? ''), self::MAX_NAME),
            'descr' => self::clip((string)($f['descr'] ?? ''), self::MAX_DESCR),
            'organizer' => isset($f['organizer']) && Util::isValidId($f['organizer'])
                ? (string)$f['organizer'] : null,
            'ekey' => $key,
            'secret' => bin2hex(random_bytes(32)),
            'closed' => !empty($f['closed']) ? 1 : 0,
            'starts' => isset($f['starts']) && $f['starts'] !== null ? (int)$f['starts'] : null,
            'ends' => isset($f['ends']) && $f['ends'] !== null ? (int)$f['ends'] : null,
            'mode' => in_array($f['mode'] ?? '', ['upcoming', 'active'], true)
                ? (string)$f['mode'] : 'upcoming',
            'ach_name' => isset($f['ach_name']) && $f['ach_name'] !== null
                ? self::clip((string)$f['ach_name'], FOK_MAX_NAME_LEN) : null,
            'ach_desc' => isset($f['ach_desc']) && $f['ach_desc'] !== null
                ? self::clip((string)$f['ach_desc'], 40) : null,
            'ach_icon' => isset($f['ach_icon']) && $f['ach_icon'] !== null
                ? (string)$f['ach_icon'] : null,
            // Offering a monitor is the DEFAULT: a screen that shows what is
            // happening costs the event nothing and is what a room wants.
            'monitor_allowed' => array_key_exists('monitor_allowed', $f)
                && empty($f['monitor_allowed']) ? 0 : 1,
            'monitor' => isset($f['monitor']) && Util::isValidId($f['monitor'])
                ? (string)$f['monitor'] : null,
            'created' => $now,
            'ended_at' => null,
        ];
        Db::retry(static function () use ($db, $row): void {
            $st = $db->prepare('INSERT INTO events
                (eid, name, descr, organizer, ekey, secret, closed, starts, ends,
                 mode, ach_name, ach_desc, ach_icon, monitor_allowed, monitor,
                 created, ended_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $st->execute([
                $row['eid'], $row['name'], $row['descr'], $row['organizer'],
                $row['ekey'], $row['secret'], $row['closed'], $row['starts'],
                $row['ends'], $row['mode'], $row['ach_name'], $row['ach_desc'],
                $row['ach_icon'], $row['monitor_allowed'], $row['monitor'],
                $row['created'], $row['ended_at'],
            ]);
        });
        self::forgetCard($eid);
        // Both named people are PRE-SUBSCRIBED at creation, exactly as they
        // are when named later (setOrganizer / setMonitorId): naming is
        // granting access, and the events list is how a client learns it.
        if ($row['organizer'] !== null) {
            self::setMember($eid, (string)$row['organizer'], 'member', 'admin');
        }
        if ($row['monitor'] !== null) {
            self::setMember($eid, (string)$row['monitor'], 'monitor', 'admin');
        }
        return self::card($eid) ?? [];
    }

    /**
     * Changes an event. Only the named fields move; the key is never one of
     * them - a new key is a new event.
     */
    public static function edit(string $eid, array $f): void
    {
        $sets = [];
        $args = [];
        $text = ['name' => self::MAX_NAME, 'descr' => self::MAX_DESCR,
                 'ach_name' => FOK_MAX_NAME_LEN, 'ach_desc' => 40];
        foreach ($text as $col => $max) {
            if (array_key_exists($col, $f)) {
                $sets[] = "$col = ?";
                $args[] = $f[$col] === null ? null : self::clip((string)$f[$col], $max);
            }
        }
        foreach (['starts', 'ends'] as $col) {
            if (array_key_exists($col, $f)) {
                $sets[] = "$col = ?";
                $args[] = $f[$col] === null || $f[$col] === '' ? null : (int)$f[$col];
            }
        }
        if (array_key_exists('ach_icon', $f)) {
            $sets[] = 'ach_icon = ?';
            $args[] = $f['ach_icon'] === null ? null : (string)$f['ach_icon'];
        }
        if (array_key_exists('closed', $f)) {
            $sets[] = 'closed = ?';
            $args[] = !empty($f['closed']) ? 1 : 0;
        }
        if (array_key_exists('monitor_allowed', $f)) {
            $sets[] = 'monitor_allowed = ?';
            $args[] = !empty($f['monitor_allowed']) ? 1 : 0;
        }
        if (array_key_exists('monitor', $f)) {
            $sets[] = 'monitor = ?';
            $args[] = $f['monitor'] === null || $f['monitor'] === ''
                ? null : (string)$f['monitor'];
        }
        if (array_key_exists('organizer', $f)) {
            $sets[] = 'organizer = ?';
            $args[] = $f['organizer'] === null || $f['organizer'] === ''
                ? null : (string)$f['organizer'];
        }
        if (!$sets) {
            return;
        }
        $args[] = $eid;
        Db::retry(static function () use ($sets, $args): void {
            Db::get()->prepare('UPDATE events SET ' . implode(', ', $sets)
                . ' WHERE eid = ?')->execute($args);
        });
        self::forgetCard($eid);
    }

    /**
     * Gives an event a new eid. Every row that names it moves in ONE
     * transaction, and the check that the new one is free runs under the
     * same lock, so two operators cannot both take it; false says it was
     * taken. WHETHER the event may move is the caller's question - once
     * it has started the eid is in its players' hands - this only makes
     * sure nothing is left pointing at the old one: the rows, and the
     * caches of the event and of everybody in it.
     */
    public static function rename(string $eid, string $to): bool
    {
        $ids = [];
        $moved = (bool)Db::retry(static function () use ($eid, $to, &$ids): bool {
            $db = Db::get();
            $db->exec('BEGIN IMMEDIATE');
            try {
                $st = $db->prepare('SELECT 1 FROM events WHERE eid = ?');
                $st->execute([$to]);
                $taken = (bool)$st->fetchColumn();
                $st->closeCursor();
                if ($taken) {
                    $db->exec('ROLLBACK');
                    return false;
                }
                $st = $db->prepare('SELECT id FROM event_members WHERE eid = ?');
                $st->execute([$eid]);
                $ids = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
                $st->closeCursor();
                foreach (['events', 'event_members', 'event_results'] as $table) {
                    $db->prepare("UPDATE $table SET eid = ? WHERE eid = ?")
                        ->execute([$to, $eid]);
                }
                $db->exec('COMMIT');
                return true;
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->exec('ROLLBACK');
                }
                throw $e;
            }
        });
        if (!$moved) {
            return false;
        }
        self::forgetCard($eid);
        self::forgetCard($to);
        self::forgetCounts($eid);
        self::forgetMonitor($eid);
        foreach ($ids as $id) {
            self::forgetMine($id);
        }
        return true;
    }

    /**
     * run / pause / end. An ended event never leaves that state by this
     * path - reopen() is the operator's way back and the only one - and the
     * moment it ended is recorded because the schedule cannot say it.
     */
    public static function setMode(string $eid, string $mode): void
    {
        $ended = $mode === 'ended' ? time() : null;
        Db::retry(static function () use ($eid, $mode, $ended): void {
            Db::get()->prepare(
                "UPDATE events SET mode = ?, ended_at = ? WHERE eid = ? AND mode != 'ended'"
            )->execute([$mode, $ended, $eid]);
        });
        self::forgetCard($eid);
    }

    /**
     * The way back out of `ended`, and only the operator has it: the event
     * is opened again for everyone. Mode goes to active, which an
     * unscheduled event runs on and a scheduled one ignores in favour of
     * its stamps - and an end that has already passed is cleared, because
     * left in place it would end the event again on the very next read.
     * The moment it ended is forgotten with it.
     */
    public static function reopen(string $eid, ?int $now = null): void
    {
        $now ??= time();
        $card = self::card($eid);
        if ($card === null) {
            return;
        }
        $clear = $card['ends'] !== null && (int)$card['ends'] <= $now;
        Db::retry(static function () use ($eid, $clear): void {
            Db::get()->prepare('UPDATE events SET mode = ?, ended_at = NULL'
                . ($clear ? ', ends = NULL' : '') . ' WHERE eid = ?')
                ->execute(['active', $eid]);
        });
        self::forgetCard($eid);
    }

    public static function setClosed(string $eid, bool $closed): void
    {
        self::edit($eid, ['closed' => $closed]);
    }

    /**
     * Names the organizer, and SEATS them.
     *
     * An organizer is in its own event by definition: the `events` list on
     * hello is how any client learns it is in one at all, and an announce
     * of the event's own lobby is read against the same rows - so an
     * organizer with no row could not see the event it runs, nor the
     * tournament it just opened. It never scans anything, so nothing else
     * would ever give it one.
     */
    public static function setOrganizer(string $eid, ?string $id): void
    {
        self::edit($eid, ['organizer' => $id]);
        if ($id === null) {
            return;
        }
        $row = self::rowOf($eid, $id);
        // A banned or monitor row is left exactly as it is: naming an
        // organizer is not the place to overrule either of those.
        if ($row === null) {
            self::setMember($eid, $id, 'member', 'admin');
        } elseif ($row['state'] === 'pending') {
            self::setMember($eid, $id, 'member', 'admin');
        }
    }

    /**
     * Names the screen an event reserves its monitor slot for, or clears it.
     *
     * The ROW follows the column, because the two are one fact: naming a
     * screen PRE-SUBSCRIBES it - it has access from that moment, whether or
     * not it ever scans - a member named as the monitor stops being a
     * participant, and the screen it replaces goes back to being an ordinary
     * member rather than silently losing its place. The same rule the
     * organizer follows (see setOrganizer).
     */
    public static function setMonitorId(string $eid, ?string $id): void
    {
        $was = self::card($eid)['monitor'] ?? null;
        self::edit($eid, ['monitor' => $id]);
        if ($was !== null && $was !== $id) {
            self::demoteMonitor($eid, $was);
        }
        if ($id !== null) {
            // PRE-SUBSCRIBED, exactly like the organizer: naming a screen is
            // granting it access, so it has its row from that moment whether
            // or not it ever scans anything. A member named as the monitor
            // stops being a participant in the same write.
            self::setMember($eid, $id, 'monitor', 'admin');
        }
    }

    /** A screen that is no longer the monitor is an ordinary member again. */
    private static function demoteMonitor(string $eid, string $id): void
    {
        $row = self::rowOf($eid, $id);
        if ($row !== null && $row['state'] === 'monitor') {
            self::setMember($eid, $id, 'member', 'admin');
        }
    }

    /**
     * The ONE path behind the organizer's `roster` verb and the operator's
     * event_roster action, so the two can never drift apart.
     *
     * 'none' DELETES the row - a decline, a removal and lifting a ban are
     * one thing: the person is not in the event and may scan again.
     *
     * @param string $set 'member', 'monitor', 'none' or 'banned'
     * @return bool whether a row changed
     */
    public static function setMember(string $eid, string $id, string $set,
                                     string $via = 'admin'): bool
    {
        $now = time();
        $changed = (bool)Db::retry(static function () use ($eid, $id, $set, $via, $now): bool {
            $db = Db::get();
            // One transaction: the read decides and the write follows it
            // under the same lock, so the organizer's roster verb and the
            // operator's dashboard editing one row in the same instant
            // cannot both find no row and collide on the key.
            $db->exec('BEGIN IMMEDIATE');
            try {
                if ($set === 'none') {
                    $st = $db->prepare('DELETE FROM event_members WHERE eid = ? AND id = ?');
                    $st->execute([$eid, $id]);
                    $changed = $st->rowCount() > 0;
                } else {
                    $st = $db->prepare('SELECT state FROM event_members WHERE eid = ? AND id = ?');
                    $st->execute([$eid, $id]);
                    $was = $st->fetchColumn();
                    $st->closeCursor();
                    if ($was === false) {
                        // Only an operator reaches this: the organizer
                        // approves and never adds, so a peer with no row is
                        // refused before here.
                        $db->prepare('INSERT INTO event_members (eid, id, state, asked, joined, via)
                                      VALUES (?,?,?,?,?,?)')
                           ->execute([$eid, $id, $set, $now, $set === 'member' ? $now : null, $via]);
                        $changed = true;
                    } elseif ((string)$was === $set) {
                        $changed = false;
                    } else {
                        // joined is stamped when the row BECOMES a member and
                        // never moved after: it is when this person got in.
                        $db->prepare('UPDATE event_members SET state = ?,
                                      joined = CASE WHEN ? = ? THEN COALESCE(joined, ?) ELSE joined END
                                      WHERE eid = ? AND id = ?')
                           ->execute([$set, $set, 'member', $now, $eid, $id]);
                        $changed = true;
                    }
                }
                $db->exec('COMMIT');
                return $changed;
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->exec('ROLLBACK');
                }
                throw $e;
            }
        });
        if ($changed) {
            self::forgetMine($id);
            self::forgetCounts($eid);
            // A row that stops being the monitor stops being the RESERVED
            // one too. setMonitorId keeps the column and the row together
            // in the other direction; without this the column can outlive
            // the row it named, and then monitorHolder answers somebody the
            // monitor action itself refuses - a slot nobody at all can take.
            if ($set !== 'monitor' && (self::card($eid)['monitor'] ?? null) === $id) {
                self::edit($eid, ['monitor' => null]);
            }
        }
        return $changed;
    }

    /**
     * A scan. Returns the row state the caller now has: 'member' when the
     * door is open, 'pending' when it is closed. Idempotent - a repeat scan
     * answers what the first did, so a client that lost the response simply
     * asks again.
     */
    public static function admit(string $eid, string $id, bool $closed, string $via,
                                 bool $monitor = false): string
    {
        $now = time();
        $state = $closed ? 'pending' : 'member';
        if ($monitor) {
            // The operator named this screen when the event was set up, so
            // there is nobody left to approve it and no door for it to wait
            // at. It joins as what it is.
            $state = 'monitor';
        }
        $got = (string)Db::retry(static function () use ($eid, $id, $state, $via, $now): string {
            $db = Db::get();
            $read = static function () use ($db, $eid, $id): string|false {
                $st = $db->prepare('SELECT state FROM event_members WHERE eid = ? AND id = ?');
                $st->execute([$eid, $id]);
                $was = $st->fetchColumn();
                $st->closeCursor();
                return $was === false ? false : (string)$was;
            };
            $was = $read();
            if ($was !== false) {
                return $was;
            }
            // The INSERT arbitrates, not the read above it: two scans of one
            // code by one client in the same instant both find no row, and
            // only one of them may create it. ON CONFLICT DO NOTHING is that
            // test in one statement, with no lock spanning a read - and the
            // one that lost answers what the winner wrote, exactly as a
            // repeat scan does.
            $st = $db->prepare('INSERT INTO event_members (eid, id, state, asked, joined, via)
                                VALUES (?,?,?,?,?,?) ON CONFLICT (eid, id) DO NOTHING');
            $st->execute([$eid, $id, $state, $now, $state === 'pending' ? null : $now, $via]);
            if ($st->rowCount() > 0) {
                return $state;
            }
            $was = $read();
            return $was === false ? $state : $was;
        });
        self::forgetMine($id);
        self::forgetCounts($eid);
        return $got;
    }

    /** Every row of one player, at expiry. */
    public static function forgetPlayer(string $id): void
    {
        $eids = [];
        foreach (self::mine($id) as $row) {
            $eids[] = $row['eid'];
        }
        if (!$eids) {
            self::forgetMine($id);
            return;
        }
        Db::retry(static function () use ($id): void {
            Db::get()->prepare('DELETE FROM event_members WHERE id = ?')->execute([$id]);
        });
        self::forgetMine($id);
        foreach ($eids as $eid) {
            self::forgetCounts($eid);
        }
    }

    /**
     * What a finished tournament leaves behind on its event. Written even
     * when the event has ENDED: the match began while it was live, and this
     * is the only record the evening leaves.
     */
    public static function archive(string $eid, array $r): void
    {
        Db::retry(static function () use ($eid, $r): void {
            Db::get()->prepare('INSERT INTO event_results
                (eid, tid, host, started, finished, seats, played, podium, standings)
                VALUES (?,?,?,?,?,?,?,?,?)')->execute([
                    $eid,
                    (string)$r['tid'],
                    (string)($r['host'] ?? ''),
                    (int)($r['started'] ?? 0),
                    (int)($r['finished'] ?? time()),
                    (int)($r['seats'] ?? 0),
                    (int)($r['played'] ?? 0),
                    json_encode($r['podium'] ?? [], JSON_UNESCAPED_SLASHES),
                    json_encode($r['standings'] ?? [], JSON_UNESCAPED_SLASHES),
                ]);
        });
    }

    /**
     * The event's past tournaments, newest first.
     * @return list<array<string, mixed>>
     */
    public static function archiveOf(string $eid, int $limit = 20): array
    {
        $st = Db::get()->prepare('SELECT tid, host, started, finished, seats, played,
                                  podium, standings FROM event_results
                                  WHERE eid = ? ORDER BY finished DESC LIMIT ?');
        $st->execute([$eid, $limit]);
        $out = [];
        foreach ($st->fetchAll() as $row) {
            $out[] = [
                'tid' => (string)$row['tid'],
                'host' => (string)$row['host'],
                'started' => (int)$row['started'],
                'finished' => (int)$row['finished'],
                'seats' => (int)$row['seats'],
                'played' => (int)$row['played'],
                'podium' => json_decode((string)$row['podium'], true) ?: [],
                'standings' => json_decode((string)$row['standings'], true) ?: [],
            ];
        }
        $st->closeCursor();
        return $out;
    }

    // ---------------------------------------------------------------
    // Projections
    // ---------------------------------------------------------------

    /**
     * The public face: what a PENDING row - and nothing less than a row -
     * is allowed to see. No count, no members, no tournaments, no archive,
     * no achievement.
     */
    public static function publicFace(array $card, ?int $now = null): array
    {
        $now ??= time();
        return [
            'eid' => $card['eid'],
            'name' => $card['name'],
            'descr' => $card['descr'],
            'organizer' => $card['organizer'],
            'closed' => $card['closed'],
            'state' => self::stateOf($card, $now),
            'starts' => $card['starts'] === null ? null : $card['starts'] * 1000,
            'ends' => $card['ends'] === null ? null : $card['ends'] * 1000,
            // Whether the event offers a screen at all. A property of the
            // event like the door, and it rides every answer BECAUSE the
            // question must be askable without answering it: the monitor
            // call takes the lease, so it cannot be how a client finds out.
            'monitor_allowed' => $card['monitor_allowed'],
        ];
    }

    /**
     * The achievement joining grants. It rides the join answer and every
     * member's state answer, so a reinstalled client re-grants it silently
     * - the server records nothing about having given it out, because
     * being a member IS the record.
     */
    public static function ach(array $card): ?array
    {
        if ($card['ach_name'] === null || $card['ach_name'] === '') {
            return null;
        }
        $out = [
            'id' => 'ev_' . $card['eid'],
            'name' => $card['ach_name'],
            'desc' => (string)($card['ach_desc'] ?? ''),
        ];
        if ($card['ach_icon'] !== null && $card['ach_icon'] !== '') {
            $icon = json_decode($card['ach_icon'], true);
            if (is_array($icon)) {
                $out['icon'] = $icon;
            }
        }
        return $out;
    }

    private static function clip(string $s, int $max): string
    {
        $s = trim($s);
        return mb_substr($s, 0, $max);
    }
}
