<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Events.php';
require_once __DIR__ . '/Util.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Signals.php';
require_once __DIR__ . '/ConnTrack.php';
require_once __DIR__ . '/Caps.php';
require_once __DIR__ . '/FriendFeed.php';

/**
 * Who is here. Presence is volatile - it is worth nothing FOK_ONLINE_WINDOW
 * seconds after the last beat - so it lives in APCu shared memory, one
 * entry per player, and the database sees a SESSION, not a heartbeat: one
 * write when a player arrives (touch finds no live entry) and one when the
 * fold finds the entry gone stale (see fold). A rename is written through
 * on the spot - a name is identity, not presence. Every request a client
 * makes is a beat, poll.php included; what a beat costs is one
 * shared-memory store.
 *
 * The entry carries everything that is only true while the player is
 * here: the last beat, the address of the moment, latency, the auto-accept
 * flag, the client's own debug report, the operator's debug wish (cached
 * from the row, so a hello reads no row at all) and the networks the
 * player has been seen on, one per address family (see seenOn). The player
 * ROW is the durable record - first seen, last session, name, the wish,
 * the friend ban - and is read about somebody who is not here.
 *
 * There is no database transport to fall back to, exactly as for the
 * mailbox and the connection tracking (see Signals, ConnTrack): a host
 * without usable APCu answers 503 and raises a perf alert.
 *
 * Keys carry the environment namespace (FOK_APCU_NS): one FPM pool can
 * serve live and staging, and a staging test client must not read as
 * online on live.
 */
final class Presence
{
    /** One entry per player who has been here lately; the shape is on entryOf. */
    private const PREFIX = FOK_APCU_NS . 'p:';

    /** The fold's own rate marker, outside the entry prefix so a scan never sees it. */
    private const FOLD_KEY = FOK_APCU_NS . 'p-fold';

    /**
     * How long an entry outlives its last beat in shared memory. Long on
     * purpose: the entry is what the fold writes back to the row, and the
     * fold runs on the next request, whenever that is - a quiet server must
     * not lose the stamp of the last player to leave. The fold drops what it
     * has written, so on a busy server a stale entry lives for seconds.
     */
    private const KEEP = 86400;

    /** The fold runs at most this often, in one worker (see fold). */
    private const FOLD_EVERY = 30;

    /** How stale an observed network may get before a beat rewrites it. */
    private const NET_REFRESH_AFTER = 60;

    /**
     * Shared-memory slot for the presence counters (see counts()). Handed to
     * every client on every hello, so it is never counted per request.
     */
    private const COUNTS_KEY = FOK_APCU_NS . 'counts';

    /**
     * Records a beat and returns whether the server wants this client in
     * debug mode. $debugActive is what the client REPORTS it is doing; null
     * leaves the entry alone (non-hello endpoints), as does every other
     * optional argument.
     *
     * A beat that finds no live entry opens a session: the one write a
     * player's arrival costs. The row is upserted - an unknown id registers
     * in silence - and the name and the wish ride back on the same
     * RETURNING, into the entry, where every later beat reads them.
     */
    public static function touch(string $id, string $ip, ?int $latency = null, ?string $name = null, ?bool $autoAccept = null, ?bool $debugActive = null): bool
    {
        self::mustHaveApcu();
        $now = time();
        $moved = false;
        $e = self::entryOf($id);
        if ($e === null || (int)$e['seen'] < Util::since(FOK_ONLINE_WINDOW, $now)) {
            // A stale entry the fold has not reached carries the last
            // session's latency; it rides into this write, so a quiet
            // server loses nothing of that session either.
            $row = self::open($id, $ip, $now, $name, $e['lat'] ?? null);
            $e = [
                'seen' => $now,
                'start' => $now,
                'ip' => $ip,
                'lat' => null,
                'name' => $row['name'],
                'accept' => 0,
                'dbg' => false,
                'wish' => (int)$row['debug'] === 1,
                'nets' => [],
                // Coming online is a transition: the moment it happened is
                // what a friend's cursor is compared against (see FriendFeed).
                'chg' => Util::nowMs(),
                'duel' => 0,
                'dpeer' => null,
                'dpriv' => false,
            ];
            $moved = true;
            // Nobody may watch their own first hello report zero online, so
            // an arrival drops the counters cache. The beats that are
            // virtually all the traffic leave it alone.
            self::flushCounts();
        }
        $e['seen'] = $now;
        $e['ip'] = $ip;
        if ($latency !== null) {
            $e['lat'] = $latency;
        }
        if ($name !== null && $name !== $e['name']) {
            // Identity, not presence: a rename reaches the row at once, so
            // everything read about this player off the row - an alert, the
            // admin, an offline friend's roster - agrees with the entry.
            Db::retry(static function () use ($id, $name): void {
                Db::get()->prepare('UPDATE players SET name = ? WHERE id = ?')->execute([$name, $id]);
            });
            $e['name'] = $name;
            $e['chg'] = Util::nowMs();
            $moved = true;
        }
        if ($autoAccept !== null) {
            $e['accept'] = $autoAccept ? $now + FOK_AUTO_ACCEPT_WINDOW + FOK_BEAT_JITTER : 0;
        }
        if ($debugActive !== null) {
            $e['dbg'] = $debugActive;
        }
        self::seenOnEntry($e, $ip, true, $now);
        self::store($id, $e);
        // After the store, never before it: what the announcement wakes is a
        // poll that reads this entry.
        if ($moved) {
            FriendFeed::bump($id, (int)$e['chg']);
        }
        return (bool)$e['wish'];
    }

