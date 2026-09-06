<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Caps.php';
require_once __DIR__ . '/Matchmaking.php';
require_once __DIR__ . '/Relay.php';

/**
 * Per-client state of the current 1:1 connection, one entry per player.
 * Inferred from traffic the server relays anyway (signal handshake, duel
 * heartbeat, relay messages), so clients report nothing for it.
 *
 * States: inviting, invited, connecting, playing, plus the terminal
 * declined / ended that linger briefly on the Duels card (see listDuels).
 * mode is 'p2p' or 'relay'; relay is never downgraded within a duel, the
 * no-P2P bit counts from either side.
 *
 * The state lives in shared memory rather than in a table because it is
 * liveness with a TTL of seconds, written twice per signaling message and
 * twice per duel heartbeat: the busiest writer the single SQLite writer
 * carried, in service of nothing but two admin cards. There is no database
 * transport and no fallback, exactly as for the signal mailbox and the
 * relay hub (see Signals, RelayStore) - a host with no usable APCu shows an
 * empty Duels card rather than a stale one.
 *
 * Presence - every online client, dueling or not - is a separate list,
 * listPresence, and stays on the players table: presence is durable, has no
 * TTL of its own and is written by the hello the client already sends.
 */
final class ConnTrack
{
    /** One entry per client; the value shape is documented on stateOf. */
    private const PREFIX = FOK_APCU_NS . 'conn:';

    /**
     * How long an untouched entry survives. It has to outlast every window
     * an entry is read over - FOK_CONN_TTL + FOK_DUEL_LINGER for the cards,
     * FOK_RELAY_WINDOW for the relay slot - so that expiry only ever drops
     * an entry no reader would have shown anything for. Every write
     * refreshes it, so a live duel never reaches it.
     */
    public const TTL = FOK_RELAY_WINDOW > FOK_CONN_TTL + FOK_DUEL_LINGER
        ? FOK_RELAY_WINDOW : FOK_CONN_TTL + FOK_DUEL_LINGER;

    /** Signal type => [sender state, recipient state, mode]. */
    private const BY_TYPE = [
        'invite' => ['inviting', 'invited', 'p2p'],
        'invite-relay' => ['inviting', 'invited', 'relay'],
        'accept' => ['connecting', 'connecting', 'p2p'],
        'accept-relay' => ['connecting', 'connecting', 'relay'],
        'offer' => ['connecting', 'connecting', 'p2p'],
        'answer' => ['connecting', 'connecting', 'p2p'],
        'ice' => ['connecting', 'connecting', 'p2p'],
        'ices' => ['connecting', 'connecting', 'p2p'],
        // decline is special-cased in note() (it leaves a 'declined' entry);
        // bye ends the pairing for both sides.
        'decline' => [null, null, null],
        'bye' => [null, null, null],
    ];

    /** What a signaling message means for both endpoints. */
    public static function note(string $from, string $to, string $type): void
    {
        if (!isset(self::BY_TYPE[$type])) {
            return;
        }
        if ($type === 'decline') {
            // Keep the rejection visible: the decliner holds a short-lived
            // 'declined' entry naming who it turned down, so the Duels card
            // shows the decline and who made it; the inviter returns to idle.
            self::set($from, $to, 'declined', null);
            self::clear($to, $from);
            return;
        }
        if ($type === 'bye') {
            // A clean teardown does not wipe the pair: both sides keep a
            // short-lived 'ended' entry so the duel lingers on the Duels card
            // for FOK_DUEL_LINGER seconds instead of blinking out.
            self::end($from, $to);
            return;
        }
        [$mine, $theirs, $mode] = self::BY_TYPE[$type];
        if ($mine === null) {
            self::clear($from, $to);
            self::clear($to, $from);
            return;
        }
        self::set($from, $to, $mine, $mode);
        self::set($to, $from, $theirs, $mode);
    }

    /** The duel heartbeat: the 1:1 game is running. Keeps the pair's mode. */
    public static function playing(string $a, string $b): void
    {
        self::set($a, $b, 'playing', null);
        self::set($b, $a, 'playing', null);
    }

    /**
     * A clean teardown (bye): both sides keep a short-lived 'ended' entry so
     * the duel lingers on the Duels card for FOK_DUEL_LINGER seconds, and
     * the relay slot is freed at once (relay_seen = 0) so a byed relay duel
     * does not hold the cap for the whole relay window. Touches only entries
     * that are actually THIS pairing (same guard as clear): a stranger's
     * bye must not end a duel it has nothing to do with.
     */
    public static function end(string $a, string $b): void
    {
        self::markEnded($a, $b);
        self::markEnded($b, $a);
    }

