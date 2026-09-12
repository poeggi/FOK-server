<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Presence.php';
require_once __DIR__ . '/../src/Events.php';
require_once __DIR__ . '/../src/EventView.php';
require_once __DIR__ . '/../src/Signals.php';
require_once __DIR__ . '/../src/Settings.php';
require_once __DIR__ . '/../src/Alerts.php';

/**
 * Events: a room an operator opens, entered by scanning its QR.
 *
 * One POST endpoint, ten actions, always {"id": "8-hex", "action": "...",
 * "eid": "4 chars"} plus the action's fields (see docs/API.md "Events"):
 *
 *   join     {id, code}               -> {ok, ...state}   member or pending
 *                                       the code names its own event
 *   state    {id, eid}                -> {ok, ...the caller's whole view}
 *   members  {id, eid}                -> {ok, members: [...]}
 *   pass     {id, eid}                -> {ok, step, valid, slots: [...]}
 *   leave    {id, eid}                -> {ok}
 *   roster   {id, eid, peer, set}     -> {ok}             organizer only
 *   access   {id, eid, closed}        -> {ok}             organizer only
 *   run      {id, eid}                -> {ok}             organizer only
 *   pause    {id, eid}                -> {ok}             organizer only
 *   end      {id, eid}                -> {ok}             organizer, terminal
 *
 * THE CODE IS THE CAPABILITY, and `join` is the only action that takes one.
 * An eid on its own grants nothing: every other action answers 404 to a
 * caller with no row, which is the same answer an eid that does not exist
 * gets, so nothing here can be enumerated. A wrong code answers the same
 * 404 again and is throttled per player - the codes are far too large to
 * guess, so the throttle is there to put the attempt on record.
 *
 * THE SERVER IS THE ROSTER. Approving, declining, removing and banning are
 * one verb (`roster`) over one path (Events::setMember), which the admin
 * dashboard calls too, so the organizer's remote control and the operator's
 * can never drift apart.
 *
 * Transitions are announced as reserved 'event' signals through the ordinary
 * mailbox, so a member learns of them on its next hello or poll like
 * everything else. The scheduled moments announce NOTHING: state is derived
 * from the two stamps and the clock, so a client that can read the clock
 * already knows.
 */
Util::cors();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Util::fail('POST only', 405);
}

$body = Util::jsonBody();
$id = $body['id'] ?? null;
if (!Util::isValidId($id)) {
    Util::fail('invalid id');
}
Util::noteCaller($id);
$action = $body['action'] ?? null;
if (!in_array($action, ['join', 'state', 'members', 'pass', 'leave', 'monitor',
        'roster', 'access', 'run', 'pause', 'end'], true)) {
    Util::fail('invalid action');
}
Util::bump('event');
Presence::touch($id, Util::clientIp());

$now = time();
$code = $body['code'] ?? null;

// A SCAN carries the code and nothing else: the poster's key names its own
// event (there is no room beside it in a version 3 code, see Events::KEY_LEN)
// and a pass carries the eid in front of its own dot. Every other action is
// asked by somebody who already has a row, so it names the eid outright.
if ($action === 'join') {
    if (Events::failsOver($id)) {
        Util::jsonOut(['ok' => false, 'error' => 'too many attempts',
            'retry_after' => 60], 429);
    }
    if (!is_string($code)) {
        Util::fail('invalid code');
    }
    $A = Events::ALPHABET;
    if (preg_match('/^[' . $A . ']{4}\\.[' . $A . ']{6}$/', $code) === 1) {
        $eid = substr($code, 0, 4);
        $code = substr($code, 5);
        $card = Events::card($eid);
        $via = 'pass';
    } elseif (preg_match('/^[' . $A . ']{' . Events::KEY_LEN . '}$/', $code) === 1) {
        $card = Events::byKey($code);
        $eid = $card === null ? '' : $card['eid'];
        $via = 'key';
    } else {
        Util::fail('invalid code');
    }
} else {
    $eid = $body['eid'] ?? null;
    if (!is_string($eid) || preg_match('/^[' . Events::ALPHABET . ']{4}$/', $eid) !== 1) {
        Util::fail('invalid eid');
    }
    $card = Events::card($eid);
    $via = '';
}

// A MONITOR is in the event without being at it - it is a screen on a
// wall. It reads the event, it runs itself, and it shows the live pass
// between tournaments so people join off the TV; that is the whole list:
// it is in no roster and has no business with a door.
$mine = $eid === '' ? null : Events::rowOf($eid, $id);
if ($mine !== null && $mine['state'] === 'monitor'
    && !in_array($action, ['state', 'monitor', 'pass'], true)) {
    Util::fail('monitor only', 403);
}