    /** The session-start write: registers or refreshes the row, once per session. */
    private static function open(string $id, string $ip, int $now, ?string $name, ?int $latency): array
    {
        return Db::retry(static function () use ($id, $ip, $now, $name, $latency): array {
            $st = Db::get()->prepare(
                'INSERT INTO players (id, ip, ipnet, first_seen, last_seen, hello_count, name, latency)
                 VALUES (?, ?, ?, ?, ?, 1, ?, ?)
                 ON CONFLICT (id) DO UPDATE SET ip = excluded.ip, ipnet = excluded.ipnet,
                     last_seen = excluded.last_seen, hello_count = hello_count + 1,
                     name = COALESCE(excluded.name, players.name),
                     latency = COALESCE(excluded.latency, players.latency)
                 RETURNING name, debug'
            );
            $st->execute([$id, $ip, Util::ipNet($ip), $now, $now, $name, $latency]);
            $row = $st->fetch();
            // An INSERT ... RETURNING is a write: finish it before anything
            // else touches the database, this retry included (see Db).
            $st->closeCursor();
            return $row;
        });
    }

    /**
     * The one write a session's END costs. Runs in the deferred tail of a
     * request (see Util::bumpNow), at most every FOLD_EVERY seconds and in
     * one worker: the shared-memory add succeeds for one caller. It walks
     * the entries, and each one whose beat is older than every window that
     * still reads it - the online window, and the announce window while a
     * lobby host is matched on its networks - has its stamp and latency
     * written to the row and is dropped.
     *
     * Both halves are guarded against a player who came back between the
     * scan and the write: the row never moves backwards, and a fresh entry
     * under the same key stays.
     */
    public static function fold(): void
    {
        if (!Caps::apcu() || apcu_add(self::FOLD_KEY, 1, self::FOLD_EVERY) !== true) {
            return;
        }
        $now = time();
        $keep = max(FOK_ONLINE_WINDOW, Settings::int('tournament_announce_window'));
        $cut = Util::since($keep, $now);
        $ended = [];
        foreach (self::all() as $id => $e) {
            if ((int)$e['seen'] < $cut) {
                $ended[(string)$id] = $e;
            }
        }
        if ($ended === []) {
            return;
        }
        Db::retry(static function () use ($ended): void {
            $st = Db::get()->prepare(
                'UPDATE players SET last_seen = ?, latency = ? WHERE id = ? AND last_seen <= ?'
            );
            foreach ($ended as $id => $e) {
                $st->execute([(int)$e['seen'], $e['lat'], $id, (int)$e['seen']]);
            }
        });
        foreach ($ended as $id => $e) {
            $cur = apcu_fetch(self::PREFIX . $id);
            if (is_array($cur) && (int)$cur['seen'] === (int)$e['seen']) {
                apcu_delete(self::PREFIX . $id);
            }
        }
    }

    /** Test-suite only: lifts the fold's rate gate and folds. */
    public static function foldNow(): void
    {
        if (Caps::apcu()) {
            apcu_delete(self::FOLD_KEY);
        }
        self::fold();
    }

    /**
     * Empties the store: the entries, the fold's rate gate and the counts
     * cache. For a restore (see Backup) - the entries describe players in
     * the database that was replaced, and the fold would write their last
     * beat over the rows just brought back.
     */
    public static function dropEntries(): void
    {
        Caps::dropKeys(self::PREFIX);
        Caps::dropKeys(self::FOLD_KEY);
        Caps::dropKeys(self::COUNTS_KEY);
    }

