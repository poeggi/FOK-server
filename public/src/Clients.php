<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Util.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Presence.php';

/**
 * The client's own version (docs/API.md, "The client's version"). A web
 * client is replaced at its next page load; one installed from a store is
 * replaced when its player updates it, which may be months later. The
 * server keeps every contract minor it published on the same major, so an
 * old build keeps working - what it cannot do by itself is tell the player
 * a newer one exists. hello's `client` names the build, and `upgrade` is
 * that word: judged here against two floors the operator sets, enforced
 * nowhere. The last version and platform an id named are kept on its row
 * (Presence::touch writes them through), so the operator can read which
 * builds are in use.
 */
final class Clients
{
    /**
     * What `client` may look like: three or four numbers joined by dots,
     * 4.5.12, never a "v". Two dots at least, on purpose: "5.0" is a
     * numeric literal, and the settings column's INTEGER affinity would
     * store a floor of that shape as the number 5.
     */
    private const VERSION = '/^[0-9]{1,4}(\.[0-9]{1,4}){2,3}$/';

    /** What `platform` may look like: web, ios, android - kept as sent. */
    private const PLATFORM = '/^[a-z]{1,8}$/';

    /** How far back the spread of builds looks. */
    public const SPREAD_DAYS = 30;

    public static function isVersion(string $v): bool
    {
        return preg_match(self::VERSION, $v) === 1;
    }

    public static function isPlatform(string $p): bool
    {
        return preg_match(self::PLATFORM, $p) === 1;
    }

    /**
     * The `upgrade` word for a client naming $client, or null for nothing
     * to say: no version named, or none of the floors set, or the build is
     * at or above both. The stricter floor wins where both apply. A floor
     * that is not a version (the '0' default) is off.
     */
    public static function upgradeFor(?string $client): ?string
    {
        if ($client === null) {
            return null;
        }
        $min = Settings::str('client_min_version');
        if (self::isVersion($min) && version_compare($client, $min, '<')) {
            return 'required';
        }
        $adv = Settings::str('client_advised_version');
        if (self::isVersion($adv) && version_compare($client, $adv, '<')) {
            return 'advised';
        }
        return null;
    }

    /**
     * How many versions are in use: the bubble's figure, one query per
     * tick. A client that named none is not a version (DISTINCT skips
     * NULL); the spread still lists it as a row.
     */
    public static function distinct(?int $now = null): int
    {
        $st = Db::get()->prepare('SELECT COUNT(DISTINCT client) FROM players WHERE last_seen >= ?');
        $st->execute([($now ?? time()) - self::SPREAD_DAYS * 86400]);
        return (int)$st->fetchColumn();
    }

    /**
     * The builds in use: one row per (version, platform) among players
     * seen in the last SPREAD_DAYS, most players first, with how many of
     * each are online right now. A player whose client never named a
     * version is a row of its own (null), which is the pre-4.23 web
     * client until it updates. The popup's read: one GROUP BY over a
     * small table plus a scan of the presence entries.
     * @return list<array{client: ?string, platform: ?string, players: int, online: int}>
     */
    public static function spread(?int $now = null): array
    {
        $now ??= time();
        $online = Presence::buildsOnline();
        $st = Db::get()->prepare(
            'SELECT client, platform, COUNT(*) AS n FROM players WHERE last_seen >= ?
             GROUP BY client, platform ORDER BY n DESC, client DESC'
        );
        $st->execute([$now - self::SPREAD_DAYS * 86400]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $cv = $r['client'] === null ? null : (string)$r['client'];
            $cp = $r['platform'] === null ? null : (string)$r['platform'];
            $out[] = [
                'client' => $cv,
                'platform' => $cp,
                'players' => (int)$r['n'],
                'online' => $online[($cv ?? '') . '|' . ($cp ?? '')] ?? 0,
            ];
        }
        $st->closeCursor();
        return $out;
    }
}