    private static function markEnded(string $id, string $peer): void
    {
        $cur = self::stateOf($id);
        if ($cur === null || $cur['peer'] !== $peer) {
            return;
        }
        $cur['state'] = 'ended';
        $cur['updated'] = time();
        $cur['relay_seen'] = 0;
        self::store($id, $cur);
        // An entry of this pairing really did end, so the slot it held is
        // gone - and the throttle that guards it must go with it, or the
        // pair's next relayed duel would not re-mark the entry and would
        // hold no slot at all (see Relay::slotFreed).
        Relay::slotFreed($id, $peer);
    }

    // The relay slot accounting lives on the Relay facade, not here, so the
    // whole relay fallback deletes with that file (docs/DEPRECATED-relay.md).
    // The only relay references left in this class, each a one-token removal:
    // markEnded zeroes relay_seen inside the bye write and tells the facade
    // the slot is gone (freeing a byed duel's slot at once, marked above),
    // TTL is held up by FOK_RELAY_WINDOW, the entry shape carries relay_seen
    // for the facade to stamp, and set()/BY_TYPE understand the 'relay'
    // connection mode.

    /**
     * The raw tracked-connection entry for one client (admin detail view,
     * and the relay's slot record), or null if it holds no duel state - and
     * null on a host with no usable APCu, where there is nothing to read.
     * Callers render the linger/ended semantics themselves (see listDuels).
     * @return array{peer:?string,state:string,mode:?string,updated:int,relay_seen:int}|null
     */
    public static function stateOf(string $id): ?array
    {
        if (!Caps::apcu()) {
            return null;
        }
        $e = apcu_fetch(self::key($id));
        return is_array($e) ? $e : null;
    }

    /**
     * The key one client's entry lives under. Public because the relay stamps
     * its slot onto that entry and counts entries itself, so the whole relay
     * mechanism stays inside its own facade and deletes with it (see Relay).
     */
    public static function key(string $id): string
    {
        return self::PREFIX . $id;
    }

    /**
     * Every tracked entry, keyed by client id. The scan only ever covers
     * clients in a duel phase - the TTL is seconds and an idle client holds
     * no entry - and only the admin cards, forget() and the relay's pair
     * count ask for it. An id of nothing but digits is a valid id and PHP
     * makes it an INTEGER array key, so a caller that passes a key on as an
     * id casts it back.
     * @return array<string,array{peer:?string,state:string,mode:?string,updated:int,relay_seen:int}>
     */
    public static function entries(): array
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

    /** Drops a player's tracked connection (expiry, admin delete). */
    public static function forget(string $id): void
    {
        if (!Caps::apcu()) {
            return;
        }
        apcu_delete(self::key($id));
        // The peer side names this client, and left alone it would keep
        // showing a duel with someone the server has forgotten.
        foreach (self::entries() as $other => $e) {
            if ($e['peer'] === $id) {
                apcu_delete(self::key((string)$other));
            }
        }
    }

    /**
     * Presence for the Connections card: every client that is here, newest
     * first, with a short tail so one that just dropped stays visible
     * (gone=true) for FOK_DUEL_LINGER seconds. Clients in a 1:1 are listed
     * here too - presence is the full picture; the Duels card (listDuels)
     * additionally breaks out those in a duel phase.
     * @return array [{id, name, ip, latency, last_seen, gone}]
     */
    public static function listPresence(int $limit = 200): array
    {
        $db = Db::get();
        $now = time();
        $st = $db->prepare(
            'SELECT id, name, ip, latency, last_seen
               FROM players
              WHERE last_seen > ?
              ORDER BY last_seen DESC LIMIT ' . $limit
        );
        $st->execute([$now - FOK_ONLINE_WINDOW - FOK_DUEL_LINGER]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[] = [
                'id' => $r['id'],
                'name' => $r['name'],
                'ip' => $r['ip'],
                'latency' => $r['latency'] === null ? null : (int)$r['latency'],
                'last_seen' => (int)$r['last_seen'],
                'gone' => (int)$r['last_seen'] < $now - FOK_ONLINE_WINDOW,
            ];
        }
        $st->closeCursor();
        return $out;
    }