    /**
     * One player's entry, or null when there is none. Shape:
     * {seen, start, ip, lat, name, accept, dbg, wish, chg, duel, dpeer,
     * dpriv, nets:{family:{net, seen, src}}}
     * - seen is the last beat, start the session's first; accept is the
     * moment the auto-accept flag lapses (0 = off); dbg is the client's own
     * report and wish the operator's; chg is the last transition a friend's
     * cursor is compared against; duel/dpeer/dpriv are the spectate offer
     * (see touchDuel); nets is one network per address family with the
     * moment it was seen and whether it was observed ('o') or claimed
     * ('c').
     */
    public static function entryOf(string $id): ?array
    {
        if (!Caps::apcu()) {
            return null;
        }
        $e = apcu_fetch(self::PREFIX . $id);
        return is_array($e) ? $e : null;
    }

    /**
     * The entries of a set of ids, keyed by id, in one fetch. An id with no
     * entry is simply absent. This is the whole cost of a friend delta that
     * has something to report (see FriendFeed).
     * @param list<string> $ids
     * @return array<string, array>
     */
    public static function entriesOf(array $ids): array
    {
        if ($ids === [] || !Caps::apcu()) {
            return [];
        }
        $hit = apcu_fetch(array_map(static fn(string $i): string => self::PREFIX . $i, $ids));
        $out = [];
        if (is_array($hit)) {
            $cut = strlen(self::PREFIX);
            foreach ($hit as $k => $v) {
                if (is_array($v)) {
                    $out[substr((string)$k, $cut)] = $v;
                }
            }
        }
        return $out;
    }

    /**
     * Every entry, keyed by id. A scan, so only the counts (cached), the
     * admin cards and the fold ask for it. An id of nothing but digits is a
     * valid id and PHP makes it an INTEGER array key, so every caller casts
     * the key back before passing it on.
     * @return array<string, array>
     */
    private static function all(): array
    {
        if (!Caps::apcu()) {
            return [];
        }
        $out = [];
        $cut = strlen(self::PREFIX);
        foreach (new APCUIterator('/^' . preg_quote(self::PREFIX, '/') . '/') as $e) {
            if (is_array($e['value'])) {
                $out[substr($e['key'], $cut)] = $e['value'];
            }
        }
        return $out;
    }

    private static function store(string $id, array $e): void
    {
        apcu_store(self::PREFIX . $id, $e, self::KEEP);
    }

    private static function mustHaveApcu(): void
    {
        static $ok = null;
        if ($ok === true) {
            return;
        }
        if ($ok === null) {
            $ok = Caps::apcu();
        }
        if ($ok !== true) {
            Alerts::raise('perf', 'Presence is unavailable: APCu is not usable on this host. '
                . 'Presence has no database transport by design - every player-facing '
                . 'endpoint stays down until shared memory works.');
            Util::fail('service unavailable', 503);
        }
    }

    /**
     * Records a NETWORK this player is on, one per address family (see
     * Util::ipNet).
     *
     * The address of the moment is not the same as the networks the player
     * can be reached on: a dual-stack client picks a family per connection,
     * so the same device answers from a v4 address one minute and out of
     * its v6 /64 the next. Keeping one network per family is what lets two
     * devices in one room match when they did not pick the same family.
     *
     * $observed says whether the server SAW this address (a REMOTE_ADDR,
     * which is evidence) or whether the client reported it about itself (a
     * claim, which is not - see claim()). A claim may not displace an
     * observation that is still doing work, and may not be rewritten faster
     * than an observation would be; that is the whole trust model, and it
     * is here rather than at the caller so no future caller can skip it.
     * NET_REFRESH_AFTER bounds how stale `seen` may get; the announce reads
     * it, so it may not drift.
     */
    public static function seenOn(string $id, string $ip, bool $observed = true): void
    {
        $e = self::entryOf($id);
        if ($e !== null && self::seenOnEntry($e, $ip, $observed, time())) {
            self::store($id, $e);
        }
    }

