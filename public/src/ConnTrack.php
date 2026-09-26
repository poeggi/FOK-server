<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Caps.php';
require_once __DIR__ . '/Matchmaking.php';

/**
 * Per-client state of the current 1vs1 connection, one entry per player.
 * Inferred from traffic the server forwards anyway (signal handshake, duel
 * heartbeat), so clients report nothing for it.
 *
 * States: inviting, invited, connecting, playing, plus the terminal
 * declined / ended that linger briefly on the Duels card (see listDuels).
 *
 * The state lives in shared memory rather than in a table because it is
 * liveness with a TTL of seconds, written twice per signaling message and
 * twice per duel heartbeat: the busiest writer the single SQLite writer
 * carried, in service of nothing but two admin cards. There is no database
 * transport and no fallback, exactly as for the signal mailbox (see
 * Signals) - a host with no usable APCu shows an empty Duels card rather
 * than a stale one.
 *
 * Presence - every online client, dueling or not - is Presence's own list
 * (see Presence::recent); listPresence hands it through for the card.
 */
final class ConnTrack
{
    /** One entry per client; the value shape is documented on stateOf. */
    private const PREFIX = FOK_APCU_NS . 'conn:';

    /**
     * How long an untouched entry survives. It has to outlast the window the
     * cards read an entry over, FOK_CONN_TTL + FOK_DUEL_LINGER, so that
     * expiry only ever drops an entry no reader would have shown anything
     * for. Every write refreshes it, so a live duel never reaches it.
     */
    public const TTL = FOK_CONN_TTL + FOK_DUEL_LINGER;

    /** Signal type => [sender state, recipient state]. */
    private const BY_TYPE = [
        'invite' => ['inviting', 'invited'],
        'accept' => ['connecting', 'connecting'],
        'offer' => ['connecting', 'connecting'],
        'answer' => ['connecting', 'connecting'],
        'ice' => ['connecting', 'connecting'],
        'ices' => ['connecting', 'connecting'],
        // decline is special-cased in note() (it leaves a 'declined' entry);
        // bye ends the pairing for both sides.
        'decline' => [null, null],
        'bye' => [null, null],
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
            self::set($from, $to, 'declined');
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
        [$mine, $theirs] = self::BY_TYPE[$type];
        if ($mine === null) {
            self::clear($from, $to);
            self::clear($to, $from);
            return;
        }
        self::set($from, $to, $mine);
        self::set($to, $from, $theirs);
    }

    /** The duel heartbeat: the 1vs1 game is running. */
    public static function playing(string $a, string $b): void
    {
        self::set($a, $b, 'playing');
        self::set($b, $a, 'playing');
    }

    /**
     * A clean teardown (bye): both sides keep a short-lived 'ended' entry so
     * the duel lingers on the Duels card for FOK_DUEL_LINGER seconds.
     * Touches only entries that are actually THIS pairing (same guard as
     * clear): a stranger's bye must not end a duel it has nothing to do with.
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
        self::store($id, $cur);
    }

    /**
     * The raw tracked-connection entry for one client (admin detail view),
     * or null if it holds no duel state - and null on a host with no usable
     * APCu, where there is nothing to read. Callers render the linger/ended
     * semantics themselves (see listDuels).
     * @return array{peer:?string,state:string,updated:int}|null
     */
    public static function stateOf(string $id): ?array
    {
        if (!Caps::apcu()) {
            return null;
        }
        $e = apcu_fetch(self::key($id));
        return is_array($e) ? $e : null;
    }

    /** The key one client's entry lives under. */
    public static function key(string $id): string
    {
        return self::PREFIX . $id;
    }

    /**
     * Every tracked entry, keyed by client id. The scan only ever covers
     * clients in a duel phase - the TTL is seconds and an idle client holds
     * no entry - and only the admin cards and forget() ask for it. An id of
     * nothing but digits is a valid id and PHP makes it an INTEGER array
     * key, so a caller that passes a key on as an id casts it back.
     * @return array<string,array{peer:?string,state:string,updated:int}>
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

    /** Empties the store; a restore replaced the players it tracks. */
    public static function dropEntries(): void
    {
        Caps::dropKeys(self::PREFIX);
    }

    /**
     * The Connections card's list (see Presence::recent).
     * @return array [{id, name, ip, latency, last_seen, gone}]
     */
    public static function listPresence(int $limit = 200): array
    {
        require_once __DIR__ . '/Presence.php';
        return Presence::recent($limit);
    }

    /**
     * The 1vs1 Duels card: one row per client in a duel phase - inferred
     * from the entry the signal handshake and the duel heartbeat leave -
     * plus quick-match seekers with no peer yet. A live phase shows
     * while the entry is fresh (FOK_CONN_TTL); a clean bye or decline leaves
     * a terminal entry that lingers exactly FOK_DUEL_LINGER seconds, and a
     * duel that simply goes quiet is shown as 'ended' for the same tail - so
     * nothing blinks out mid-glance.
     * @return array [{id, name, peer, state, latency, since}]
     */
    public static function listDuels(int $limit = 200): array
    {
        $now = time();
        $live = [];
        foreach (self::entries() as $id => $e) {
            if ($e['peer'] === null || $e['updated'] <= Util::since(FOK_CONN_TTL + FOK_DUEL_LINGER, $now)) {
                continue;
            }
            $age = $now - $e['updated'];
            if ($e['state'] === 'ended' || $e['state'] === 'declined') {
                // A clean teardown or a rejection: keep it exactly
                // FOK_DUEL_LINGER seconds, then let it go.
                if ($age > FOK_DUEL_LINGER) {
                    continue;
                }
            } elseif ($age > FOK_CONN_TTL + FOK_BEAT_JITTER) {
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
                'latency' => $players[$id]['latency'],
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
                'latency' => $p['latency'],
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
        require_once __DIR__ . '/Presence.php';
        $out = [];
        foreach (Presence::infoOf($ids) as $id => $i) {
            $out[(string)$id] = ['name' => $i['name'], 'latency' => $i['latency']];
        }
        return $out;
    }

    private static function set(string $id, string $peer, string $state): void
    {
        self::store($id, [
            'peer' => $peer,
            'state' => $state,
            'updated' => time(),
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

    /** @param array{peer:?string,state:string,updated:int} $entry */
    private static function store(string $id, array $entry): void
    {
        if (Caps::apcu()) {
            apcu_store(self::key($id), $entry, self::TTL);
        }
    }
}
