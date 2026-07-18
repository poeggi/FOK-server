<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';

/**
 * Per-client state of the current 1:1 connection, one row per player.
 * Inferred from traffic the server relays anyway (signal handshake, duel
 * heartbeat, relay messages), so clients report nothing for it.
 *
 * States: inviting, invited, connecting, playing, plus the terminal
 * declined / ended that linger briefly on the Duels card (see listDuels).
 * Presence - every online client - is a separate list, listPresence.
 * mode is 'p2p' or 'relay'; relay is never downgraded within a duel, the
 * no-P2P bit counts from either side.
 */
final class ConnTrack
{
    /** Signal type => [sender state, recipient state, mode]. */
    private const BY_TYPE = [
        'invite' => ['inviting', 'invited', 'p2p'],
        'invite-relay' => ['inviting', 'invited', 'relay'],
        'accept' => ['connecting', 'connecting', 'p2p'],
        'accept-relay' => ['connecting', 'connecting', 'relay'],
        'offer' => ['connecting', 'connecting', 'p2p'],
        'answer' => ['connecting', 'connecting', 'p2p'],
        'ice' => ['connecting', 'connecting', 'p2p'],
        // decline is special-cased in note() (it leaves a 'declined' row);
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
            // 'declined' row naming who it turned down, so the Duels card
            // shows the decline and who made it; the inviter returns to idle.
            self::set($from, $to, 'declined', null);
            self::clear($to, $from);
            return;
        }
        if ($type === 'bye') {
            // A clean teardown does not wipe the pair: both sides keep a
            // short-lived 'ended' row so the duel lingers on the Duels card
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
     * A clean teardown (bye): both sides keep a short-lived 'ended' row so
     * the duel lingers on the Duels card for FOK_DUEL_LINGER seconds, and
     * the relay slot is freed at once (relay_seen = 0) so a byed relay duel
     * does not hold the cap for the whole relay window. Touches only rows
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
        Db::get()->prepare(
            "UPDATE conn SET state = 'ended', updated = ?, relay_seen = 0
               WHERE id = ? AND peer = ?"
        )->execute([time(), $id, $peer]);
    }

    /**
     * Real traffic through the hub - also the only writer of relay_seen,
     * so a relay slot always costs hub traffic and never a client's claim
     * to be relaying (accept-relay is not friendship-gated, so claims are
     * free and a few would otherwise deny the relay to everyone).
     * Writes only the sender's row: one statement on a hot path.
     */
    public static function relaying(string $from, string $to): void
    {
        $now = time();
        Db::get()->prepare(
            'INSERT INTO conn (id, peer, state, mode, updated, relay_seen) VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT (id) DO UPDATE SET
                 peer = excluded.peer,
                 state = excluded.state,
                 mode = excluded.mode,
                 updated = excluded.updated,
                 relay_seen = excluded.relay_seen'
        )->execute([$from, $to, 'playing', 'relay', $now, $now]);
    }

    /**
     * Does this pair already hold a relay admission slot? Asked before
     * the relay-duel cap, so an admitted duel is never rejected mid-game.
     */
    public static function isRelaying(string $a, string $b): bool
    {
        $st = Db::get()->prepare(
            'SELECT 1 FROM conn WHERE relay_seen > ?
               AND ((id = ? AND peer = ?) OR (id = ? AND peer = ?)) LIMIT 1'
        );
        $st->execute([time() - FOK_RELAY_WINDOW, $a, $b, $b, $a]);
        return $st->fetchColumn() !== false;
    }