    /** The rules of seenOn, applied to an entry in hand; true when it changed. */
    private static function seenOnEntry(array &$e, string $ip, bool $observed, int $now): bool
    {
        $info = Util::ipInfo($ip);
        if ($info['family'] === 0) {
            return false;   // nothing we can compare later, so nothing worth storing
        }
        $net = Util::ipNet($ip);
        $src = $observed ? 'o' : 'c';
        $cur = $e['nets'][$info['family']] ?? null;
        if ($cur !== null) {
            $fresh = (int)$cur['seen'] > $now - self::NET_REFRESH_AFTER;
            if ($fresh && (string)$cur['net'] === $net && (string)$cur['src'] === $src) {
                return false;   // nothing would change
            }
            if (!$observed) {
                // What we saw ourselves outranks what we were told, for as
                // long as the announce would still act on it.
                if ((string)$cur['src'] === 'o'
                    && (int)$cur['seen'] > $now - Settings::int('tournament_announce_window')) {
                    return false;
                }
                // And a claim cannot be churned: one write per family per
                // refresh interval, so a client cannot sweep networks by
                // reporting a different one on every heartbeat.
                if ($fresh) {
                    return false;
                }
            }
        }
        $e['nets'][$info['family']] = ['net' => $net, 'seen' => $now, 'src' => $src];
        return true;
    }

    /**
     * Networks the CLIENT reports it is on, at most one per family.
     *
     * The server sees a client on exactly one address family per request -
     * whichever the browser picked - and a browser cannot be told to use
     * the other one, so the second network is unobservable from here. The
     * client can discover it (a STUN reflexive candidate names its public
     * v4 address and its global v6 one), and this is where it hands it over.
     *
     * Everything here is untrusted input: only public addresses count (see
     * Util::isPublicIp - an ICE list is full of link-local and RFC 1918
     * candidates, and two houses sharing 192.168.0.0 are not one room),
     * only the first address of each family is taken, and the whole lot
     * ranks below what the server saw for itself (see seenOn).
     *
     * @param string[] $ips
     */
    public static function claim(string $id, array $ips): void
    {
        $done = [];
        foreach ($ips as $ip) {
            if (!is_string($ip) || !Util::isPublicIp($ip)) {
                continue;
            }
            $family = Util::ipInfo($ip)['family'];
            if (isset($done[$family])) {
                continue;
            }
            $done[$family] = true;
            self::seenOn($id, $ip, false);
        }
    }

    /**
     * Every network the player has been seen on since $since - what "the
     * same line" has to mean for a dual-stack household (see seenOn). The
     * caller folds its own current network in itself.
     * @return string[]
     */
    public static function netsOf(string $id, int $since): array
    {
        $out = [];
        foreach (self::entryOf($id)['nets'] ?? [] as $n) {
            if ((int)$n['seen'] > $since) {
                $out[] = (string)$n['net'];
            }
        }
        return $out;
    }