/**
 * The one answer for an eid nobody minted, a code that is wrong, and an
 * event the caller has no row in. Three different situations on the server,
 * one indistinguishable answer on the wire.
 */
function event_unknown(): never
{
    Util::fail('no such event', 404);
}

/**
 * Counts a wrong code, and says so ONCE when the count crosses the cap.
 * Noted rather than raised: somebody typing a stale pass is ordinary, and
 * an alert per refused attempt would be a flood rather than a signal. The
 * line names what was tried, never the code itself.
 */
function event_noteWrongCode(string $id, string $eid, string $kind): void
{
    $n = Events::noteFail($id);
    if ($n === Settings::int('event_join_fails_per_min')) {
        Alerts::note('event', "$id walked into the event code throttle on $eid "
            . "($n wrong $kind attempts in a minute)");
    }
}

/** Only the organizer drives the event itself. */
function event_requireOrganizer(?array $card, string $id): void
{
    if ($card === null || !Events::isOrganizer($card, $id)) {
        Util::fail('not the organizer', 403);
    }
}

/**
 * run / pause / end. A SCHEDULED event walks itself, so its organizer is
 * refused here: pressing pause on a clock would be a state nothing could
 * derive. The operator can still end one from the dashboard.
 */
function event_setMode(array $card, string $mode, string $id): never
{
    event_requireOrganizer($card, $id);
    if (Events::isScheduled($card)) {
        Util::fail('scheduled', 409);
    }
    $was = Events::stateOf($card);
    if ($was === 'ended') {
        Util::fail('ended', 409);
    }
    Events::setMode($card['eid'], $mode);
    $fresh = Events::card($card['eid']);
    $state = $fresh === null ? $mode : Events::stateOf($fresh);
    if ($state !== $was) {
        EventView::announce($card['eid'], ['event' => 'state', 'state' => $state]);
    }
    Util::jsonOut(['ok' => true, 'state' => $state]);
}

