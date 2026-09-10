<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Events.php';
require_once __DIR__ . '/EventView.php';
require_once __DIR__ . '/Presence.php';
require_once __DIR__ . '/TourneyStore.php';
require_once __DIR__ . '/Tournament.php';

/**
 * What the operator's dashboard reads about events.
 *
 * The operator can do everything an organizer can and five things more:
 * create, edit, print, name an organizer, and delete. Those verbs go through
 * the SAME paths the client-facing endpoint uses (Events::setMember and the
 * rest), so the two remote controls over one roster cannot drift apart.
 *
 * THE KEY IS IN NOTHING HERE. It exists in one place an operator can see it,
 * the print page, and never in a JSON answer - the same rule the per-match
 * attestation secrets follow.
 */
final class EventAdmin
{
    /**
     * The card: one row per event, newest first. Reads the events table
     * directly rather than through the per-event cache - a dashboard tick
     * asking for every event would fill shared memory with cards nobody is
     * about to ask for again.
     *
     * @return array{events: list<array<string, mixed>>, names: array<string, string>}
     */
    public static function card(): array
    {
        $now = time();
        $st = Db::get()->query('SELECT eid, name, organizer, closed, starts, ends, mode,
                                monitor_allowed, monitor, created, ended_at
                                FROM events ORDER BY created DESC');
        $rows = $st === false ? [] : $st->fetchAll();
        if ($st !== false) {
            $st->closeCursor();
        }
        if (!$rows) {
            return ['events' => [], 'names' => []];
        }
        $counts = self::countsAll();
        $arch = self::archiveCounts();
        $out = [];
        $ids = [];
        foreach ($rows as $r) {
            $eid = (string)$r['eid'];
            $card = [
                'mode' => (string)$r['mode'],
                'starts' => $r['starts'] === null ? null : (int)$r['starts'],
                'ends' => $r['ends'] === null ? null : (int)$r['ends'],
            ];
            $organizer = $r['organizer'] === null ? null : (string)$r['organizer'];
            if ($organizer !== null) {
                $ids[] = $organizer;
            }
            $c = $counts[$eid] ?? ['members' => 0, 'pending' => 0, 'banned' => 0];
            $out[] = [
                'eid' => $eid,
                'name' => (string)$r['name'],
                'state' => Events::stateOf($card, $now),
                'closed' => (int)$r['closed'] === 1,
                'organizer' => $organizer,
                'members' => $c['members'],
                'pending' => $c['pending'],
                'banned' => $c['banned'],
                'tournaments' => $arch[$eid] ?? 0,
                'monitor_allowed' => (int)($r['monitor_allowed'] ?? 1) === 1,
                'monitor' => ($r['monitor'] ?? null) === null ? null : (string)$r['monitor'],
                'starts' => $card['starts'],
                'ends' => $card['ends'],
                'created' => (int)$r['created'],
                'scheduled' => Events::isScheduled($card),
            ];
        }
        return ['events' => $out, 'names' => Presence::namesFor($ids)];
    }

    /**
     * One event in full, for the popup: the row, the whole roster with the
     * pending queue on top, the archive, and the live tournament if there is
     * one. Read-only and inert.
     */
    public static function detail(string $eid): ?array
    {
        $card = Events::card($eid);
        if ($card === null) {
            return null;
        }
        $now = time();
        // The operator's popup is the ONE place the monitor row shows: it is
        // absent from every participant list, and somebody has to be able to
        // see the screen they set up.
        $rows = Events::members($eid, true, true);
        $ids = array_column($rows, 'id');
        if ($card['organizer'] !== null) {
            $ids[] = $card['organizer'];
        }
        $holder = Events::monitorHolder($card);
        if ($holder !== null) {
            $ids[] = $holder;
        }
        // The dashboard may say who is online: it is the operator's screen,
        // not a player's, and presence gating exists to stop PLAYERS reading
        // each other. The client-facing roster still carries none.
        $info = Presence::infoOf(array_column($rows, 'id'));
        $roster = [];
        foreach ($rows as $r) {
            $roster[] = $r + [
                'organizer' => Events::isOrganizer($card, $r['id']),
                'online' => (bool)($info[$r['id']]['online'] ?? false),
            ];
        }
        $counts = Events::counts($eid);
        return [
            'eid' => $card['eid'],
            'name' => $card['name'],
            'descr' => $card['descr'],
            'organizer' => $card['organizer'],
            'closed' => $card['closed'],
            'mode' => $card['mode'],
            'state' => Events::stateOf($card, $now),
            'scheduled' => Events::isScheduled($card),
            'starts' => $card['starts'],
            'ends' => $card['ends'],
            'created' => $card['created'],
            'ended_at' => $card['ended_at'],
            'ach_name' => $card['ach_name'],
            'ach_desc' => $card['ach_desc'],
            'ach_icon' => $card['ach_icon'],
            'monitor_allowed' => $card['monitor_allowed'],
            'monitor' => $card['monitor'],
            'monitor_holder' => Events::monitorHolder($card),
            'counts' => $counts,
            'roster' => $roster,
            'archive' => EventView::archive($eid),
            'tourney' => EventView::liveTourney($eid),
            'names' => Presence::namesFor($ids),
        ];
    }

    /** Member, pending and banned per event, in one query. */
    private static function countsAll(): array
    {
        $st = Db::get()->query('SELECT eid, state, COUNT(*) AS n
                                FROM event_members GROUP BY eid, state');
        $out = [];
        foreach ($st === false ? [] : $st->fetchAll() as $r) {
            $eid = (string)$r['eid'];
            $out[$eid] ??= ['members' => 0, 'pending' => 0, 'banned' => 0];
            $key = ['member' => 'members', 'pending' => 'pending',
                    'banned' => 'banned'][(string)$r['state']] ?? null;
            if ($key !== null) {
                $out[$eid][$key] = (int)$r['n'];
            }
        }
        if ($st !== false) {
            $st->closeCursor();
        }
        return $out;
    }

    /** How many tournaments each event has archived, in one query. */
    private static function archiveCounts(): array
    {
        $st = Db::get()->query('SELECT eid, COUNT(*) AS n FROM event_results GROUP BY eid');
        $out = [];
        foreach ($st === false ? [] : $st->fetchAll() as $r) {
            $out[(string)$r['eid']] = (int)$r['n'];
        }
        if ($st !== false) {
            $st->closeCursor();
        }
        return $out;
    }

    /**
     * PURGES an event: every row it owns, every cache describing it, and the
     * tournament it is running if it has one. Terminal, and the one verb
     * here with no organizer-side equivalent - the archive is the record of
     * every evening the event ever ran, so destroying it is an operator's
     * decision alone.
     *
     * Nothing may be left describing an event that is gone, which is why
     * this is not three DELETEs: a live tournament tagged with the eid
     * would go on refusing joins in the name of a room nobody can read any
     * more, and a monitor claim would hold a slot on it.
     *
     * @return array{tournament: bool} what else had to go with it
     */
    public static function delete(string $eid): array
    {
        // First, or the abort's own write lands on rows this is removing.
        $tid = TourneyStore::usable() ? TourneyStore::liveForEvent($eid) : null;
        if ($tid !== null) {
            Tournament::abort($tid);
        }
        Db::retry(static function () use ($eid): void {
            $db = Db::get();
            $db->prepare('DELETE FROM event_results WHERE eid = ?')->execute([$eid]);
            $db->prepare('DELETE FROM event_members WHERE eid = ?')->execute([$eid]);
            $db->prepare('DELETE FROM events WHERE eid = ?')->execute([$eid]);
        });
        Events::forgetCard($eid);
        Events::forgetCounts($eid);
        Events::forgetMonitor($eid);
        return ['tournament' => $tid !== null];
    }

    /**
     * Every member of an event, so a delete can drop the caches of the people
     * who were in it. One query, and only ever on the way out.
     * @return list<string>
     */
    public static function memberIds(string $eid): array
    {
        $st = Db::get()->prepare('SELECT id FROM event_members WHERE eid = ?');
        $st->execute([$eid]);
        $ids = [];
        foreach ($st->fetchAll() as $r) {
            $ids[] = (string)$r['id'];
        }
        $st->closeCursor();
        return $ids;
    }
}