    /**
     * The 1:1 Duels card: one row per client in a duel phase - inferred
     * from the entry the signal handshake, duel heartbeat and relay write
     * leave - plus quick-match seekers with no peer yet. A live phase shows
     * while the entry is fresh (FOK_CONN_TTL); a clean bye or decline leaves
     * a terminal entry that lingers exactly FOK_DUEL_LINGER seconds, and a
     * duel that simply goes quiet is shown as 'ended' for the same tail - so
     * nothing blinks out mid-glance.
     * @return array [{id, name, peer, state, mode, latency, msgs, since}]
     */
    public static function listDuels(int $limit = 200): array
    {
        $now = time();
        $live = [];
        foreach (self::entries() as $id => $e) {
            if ($e['peer'] === null || $e['updated'] <= $now - FOK_CONN_TTL - FOK_DUEL_LINGER) {
                continue;
            }
            $age = $now - $e['updated'];
            if ($e['state'] === 'ended' || $e['state'] === 'declined') {
                // A clean teardown or a rejection: keep it exactly
                // FOK_DUEL_LINGER seconds, then let it go.
                if ($age > FOK_DUEL_LINGER) {
                    continue;
                }
            } elseif ($age > FOK_CONN_TTL) {
                // A live phase that stopped refreshing (no bye reached us):
                // treat the stale entry as ended and give it the same tail.
                $e['state'] = 'ended';
            }
            $live[$id] = $e;
        }
        uasort($live, static fn(array $x, array $y): int => $y['updated'] <=> $x['updated']);
        $players = self::players(array_keys($live));
        $out = [];
        $seen = [];
        foreach ($live as $id => $e) {
            // An all-digit id comes back as an integer key (see entries()),
            // and everything below hands it on as an id.
            $id = (string)$id;
            if (!isset($players[$id])) {
                // Nothing to name it with - which is what the JOIN this list
                // replaced did with an entry whose player row is gone.
                continue;
            }
            $seen[$id] = true;
            $out[] = [
                'id' => $id,
                'name' => $players[$id]['name'],
                'peer' => $e['peer'],
                'state' => $e['state'],
                'mode' => $e['mode'],
                'latency' => $players[$id]['latency'],
                'msgs' => Relay::msgsFor($id),
                'since' => $e['updated'],
            ];
        }
        $out = array_slice($out, 0, $limit);
        // Quick-match seekers with no peer yet: half a duel, shown the
        // instant they start looking. Only seekers that are still polling
        // (Matchmaking::seekers) - without that filter one that quietly left
        // would linger on the card as "matchmaking" forever.
        $seekers = array_diff_key(Matchmaking::seekers(), $seen);
        foreach (self::players(array_keys($seekers)) as $sid => $p) {
            $since = $seekers[$sid];
            $sid = (string)$sid;
            $out[] = [
                'id' => $sid,
                'name' => $p['name'],
                'peer' => null,
                'state' => 'matchmaking',
                'mode' => null,
                'latency' => $p['latency'],
                'msgs' => 0,
                'since' => $since,
            ];
        }
        return $out;
    }

    /**
     * Name and latency for the clients on the card, in one statement rather
     * than a lookup per entry. An id with no player row comes back absent.
     * @param list<string> $ids
     * @return array<string,array{name:string,latency:?int}>
     */
    private static function players(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $st = Db::get()->prepare(
            'SELECT id, name, latency FROM players
              WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
        );
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[$r['id']] = [
                'name' => $r['name'],
                'latency' => $r['latency'] === null ? null : (int)$r['latency'],
            ];
        }
        $st->closeCursor();
        return $out;
    }

    /**
     * $mode null keeps whatever the pair already declared (the duel
     * heartbeat does not know the mode); a 'p2p' write never overwrites a
     * standing 'relay' for the same peer, so the no-P2P bit sticks - but
     * only within a live duel: an invite that reopens a just-ended pairing
     * (state ended/declined) starts its mode clean.
     */
    private static function set(string $id, string $peer, string $state, ?string $mode): void
    {
        $cur = self::stateOf($id);
        if ($cur !== null && $cur['peer'] === $peer
            && $cur['state'] !== 'ended' && $cur['state'] !== 'declined'
            && ($mode === null || $cur['mode'] === 'relay')) {
            $mode = $cur['mode'];
        }
        self::store($id, [
            'peer' => $peer,
            'state' => $state,
            'mode' => $mode,
            'updated' => time(),
            // The relay slot is the hub's to give and take (see Relay), never
            // a signaling event's: a declaration must not earn one, and an
            // ordinary handshake message must not hand one back.
            'relay_seen' => $cur === null ? 0 : $cur['relay_seen'],
        ]);
    }

    /**
     * Ends the connection with THIS peer only: bye/decline are not
     * friendship-gated, so a stranger must not be able to wipe the state
     * of a duel it has nothing to do with.
     */
    private static function clear(string $id, string $peer): void
    {
        $cur = self::stateOf($id);
        if ($cur !== null && $cur['peer'] === $peer) {
            apcu_delete(self::key($id));
        }
    }

    /** @param array{peer:?string,state:string,mode:?string,updated:int,relay_seen:int} $entry */
    private static function store(string $id, array $entry): void
    {
        if (Caps::apcu()) {
            apcu_store(self::key($id), $entry, self::TTL);
        }
    }
}