switch ($action) {
    // The scan. The only way to get a row, and idempotent: a repeat answers
    // what the first did, achievement included, so a client that lost the
    // response simply asks again.
    case 'join':
        if ($card === null) {
            // A key that names nothing and a pass for an event that does not
            // exist are the same answer, and so is a wrong code below.
            event_noteWrongCode($id, $eid === '' ? '?' : $eid, $via);
            event_unknown();
        }
        // The key was matched by the lookup that found the event; a pass is
        // checked against the clock here.
        if ($via === 'pass' && !Events::verifyPass($card, $code, $now)) {
            event_noteWrongCode($id, $eid, $via);
            event_unknown();
        }
        // A banned row is answered before the event's own state, because it
        // is about this caller and would otherwise read as "come back later".
        $row = Events::rowOf($eid, $id);
        if ($row !== null && $row['state'] === 'banned') {
            Util::fail('banned', 403);
        }
        // A POSTER GOES UP BEFORE THE EVENT, which is what the printed key is
        // for: long-lived, on a wall, and the only code somebody walking past
        // can have. So a key admits while the event is still `upcoming` - they
        // get their row, and the event rides their `events` list with its own
        // start stamp until it begins. A PASS does not: it is minted from the
        // clock by a member, and an upcoming event mints none.
        $state = Events::stateOf($card, $now);
        $early = $state === 'upcoming' && $via === 'key';
        if ($state !== 'active' && !$early) {
            Util::fail($state === 'upcoming' ? 'not started' : $state, 409);
        }
        $was = $row === null ? null : $row['state'];
        $reserved = $card['monitor'] !== null && $card['monitor'] === $id;
        $got = Events::admit($eid, $id, $card['closed'], $via, $reserved);
        // A new pending row is the organizer's business the moment it
        // exists; an open door announces nothing, because the count is read
        // rather than pushed.
        if ($was === null && $got === 'pending' && $card['organizer'] !== null) {
            $me = $id;
            Util::defer(static function () use ($card, $me): void {
                Signals::send($me, (string)$card['organizer'], 'event', (string)json_encode(
                    ['event' => 'request', 'eid' => $card['eid'], 'from' => $me],
                    JSON_UNESCAPED_SLASHES
                ));
            });
        }
        Util::jsonOut(EventView::forCaller($card, $id, $got, $now));

    case 'state':
        if ($card === null || $mine === null) {
            event_unknown();
        }
        if ($mine['state'] === 'banned') {
            Util::fail('banned', 403);
        }
        Util::jsonOut(EventView::forCaller($card, $id, $mine['state'], $now));

    // The roster as a member reads it. Pending and banned rows are the
    // organizer's business and a member never learns they exist.
    case 'members':
        $row = Events::rowOf($eid, $id);
        if ($card === null || $row === null) {
            event_unknown();
        }
        if ($row['state'] !== 'member') {
            Util::fail($row['state'] === 'banned' ? 'banned' : 'not a member', 403);
        }
        Util::jsonOut(['ok' => true,
            'members' => EventView::roster($card, $id, Events::isOrganizer($card, $id))]);

    // Any member may pass the event on, and so may the screen on the wall:
    // that is what makes it spread in a room. The slots are minted from
    // the clock, so nothing is stored and the server never learns who
    // showed one.
    case 'pass':
        if ($card === null || $mine === null) {
            event_unknown();
        }
        if (!in_array($mine['state'], ['member', 'monitor'], true)) {
            Util::fail($mine['state'] === 'banned' ? 'banned' : 'not a member', 403);
        }
        $state = Events::stateOf($card, $now);
        if ($state !== 'active') {
            Util::fail($state === 'upcoming' ? 'not started' : $state, 409);
        }
        Util::jsonOut([
            'ok' => true,
            'step' => Settings::int('event_pass_step_secs'),
            'valid' => Settings::int('event_pass_valid_secs'),
            'slots' => Events::mintPasses($card, 6, $now),
        ]);

    // The caller removes its own row - a member leaving, or somebody
    // withdrawing a request. The organizer cannot: leaving would abandon
    // the event for everyone, which is an operator's decision.
    case 'leave':
        $row = Events::rowOf($eid, $id);
        if ($card === null || $row === null) {
            event_unknown();
        }
        if (Events::isOrganizer($card, $id)) {
            // NOT 'not the organizer', which is the opposite of the reason:
            // they are refused BECAUSE they are the organizer, and a client
            // that shows the string would tell them so.
            Util::fail('the organizer', 403);
        }
        Events::setMember($eid, $id, 'none');
        Util::jsonOut(['ok' => true]);

    // The screen on the wall. ONE request answers everything it shows, and
    // it takes or renews the event's single monitor slot in the same call -
    // a monitor is never meant to be attended, so there is nothing to press
    // at either end and the slot frees itself by simply not being asked for.
    case 'monitor':
        if ($card === null || $mine === null) {
            event_unknown();
        }
        if ($mine['state'] === 'pending') {
            Util::fail('not a member', 403);
        }
        if ($mine['state'] === 'banned') {
            Util::fail('banned', 403);
        }
        if (!$card['monitor_allowed']) {
            Util::fail('no monitor', 403);
        }
        if (!Events::claimMonitor($card, $id)) {
            Util::fail('monitor taken', 409);
        }
        Util::jsonOut(EventView::monitor($card, $id, $mine['state'], $now));

    // Approve, decline, remove, ban, unban: one verb over one path, the
    // same one the dashboard calls.
    case 'roster':
        event_requireOrganizer($card, $id);
        $peer = $body['peer'] ?? null;
        if (!Util::isValidId($peer)) {
            Util::fail('invalid peer');
        }
        $set = $body['set'] ?? null;
        if (!in_array($set, ['member', 'none', 'banned'], true)) {
            Util::fail('invalid set');
        }
        if ($peer === $id) {
            Util::fail('not the organizer', 403);
        }
        // The organizer APPROVES, never adds: a peer with no row has not
        // scanned anything, and the code must stay the only way in.
        $was = Events::rowOf($eid, $peer);
        if ($was === null) {
            event_unknown();
        }
        $changed = Events::setMember($eid, $peer, $set);
        if ($changed && $set === 'member' && $was['state'] === 'pending') {
            $me = $id;
            Util::defer(static function () use ($eid, $me, $peer): void {
                Signals::send($me, $peer, 'event', (string)json_encode(
                    ['event' => 'accepted', 'eid' => $eid],
                    JSON_UNESCAPED_SLASHES
                ));
            });
        }
        // A decline and a removal announce nothing - the friend logic: what
        // did not happen is not sent anywhere.
        Util::jsonOut(['ok' => true]);

    case 'access':
        event_requireOrganizer($card, $id);
        $closed = $body['closed'] ?? null;
        if (!is_bool($closed)) {
            Util::fail('invalid closed');
        }
        Events::setClosed($eid, $closed);
        Util::jsonOut(['ok' => true, 'closed' => $closed]);

    case 'run':
        event_setMode($card ?? [], 'active', $id);

    case 'pause':
        event_setMode($card ?? [], 'paused', $id);

    case 'end':
        event_setMode($card ?? [], 'ended', $id);
}

Util::fail('invalid action');
