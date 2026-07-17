<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';

/**
 * Per-client connection tracker: one row per player describing the state
 * of its current 1:1 connection. The server already sees every step of a
 * connection (signal.php handshake, hello.php duel heartbeat, relay.php
 * traffic), so the state is INFERRED from that traffic - clients report
 * nothing extra and the API contract is unchanged.
 *
 * States: inviting, invited, connecting, playing. A player whose row is
 * missing or older than FOK_CONN_TTL reads as 'idle'; offline players are
 * not listed at all (both derived in listOnline(), never stored).
 *
 * mode is how the pair's game traffic is meant to flow: 'p2p' or 'relay'.
 * The no-P2P bit is honored from EITHER side, so once a pair is 'relay' it
 * is never downgraded back to 'p2p' while that pairing lasts.
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

    /**
     * Records what a signaling message means for both endpoints. Types
     * that say nothing about the connection ('chat', the reserved
     * 'friend') are ignored.
     */
    public static function note(string $from, string $to, string $type): void
    {
        if (!isset(self::BY_TYPE[$type])) {
            return;
        }
        [$mine, $theirs, $mode] = self::BY_TYPE[$type];
        if ($mine === null) {
            self::clear($from);
            self::clear($to);
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
     * The sender pushed in-duel data through the hub, so this pair is
     * relaying for real - which is the only way an UNDECLARED P2P->relay
     * fallback becomes visible. Only the sender's own row is written (one
     * statement on a hot path); the peer's row follows from its own
     * traffic and its duel heartbeat.
     */
    public static function relaying(string $from, string $to): void
    {
        self::set($from, $to, 'playing', 'relay');
    }

    /**
     * Does this pair already hold a relay admission slot? Asked before
     * the relay-duel cap, so an admitted duel is never rejected mid-game.
     */
    public static function isRelaying(string $a, string $b): bool
    {
        $st = Db::get()->prepare(
            'SELECT 1 FROM conn WHERE mode = ? AND updated > ?
               AND ((id = ? AND peer = ?) OR (id = ? AND peer = ?)) LIMIT 1'
        );
        $st->execute(['relay', time() - FOK_RELAY_WINDOW, $a, $b, $b, $a]);
        return $st->fetchColumn() !== false;
    }

    /**
     * Pairs currently running through the hub. Counted from the tracked
     * state, NOT from queued relay messages: those are deleted the moment
     * the receiver drains them, so a healthy relayed duel would count as
     * zero and the cap would protect nothing.
     */
    public static function relayPairs(): int
    {
        $st = Db::get()->prepare(
            "SELECT COUNT(DISTINCT CASE WHEN id < peer THEN id || ':' || peer
                                        ELSE peer || ':' || id END)
               FROM conn WHERE mode = 'relay' AND peer IS NOT NULL AND updated > ?"
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
     * Online players with their connection state, most recently seen
     * first. state/mode are resolved here: a stale row means the client
     * went quiet mid-handshake and reads as idle.
     * @return array [{id, name, ip, latency, last_seen, state, peer, mode, since}]
     */
    public static function listOnline(int $limit = 200): array
    {
        $db = Db::get();
        $now = time();
        $st = $db->prepare(
            'SELECT p.id, p.name, p.ip, p.latency, p.last_seen,
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

    private static function clear(string $id): void
    {
        Db::get()->prepare('DELETE FROM conn WHERE id = ?')->execute([$id]);
    }
}