    /**
     * Pairs running through the hub. Counted from relay_seen, not from
     * queued relay messages: those are deleted as the receiver drains
     * them, so a healthy duel would count as zero and the cap would
     * protect nothing.
     */
    public static function relayPairs(): int
    {
        $st = Db::get()->prepare(
            "SELECT COUNT(DISTINCT CASE WHEN id < peer THEN id || ':' || peer
                                        ELSE peer || ':' || id END)
               FROM conn WHERE peer IS NOT NULL AND relay_seen > ?"
        );
        $st->execute([time() - FOK_RELAY_WINDOW]);
        return (int)$st->fetchColumn();
    }

    /**
     * The raw tracked-connection row for one client (admin detail view), or
     * null if it holds no duel state. Callers render the linger/ended
     * semantics themselves (see listDuels).
     * @return array{peer:?string,state:string,mode:?string,updated:int,relay_seen:int}|null
     */
    public static function stateOf(string $id): ?array
    {
        $st = Db::get()->prepare(
            'SELECT peer, state, mode, updated, relay_seen FROM conn WHERE id = ?'
        );
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row === false) {
            return null;
        }
        return [
            'peer' => $row['peer'],
            'state' => $row['state'],
            'mode' => $row['mode'],
            'updated' => (int)$row['updated'],
            'relay_seen' => (int)$row['relay_seen'],
        ];
    }

    /** Drops a player's tracked connection (expiry, admin delete). */
    public static function forget(string $id): void
    {
        Db::get()->prepare('DELETE FROM conn WHERE id = ? OR peer = ?')->execute([$id, $id]);
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
        return $out;
    }

    /**
     * The 1:1 Duels card: one row per client in a duel phase - inferred
     * from the conn row the signal handshake, duel heartbeat and relay
     * write leave - plus quick-match seekers from mm_queue with no peer
     * yet. A live phase shows while conn.updated is fresh (FOK_CONN_TTL); a
     * clean bye or decline leaves a terminal row that lingers exactly
     * FOK_DUEL_LINGER seconds, and a duel that simply goes quiet is shown
     * as 'ended' for the same tail - so nothing blinks out mid-glance.
     * @return array [{id, name, peer, state, mode, latency, msgs, since}]
     */
    public static function listDuels(int $limit = 200): array
    {
        $db = Db::get();
        $now = time();
        $st = $db->prepare(
            'SELECT p.id, p.name, p.latency, c.peer, c.state, c.mode, c.updated,
                    rr.total AS msgs
               FROM conn c
               JOIN players p ON p.id = c.id
               LEFT JOIN relay_rate rr ON rr.id = c.id
              WHERE c.peer IS NOT NULL AND c.updated > ?
              ORDER BY c.updated DESC LIMIT ' . $limit
        );
        $st->execute([$now - FOK_CONN_TTL - FOK_DUEL_LINGER]);
        $out = [];
        $seen = [];
        foreach ($st->fetchAll() as $r) {
            $seen[$r['id']] = true;
            $state = $r['state'];
            $age = $now - (int)$r['updated'];
            if ($state === 'ended' || $state === 'declined') {
                // A clean teardown or a rejection: keep it exactly
                // FOK_DUEL_LINGER seconds, then let it go.
                if ($age > FOK_DUEL_LINGER) {
                    continue;
                }
            } elseif ($age > FOK_CONN_TTL) {
                // A live phase that stopped refreshing (no bye reached us):
                // treat the stale row as ended and give it the same tail.
                $state = 'ended';
            }
            $out[] = [
                'id' => $r['id'],
                'name' => $r['name'],
                'peer' => $r['peer'],
                'state' => $state,
                'mode' => $r['mode'],
                'latency' => $r['latency'] === null ? null : (int)$r['latency'],
                'msgs' => (int)$r['msgs'],
                'since' => (int)$r['updated'],
            ];
        }
        // Quick-match seekers with no peer yet: half a duel, shown the
        // instant they start looking.
        foreach ($db->query(
            'SELECT m.id, m.since, p.name, p.latency
               FROM mm_queue m JOIN players p ON p.id = m.id
              WHERE m.matched_with IS NULL'
        )->fetchAll() as $r) {
            if (isset($seen[$r['id']])) {
                continue;
            }
            $out[] = [
                'id' => $r['id'],
                'name' => $r['name'],
                'peer' => null,
                'state' => 'matchmaking',
                'mode' => null,
                'latency' => $r['latency'] === null ? null : (int)$r['latency'],
                'msgs' => 0,
                'since' => (int)$r['since'],
            ];
        }
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
        Db::get()->prepare(
            'INSERT INTO conn (id, peer, state, mode, updated) VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (id) DO UPDATE SET
                 peer = excluded.peer,
                 state = excluded.state,
                 mode = CASE WHEN conn.peer = excluded.peer
                                  AND conn.state NOT IN (\'ended\', \'declined\')
                                  AND (excluded.mode IS NULL OR conn.mode = \'relay\')
                             THEN conn.mode ELSE excluded.mode END,
                 updated = excluded.updated'
        )->execute([$id, $peer, $state, $mode, time()]);
    }

    /**
     * Ends the connection with THIS peer only: bye/decline are not
     * friendship-gated, so a stranger must not be able to wipe the state
     * of a duel it has nothing to do with.
     */
    private static function clear(string $id, string $peer): void
    {
        Db::get()->prepare('DELETE FROM conn WHERE id = ? AND peer = ?')->execute([$id, $peer]);
    }
}
