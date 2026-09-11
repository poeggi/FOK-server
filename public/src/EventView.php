<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Events.php';
require_once __DIR__ . '/Presence.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Friends.php';
require_once __DIR__ . '/Signals.php';
require_once __DIR__ . '/TourneyStore.php';
require_once __DIR__ . '/Tournament.php';
require_once __DIR__ . '/Util.php';

/**
 * What an event looks like to a caller, and how a transition reaches its
 * members. Kept apart from Events itself so the STORE never has to know who
 * is asking: Events answers rows and facts, this decides what a given
 * caller is allowed to be told.
 *
 * The one rule everything here serves: a PENDING row sees the public face
 * and nothing else - no count, no members, no tournaments, no archive, no
 * achievement. It is standing at a door, and a door tells you nothing about
 * the room behind it.
 */
final class EventView
{
    /** How many past tournaments an event's archive carries on the wire. */
    private const ARCHIVE_MAX = 20;

    /**
     * The caller's whole view. $rowState is what the caller's own row says -
     * 'member', 'monitor' or 'pending' - and is the only thing that decides
     * how much of this answer exists.
     */
    public static function forCaller(array $card, string $id, string $rowState,
                                     ?int $now = null): array
    {
        $now ??= time();
        $out = ['ok' => true] + Events::publicFace($card, $now);
        $out['organizer_name'] = $card['organizer'] === null
            ? null
            : (Presence::namesFor([$card['organizer']])[$card['organizer']] ?? null);
        $out['now'] = Util::nowMs();
        $out['you'] = [
            'state' => $rowState,
            'organizer' => Events::isOrganizer($card, $id),
        ];
        // A MONITOR reads the event as a member does - showing it is its
        // whole job - but earns nothing by being there: the achievement is
        // for joining, and a screen on a wall did not join.
        if ($rowState !== 'member' && $rowState !== 'monitor') {
            return $out;
        }
        $out['members'] = Events::counts($card['eid'])['members'];
        // Joining an event that has not happened yet earns nothing yet. The
        // achievement rides EVERY member answer, so it arrives by itself on
        // the first read after the start and needs no moment of its own -
        // and an event that has since paused or ended keeps it, because the
        // person was there.
        $ach = $rowState === 'member' && Events::stateOf($card, $now) !== 'upcoming'
            ? Events::ach($card) : null;
        if ($ach !== null) {
            $out['ach'] = $ach;
        }
        $out['tourney'] = self::liveTourney($card['eid']);
        $out['archive'] = self::archive($card['eid']);
        return $out;
    }

