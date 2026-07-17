<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';

/**
 * Per-client state of the current 1:1 connection, one row per player.
 * Inferred from traffic the server relays anyway (signal handshake, duel
 * heartbeat, relay messages), so clients report nothing for it.
 *
 * States: inviting, invited, connecting, playing. 'idle' and the offline
 * filter are derived in listOnline(), never stored. mode is 'p2p' or
 * 'relay'; relay is never downgraded, the no-P2P bit counts from either
 * side.
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
        // Ends the pairing for both sides.
        'decline' => [null, null, null],
        'bye' => [null, null, null],
    ];

    /** What a signaling message means for both endpoints. */
    public static function note(string $from, string $to, string $type): void
    {
        if (!isset(self::BY_TYPE[$type])) {
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

    /** Drops a player's tracked connection (expiry, admin delete). */
    public static function forget(string $id): void
    {
        Db::get()->prepare('DELETE FROM conn WHERE id = ? OR peer = ?')->execute([$id, $id]);
    }

    /**
     * Online players with their connection state, latest first. A stale
     * row means the client went quiet mid-handshake: it reads as idle.
     * debug is what the admin asked for, debug_active what the client last
     * reported - they differ until the wish reaches it (see Presence).
     * @return array [{id, name, ip, latency, last_seen, state, peer, mode,
     *                 since, debug, debug_active}]
     */
    public static function listOnline(int $limit = 200): array
    {
        $db = Db::get();
        $now = time();
        $st = $db->prepare(
            'SELECT p.id, p.name, p.ip, p.latency, p.last_seen, p.debug, p.debug_active,
                    c.peer, c.state, c.mode, c.updated
               FROM players p LEFT JOIN conn c ON c.id = p.id
              WHERE p.last_seen > ?
              ORDER BY p.last_seen DESC LIMIT ' . $limit
        );
        $st->execute([$now - FOK_ONLINE_WINDOW]);
        $rows = $st->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $stale = $r['state'] === null || (int)$r['updated'] < $now - FOK_CONN_TTL;
            $peer = $stale ? null : $r['peer'];
            $out[] = [
                'id' => $r['id'],
                'name' => $r['name'],
                'ip' => $r['ip'],
                'latency' => $r['latency'] === null ? null : (int)$r['latency'],
                'last_seen' => (int)$r['last_seen'],
                'state' => $stale ? 'idle' : $r['state'],
                'peer' => $peer,
                'mode' => $peer === null ? null : $r['mode'],
                'since' => $stale ? null : (int)$r['updated'],
                'debug' => (int)$r['debug'] === 1,
                'debug_active' => (int)$r['debug_active'] === 1,
            ];
        }
        return $out;
    }

    /**
     * $mode null keeps whatever the pair already declared (the duel
     * heartbeat does not know the mode); a 'p2p' write never overwrites a
     * standing 'relay' for the same peer, so the no-P2P bit sticks.
     */
    private static function set(string $id, string $peer, string $state, ?string $mode): void
    {
        Db::get()->prepare(
            'INSERT INTO conn (id, peer, state, mode, updated) VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (id) DO UPDATE SET
                 peer = excluded.peer,
                 state = excluded.state,
                 mode = CASE WHEN conn.peer = excluded.peer
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