    /**
     * Which of $ids are present and share one of $nets: the lobby announce
     * (see Tournament::announce). Answered from the entries alone, so
     * announcing never reads a row. An id whose player has never set a name
     * maps to null, and is still perfectly announceable.
     * @param list<string> $ids
     * @param list<string> $nets
     * @return array<string, ?string> id => name
     */
    public static function hostsOn(array $ids, array $nets, int $since): array
    {
        $out = [];
        foreach (self::entriesOf($ids) as $id => $e) {
            if ((int)$e['seen'] <= $since) {
                continue;
            }
            foreach ($e['nets'] as $n) {
                if ((int)$n['seen'] > $since && in_array((string)$n['net'], $nets, true)) {
                    $out[(string)$id] = $e['name'];
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * Test-suite only: backdates a player's beat and its networks (one
     * family, or all of them with the beat), so a test reaches the windows
     * without sleeping.
     */
    public static function age(string $id, int $secs, ?int $family = null): void
    {
        $e = self::entryOf($id);
        if ($e === null) {
            return;
        }
        $t = time() - $secs;
        if ($family === null) {
            $e['seen'] = $t;
        }
        foreach ($e['nets'] as $f => $n) {
            if ($family === null || (int)$f === $family) {
                $e['nets'][$f]['seen'] = $t;
            }
        }
        self::store($id, $e);
    }

    /**
     * Admin-set: what the server WANTS the client to do (see touch). The
     * row is the record and the entry is what the next beat reads - a
     * hello, or a poll that reports its own state - so a player who is
     * here learns of it on that request.
     */
    public static function setDebug(string $id, bool $on): void
    {
        Db::get()->prepare('UPDATE players SET debug = ? WHERE id = ?')->execute([(int)$on, $id]);
        $e = self::entryOf($id);
        if ($e !== null) {
            $e['wish'] = $on;
            self::store($id, $e);
        }
    }

    /** Forces the next counts() to recount (see the caching there). */
    public static function flushCounts(): void
    {
        apcu_delete(self::COUNTS_KEY);
    }

    public static function isAutoAccepting(string $id): bool
    {
        return (int)(self::entryOf($id)['accept'] ?? 0) > time();
    }

    /**
     * The duel heartbeat, on the duels table. Not shared memory: a claim's
     * integrity window reads duels.last_seen (see Items::matchDeadline), so
     * this is the one row write a beat inside a 1vs1 keeps.
     *
     * $private is the player's "make duels private" setting: the duel is
     * real and counts everywhere, but no friend is offered a spectate link
     * for it (see spectateEndsAt).
     */
    public static function touchDuel(string $id, string $peer, bool $private = false): void
    {
        [$a, $b] = $id < $peer ? [$id, $peer] : [$peer, $id];
        $now = time();
        // Both peers of every duel write this on every heartbeat, so it is
        // the most contended write there is. Re-running it is exact:
        // last_seen is set, not accumulated, and nothing is read back.
        Db::retry(static function () use ($a, $b, $now): void {
            Db::get()->prepare(
                'INSERT INTO duels (a, b, started, last_seen) VALUES (?, ?, ?, ?)
                 ON CONFLICT (a, b) DO UPDATE SET last_seen = excluded.last_seen'
            )->execute([$a, $b, $now, $now]);
        });
        // The duel rides the entry as well, because a friend delta may not
        // read the duels table (see FriendFeed). Per player, not per pair:
        // the row belongs to the pair, and each peer's friends have to hear
        // about that peer.
        $e = self::entryOf($id);
        if ($e === null) {
            return;
        }
        $wasLive = (int)($e['duel'] ?? 0) >= Util::since(FOK_DUEL_SEEN_WINDOW, $now);
        $was = self::spectateEndsAt($e) > Util::nowMs();
        $e['duel'] = $now;
        $e['dpeer'] = $peer;
        $e['dpriv'] = $private;
        self::writeSpectate($id, $e, $was);
        // Somebody entering a duel is what the playing figure counts, so the
        // edge drops its cache - a private duel included, being counted like
        // any other. The beats in between leave it alone, which is the whole
        // point of caching it (see population).
        if (!$wasLive) {
            self::flushCounts();
        }
    }

    /**
     * Whether $a and $b have beaten a duel WITH EACH OTHER since $since (unix
     * seconds). The duels ROW, never the presence entries: a client that
     * announces the end of its duel clears its entry (see endDuel), and the
     * question here is whether the two ever got a match going at all, which
     * has to stay answered afterwards.
     */
    public static function duelSeenSince(string $a, string $b, int $since): bool
    {
        [$x, $y] = $a < $b ? [$a, $b] : [$b, $a];
        $st = Db::get()->prepare('SELECT last_seen FROM duels WHERE a = ? AND b = ?');
        $st->execute([$x, $y]);
        $row = $st->fetch();
        $st->closeCursor();
        return $row !== false && (int)$row['last_seen'] >= $since;
    }

    /**
     * The end of a duel, announced rather than waited out: the client says
     * so the moment its session tears down, and the friend's WATCH row goes
     * with it instead of standing until the window lapses.
     *
     * Only the SPECTATE OFFER ends here. The duel row keeps its own window
     * on purpose - a claim legitimately arrives after the last tick, which
     * is what match_open_max_ms is grace for, so tearing the row down at the
     * teardown would close the window on the item the match was played for.
     *
     * $peer is what the caller thinks it is leaving. An end for somebody
     * else is a late announcement overtaken by the next duel, and is
     * dropped: the pairing the entry names is the one that is running.
     */
    public static function endDuel(string $id, string $peer): void
    {
        $e = self::entryOf($id);
        if ($e === null || (int)($e['duel'] ?? 0) === 0) {
            return;
        }
        if (($e['dpeer'] ?? null) !== null && $e['dpeer'] !== $peer) {
            return;
        }
        $wasLive = (int)$e['duel'] >= Util::since(FOK_DUEL_SEEN_WINDOW);
        $was = self::spectateEndsAt($e) > Util::nowMs();
        $e['duel'] = 0;
        $e['dpeer'] = null;
        $e['dpriv'] = false;
        self::writeSpectate($id, $e, $was);
        if ($wasLive) {
            self::flushCounts();
        }
    }

    /**
     * Stores an entry whose spectate offer just moved, and announces it when
     * what a FRIEND can see changed - which is the offer, not the duel. A
     * private duel therefore starts and ends in silence, and toggling the
     * setting mid-match is an ordinary transition rather than a case of its
     * own: all three ask the same question of the entry before and after.
     */
    private static function writeSpectate(string $id, array $e, bool $was): void
    {
        $nowMs = Util::nowMs();
        $is = self::spectateEndsAt($e) > $nowMs;
        if ($was !== $is) {
            $e['chg'] = $nowMs;
        }
        self::store($id, $e);
        // After the store, never before it: what the announcement wakes is a
        // poll that reads this entry.
        if ($was !== $is) {
            FriendFeed::bump($id, $nowMs);
        }
    }

    /**
     * The moment this player stops being offered to friends as spectatable,
     * in ms - 0 when there is nothing to offer. The one place the rule
     * lives, so the delta, the roster and the counters cannot disagree
     * about who may be watched.
     *
     * Two ways to be absent from it: no duel_with within
     * FOK_DUEL_SEEN_WINDOW, and a duel the player marked private. A private
     * duel is still a duel everywhere else - the row, the counters, the
     * pace tier - it is only never attributed to a person.
     *
     * @param array $e a presence entry
     */
    public static function spectateEndsAt(array $e): int
    {
        $at = (int)($e['duel'] ?? 0);
        if ($at <= 0 || !empty($e['dpriv'])) {
            return 0;
        }
        return ($at + FOK_DUEL_SEEN_WINDOW + FOK_BEAT_JITTER) * 1000;
    }

    /**
     * Online / latency / name for a set of ids: the entries answer for
     * whoever is here, the rows for the rest - the name of an offline
     * friend is still a name. An id nobody has ever seen is absent.
     * @param list<string> $ids
     * @return array<string, array{online: bool, latency: ?int, name: ?string}>
     */
    public static function infoOf(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $cut = Util::since(FOK_ONLINE_WINDOW);
        $entries = self::entriesOf($ids);
        $out = [];
        $missing = [];
        foreach ($ids as $id) {
            $e = $entries[$id] ?? null;
            if ($e === null) {
                $missing[] = $id;
                continue;
            }
            $online = (int)$e['seen'] >= $cut;
            $out[$id] = [
                'online' => $online,
                // A latency is only meaningful while the friend is online.
                'latency' => $online && $e['lat'] !== null ? (int)$e['lat'] : null,
                'name' => $e['name'],
            ];
        }
        if ($missing !== []) {
            $ph = implode(',', array_fill(0, count($missing), '?'));
            $st = Db::get()->prepare("SELECT id, name FROM players WHERE id IN ($ph)");
            $st->execute($missing);
            foreach ($st->fetchAll() as $row) {
                $out[(string)$row['id']] = ['online' => false, 'latency' => null, 'name' => $row['name']];
            }
            $st->closeCursor();
        }
        return $out;
    }

    /**
     * Everyone here, newest beat first, for the Connections card - with a
     * short tail so one that just dropped stays visible (gone=true) for
     * FOK_DUEL_LINGER seconds. Clients in a 1vs1 are listed here too;
     * presence is the full picture, and the Duels card breaks out those
     * in a duel phase (see ConnTrack::listDuels).
     * @return list<array{id: string, name: ?string, ip: string, latency: ?int, last_seen: int, gone: bool}>
     */
    public static function recent(int $limit = 200): array
    {
        $now = time();
        $shown = Util::since(FOK_ONLINE_WINDOW + FOK_DUEL_LINGER, $now);
        $online = Util::since(FOK_ONLINE_WINDOW, $now);
        $out = [];
        foreach (self::all() as $id => $e) {
            if ((int)$e['seen'] < $shown) {
                continue;
            }
            $out[] = [
                'id' => (string)$id,
                'name' => $e['name'],
                'ip' => (string)$e['ip'],
                'latency' => $e['lat'] === null ? null : (int)$e['lat'],
                'last_seen' => (int)$e['seen'],
                'gone' => (int)$e['seen'] < $online,
            ];
        }
        usort($out, static fn(array $a, array $b): int => $b['last_seen'] <=> $a['last_seen']);
        return array_slice($out, 0, $limit);
    }

    /**
     * Lays what is true right now over rows read from the players table:
     * the admin lists are read out of the durable record, and for a player
     * who is here the record holds the session, not the moment. Rows keep
     * their keys, so the shape is the caller's; they are re-ordered by the
     * beat afterwards.
     * @param list<array<string, mixed>> $rows each with an 'id'
     * @return list<array<string, mixed>>
     */
    public static function overlay(array $rows): array
    {
        $cut = Util::since(FOK_ONLINE_WINDOW);
        $entries = self::entriesOf(array_map(static fn(array $r): string => (string)$r['id'], $rows));
        foreach ($rows as &$r) {
            $e = $entries[(string)$r['id']] ?? null;
            if ($e === null) {
                continue;
            }
            $r['last_seen'] = (int)$e['seen'];
            $r['ip'] = (string)$e['ip'];
            $r['name'] = $e['name'];
            if (array_key_exists('latency', $r)) {
                $r['latency'] = $e['lat'] === null ? null : (int)$e['lat'];
            }
            if (array_key_exists('debug_active', $r)) {
                $r['debug_active'] = (bool)$e['dbg'];
            }
            if (array_key_exists('accept_until', $r)) {
                $r['accept_until'] = (int)$e['accept'];
            }
            if (array_key_exists('online', $r)) {
                $r['online'] = (int)$e['seen'] >= $cut;
            }
        }
        unset($r);
        usort($rows, static fn(array $a, array $b): int => (int)$b['last_seen'] <=> (int)$a['last_seen']);
        return $rows;
    }

    /**
     * Peer-net hint: at the moment a 1vs1 pairing is confirmed (an accepted
     * invite, a fresh quick match) and BEFORE the P2P handshake, tell each
     * side the other's server-observed IP plus its own, so that two peers on
     * the same address family can try a direct connection first (see the
     * 'peer-net' signal in docs/API.md). Both addresses come from the
     * entries - each side just beat, so both are current - and a side the
     * server has never seen is skipped: nothing to announce.
     */
    public static function announceNet(string $a, string $b): void
    {
        $entries = self::entriesOf([$a, $b]);
        if (!isset($entries[$a], $entries[$b])) {
            return;
        }
        $na = Util::ipInfo((string)$entries[$a]['ip']);
        $nb = Util::ipInfo((string)$entries[$b]['ip']);
        self::sendNet($a, $b, $na, $nb);
        self::sendNet($b, $a, $nb, $na);
    }

    /** Queues one peer-net signal: $to learns $peer's net, plus its own. */
    private static function sendNet(string $to, string $peer, array $selfNet, array $peerNet): void
    {
        Signals::send($peer, $to, 'peer-net', (string)json_encode([
            'event' => 'peer-net',
            'peer' => $peer,
            'ip' => $peerNet['ip'],
            'family' => $peerNet['family'],
            'self_ip' => $selfNet['ip'],
            'self_family' => $selfNet['family'],
        ]));
    }

    /**
     * Removes a player and everything about them that is only PRESENCE: the
     * friendships (each friend gets a best-effort 'friend' {event:"expired"}
     * signal, and one that is offline reconciles its list against friend.php
     * on next start), the entry, the connection state, and the player row
     * itself.
     *
     * What it deliberately leaves is property and history - items, the
     * config vault, the career stats, the scores. An id belongs to the
     * client and comes back with it (touch() re-registers an unknown id in
     * silence), so a player returning after the TTL finds them again. A
     * caller that means to confiscate as well says so where it can be read
     * as a decision (see delete_player in admin/api.php).
     *
     * The single removal path: the TTL sweep and the admin button both come
     * through here, so the two cannot clean up different halves of a player.
     */
    /**
     * The names a set of ids goes by, in ONE query. An id on screen always
     * carries its name; an id with no row answers nothing, which is a real
     * answer (a player can expire and leave what it owned behind).
     *
     * @param list<string> $ids
     * @return array<string, string>
     */
    public static function namesFor(array $ids): array
    {
        $ids = array_values(array_filter(array_unique($ids),
            static fn($v) => is_string($v) && Util::isValidId($v)));
        if ($ids === []) {
            return [];
        }
        $st = Db::get()->prepare('SELECT id, name FROM players WHERE id IN ('
            . implode(', ', array_fill(0, count($ids), '?')) . ')');
        $st->execute($ids);
        $rows = $st->fetchAll();
        $st->closeCursor();
        $names = [];
        foreach ($rows as $r) {
            $names[(string)$r['id']] = (string)$r['name'];
        }
        return $names;
    }
    public static function forget(string $id): void
    {
        $db = Db::get();
        $st = $db->prepare('SELECT a, b FROM friends WHERE a = ? OR b = ?');
        $st->execute([$id, $id]);
        $others = [];
        foreach ($st->fetchAll() as $row) {
            $other = $row['a'] === $id ? $row['b'] : $row['a'];
            $others[] = (string)$other;
            Signals::send($id, $other, 'friend', json_encode(['event' => 'expired', 'from' => $id]));
        }
        $db->prepare('DELETE FROM friends WHERE a = ? OR b = ?')->execute([$id, $id]);
        $db->prepare('DELETE FROM players WHERE id = ?')->execute([$id]);
        // Every event this player was in. The ARCHIVE keeps the id: a
        // finished tournament is the record of an evening, and a name
        // that no longer resolves is a real answer everywhere else here.
        Events::forgetPlayer($id);
        if (Caps::apcu()) {
            apcu_delete(self::PREFIX . $id);
        }
        ConnTrack::forget($id);
        // The friendships went with the player, so every cached list naming
        // it is wrong now (see FriendFeed).
        foreach ($others as $other) {
            FriendFeed::forgetPair($id, $other);
        }
        FriendFeed::forget($id);
        // registered and online are cached (see counts), so the dashboard
        // must not keep showing a player that is gone until the TTL lapses.
        self::flushCounts();
    }

    /**
     * Removes players not seen for player_ttl_days (0 disables expiry). The
     * row's last_seen is the session the fold wrote back, which is as
     * precise as a yearly sweep needs.
     * @return int number of players removed
     */
    public static function expireStale(): int
    {
        $days = Settings::int('player_ttl_days');
        if ($days < 1) {
            return 0;
        }
        $st = Db::get()->prepare('SELECT id FROM players WHERE last_seen < ?');
        $st->execute([time() - $days * 86400]);
        $expired = array_column($st->fetchAll(), 'id');
        foreach ($expired as $id) {
            self::forget((string)$id);
        }
        return count($expired);
    }

    /**
     * Every presence figure the server publishes, cached in shared memory for
     * FOK_COUNTS_TTL seconds and served through counts() and families().
     * Every hello returns some of these, so counting here would make a
     * heartbeat cost more as the player base grows - the one thing that must
     * not happen. Nobody needs an exact count (online is a 120 s window).
     * The recompute is unlocked: racing requests write the same numbers.
     *
     * Online, the family split and playing are one pass over the entries;
     * registered is the one count still taken from a table. Playing counts
     * PRIVATE duels too - hiding one from a friend's roster is not a reason
     * to under-report how busy the server is - so it asks the entry for its
     * duel stamp rather than for the spectate offer built on it.
     */
    private static function population(): array
    {
        $now = time();
        $hit = apcu_fetch(self::COUNTS_KEY, $ok);
        if ($ok && is_array($hit)) {
            return $hit;
        }
        // Below the cache check, not above it: poll.php serves these figures
        // too, and opening the database is the one thing that path does not
        // do (see docs/API.md, Friend presence on the poll).
        $db = Db::get();
        $cut = Util::since(FOK_ONLINE_WINDOW, $now);
        $duelCut = Util::since(FOK_DUEL_SEEN_WINDOW, $now);
        $online = 0;
        $online6 = 0;
        $playing = 0;
        foreach (self::all() as $e) {
            if ((int)$e['seen'] < $cut) {
                continue;
            }
            $online++;
            if ((int)($e['duel'] ?? 0) >= $duelCut) {
                $playing++;
            }
            // A colon is what tells the families apart, bar the v4-mapped
            // form, which is a v4 client.
            $ip = (string)$e['ip'];
            if (str_contains($ip, ':') && !str_starts_with($ip, '::ffff:')) {
                $online6++;
            }
        }
        $registered = (int)$db->query('SELECT COUNT(*) FROM players')->fetchColumn();
        $out = [
            'online' => $online,
            'playing' => $playing,
            'registered' => $registered,
            'online_v6' => $online6,
        ];
        // The TTL is the cache's own, so there is no stored timestamp to
        // compare against and no sweep to run.
        apcu_store(self::COUNTS_KEY, $out, FOK_COUNTS_TTL);
        return $out;
    }

    /**
     * The three figures the landing page shows and every hello carries (see
     * docs/API.md). Named explicitly rather than passed through, so what the
     * cache holds for the dashboard cannot leak into the client contract.
     */
    public static function counts(): array
    {
        $p = self::population();
        return ['online' => $p['online'], 'playing' => $p['playing'], 'registered' => $p['registered']];
    }

    /**
     * Which address family the online clients actually reached us over -
     * the one thing the server can say for certain about a browser's
     * connectivity (see seenOn). Admin-only: it says nothing a client
     * could act on, and the pair always adds up to online.
     */
    public static function families(): array
    {
        $p = self::population();
        $v6 = (int)($p['online_v6'] ?? 0);
        return ['v4' => $p['online'] - $v6, 'v6' => $v6];
    }
}