    /**
     * The roster. Every id carries its name, because the tables are keyed on
     * ids and an operator - or a player - reads about people.
     *
     * NO ONLINE STATE: presence is friendship-gated in this API and being in
     * the same room does not make two people friends. `friend` is what the
     * client's "ask to be friends" button reads, and the request itself is
     * friend.php, unchanged.
     *
     * @return list<array<string, mixed>>
     */
    public static function roster(array $card, string $me, bool $all): array
    {
        $rows = Events::members($card['eid'], $all);
        $ids = array_column($rows, 'id');
        $names = Presence::namesFor($ids);
        $friend = [];
        foreach (Friends::listOf($me) as $f) {
            $friend[(string)$f['id']] = (string)$f['state'];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => $r['id'],
                'name' => $names[$r['id']] ?? null,
                'state' => $r['state'],
                'joined' => $r['joined'],
                'organizer' => Events::isOrganizer($card, $r['id']),
                'friend' => $r['id'] === $me ? 'none' : ($friend[$r['id']] ?? 'none'),
            ];
        }
        return $out;
    }

    /**
     * The event's live tournament, or null. One shared-memory lookup: the
     * store keeps an eid index exactly so this answer costs no scan.
     */
    public static function liveTourney(string $eid): ?array
    {
        $tid = TourneyStore::liveForEvent($eid);
        if ($tid === null) {
            return null;
        }
        $t = TourneyStore::get($tid);
        if ($t === null) {
            return null;
        }
        return [
            'tid' => (string)$t['tid'],
            'code' => (string)$t['code'],
            'state' => (string)$t['state'],
            'players' => count($t['players']),
            // The cap is a SETTING, never a field on the stored tournament -
            // the lobby projection and the announce card both read it the
            // same way, and reading it off the entry answered 0.
            'max' => Settings::int('tournament_max_players'),
        ];
    }

    /**
     * Past tournaments, newest first, with the podium named. The ids stay
     * even when nobody answers for them: a player can expire and the record
     * of the evening still stands.
     *
     * @return list<array<string, mixed>>
     */
    public static function archive(string $eid): array
    {
        $rows = Events::archiveOf($eid, self::ARCHIVE_MAX);
        $ids = [];
        foreach ($rows as $r) {
            foreach ($r['podium'] as $pid) {
                $ids[] = (string)$pid;
            }
        }
        $names = Presence::namesFor($ids);
        $out = [];
        foreach ($rows as $r) {
            $podium = [];
            foreach ($r['podium'] as $pid) {
                $podium[] = ['id' => (string)$pid, 'name' => $names[(string)$pid] ?? null];
            }
            $out[] = [
                'tid' => $r['tid'],
                'finished' => $r['finished'],
                'seats' => $r['seats'],
                'played' => $r['played'],
                'podium' => $podium,
            ];
        }
        return $out;
    }

    /**
     * What a screen on a wall shows. Everything a monitor needs in ONE
     * answer, because it is the only request it makes: the event, the figures
     * an operator wants visible in the room, and - once a tournament is
     * running - the whole projection of it, so the screen can follow the
     * bracket and ask the pair that is playing for a spectator feed.
     *
     * $rowState is the caller's own row, which is what `you` reports here
     * as everywhere else - a free monitor is a member holding a lease and
     * says so.
     *
     * The feed itself never comes through here. `roles` names the two players
     * exactly as it does for a participant, and the monitor asks one of them
     * for a P2P feed with the ordinary 'watch' signal - the server carries no
     * match traffic for a monitor any more than for anybody else.
     */
    public static function monitor(array $card, string $id, string $rowState,
                                   ?int $now = null): array
    {
        $now ??= time();
        $counts = Events::counts($card['eid']);
        $out = ['ok' => true] + Events::publicFace($card, $now);
        $out['organizer_name'] = $card['organizer'] === null
            ? null
            : (Presence::namesFor([$card['organizer']])[$card['organizer']] ?? null);
        $out['now'] = Util::nowMs();
        // The caller's ROW, not the role it is playing on this screen. A
        // reserved monitor's row IS 'monitor'; a free one is a member - or
        // the organizer - who has taken the lease, and saying otherwise
        // made this answer disagree with state and with the events list.
        // `reserved` below is what says which of the two this is.
        $out['you'] = [
            'state' => $rowState,
            'organizer' => Events::isOrganizer($card, $id),
        ];
        $out['members'] = $counts['members'];
        $out['pending'] = $counts['pending'];
        $out['reserved'] = $card['monitor'] !== null;
        $out['archive'] = self::archive($card['eid']);
        $out['tourney'] = null;
        $tid = TourneyStore::liveForEvent($card['eid']);
        if ($tid !== null) {
            $t = Tournament::monitorView($tid, $id);
            if ($t !== null) {
                $out['tourney'] = $t;
            }
        }
        return $out;
    }

    /**
     * Tells the event's audience that something changed - its members and
     * its monitor (see Events::audience). One Signals::send each, in the
     * deferred tail: transitions are rare, and nothing here is anything the
     * caller reads back.
     */
    public static function announce(string $eid, array $payload): void
    {
        $ids = Events::audience($eid);
        if (!$ids) {
            return;
        }
        $msg = (string)json_encode(['eid' => $eid] + $payload, JSON_UNESCAPED_SLASHES);
        Util::defer(static function () use ($eid, $ids, $msg): void {
            foreach ($ids as $to) {
                Signals::send($eid, $to, 'event', $msg);
            }
        });
    }
}
