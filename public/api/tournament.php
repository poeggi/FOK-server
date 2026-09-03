<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Presence.php';
require_once __DIR__ . '/../src/Settings.php';
require_once __DIR__ . '/../src/Tournament.php';

/**
 * Tournament mode: the server runs the tournament, the players run the games.
 *
 * One POST endpoint, eight actions, always {"id": "8-hex", "action": "..."}
 * plus the action's fields (see docs/API.md "Tournament mode"):
 *
 *   create    {id, stakes?}          -> {ok, tid, code, stakes, max}
 *   join      {id, tid|code}         -> {ok, ...lobby}
 *   leave     {id, tid}              -> {ok}
 *   start     {id, tid}              -> {ok}                    host only
 *   state     {id, tid}              -> {ok, ...the whole tournament}
 *   result    {id, tid, nid, outcome, score, mid?}
 *                                    -> {ok, nid, state:"settled"|"confirmed"
 *                                        |"held"|"frozen"}
 *   standdown {id, tid, nid}         -> {ok}
 *   orphan    {id, tid, nid}         -> {ok}
 *
 * Transitions are announced as 'tourney' signals through the ordinary
 * mailbox, so a participant learns about them on its next hello/poll like
 * everything else; the responses here are for the caller's own request.
 *
 * NO GAME TRAFFIC PASSES THROUGH THE SERVER. A tournament match is an
 * ordinary P2P duel: the roles sheet names the two players and the feeder,
 * that pair calls start.php themselves for the mid and secret exactly as any
 * other duel does, and the spectator feeds are peer-to-peer as well. The
 * deprecated relay hub plays no part in tournament mode. What the server owns
 * is the schedule, the roles, the results and the bracket - and nothing here
 * ever decides a match on its own: a result is what the two players who
 * played it agree happened, or it is frozen for an admin.
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
$action = $body['action'] ?? null;
if (!in_array($action, ['create', 'join', 'leave', 'start', 'state', 'result', 'standdown', 'orphan'], true)) {
    Util::fail('invalid action');
}
Util::bump('tournament');
Presence::touch($id, Util::clientIp());

/** Every action but create names a tournament, by tid or (join only) by code. */
function tourney_tid(array $body, string $action): string
{
    $tid = $body['tid'] ?? null;
    if (is_string($tid) && preg_match('/^[0-9a-f]{32}$/', $tid) === 1) {
        return $tid;
    }
    if ($action === 'join') {
        $code = $body['code'] ?? null;
        if (!is_string($code) || preg_match('/^[0-9A-Za-z]{6}$/', $code) !== 1) {
            Util::fail('invalid tid/code');
        }
        $t = Tournament::loadByCode($code);
        if ($t === null) {
            Util::fail('no such tournament', 404);
        }
        return $t['tid'];
    }
    Util::fail('invalid tid');
}

/** A node id as the server minted it: r1.N, koS.N or the literal 'final'. */
function tourney_nid(array $body): string
{
    $nid = $body['nid'] ?? null;
    if (!is_string($nid) || preg_match('/^(r1\.\d{1,3}|ko\d{1,2}\.\d{1,3}|final)$/', $nid) !== 1) {
        Util::fail('invalid nid');
    }
    return $nid;
}

/**
 * The reporter's own score first, then the opponent's - the same "mine,
 * theirs" order the client already uses for a duel's end screen. Tournament
 * puts them back into seat order before storing them.
 *
 * @return array{0:int,1:int}
 */
function tourney_score(array $body): array
{
    $score = $body['score'] ?? null;
    if (!is_array($score) || count($score) !== 2) {
        Util::fail('invalid score');
    }
    $out = [];
    foreach ([0, 1] as $i) {
        $v = $score[$i] ?? null;
        if (!is_int($v) || $v < 0 || $v > Tournament::SCORE_MAX) {
            Util::fail('invalid score');
        }
        $out[] = $v;
    }
    return [$out[0], $out[1]];
}

/**
 * Tournament returns the endpoint's response; null means the row is gone.
 * The transport status travels as 'http' and is stripped here - 'code' is
 * taken: it is the lobby's join code, and that has to reach the client.
 */
function tourney_out(?array $res): never
{
    if ($res === null) {
        Util::fail('no such tournament', 404);
    }
    if (($res['ok'] ?? false) !== true) {
        $status = (int)($res['http'] ?? 400);
        $error = (string)($res['error'] ?? 'failed');
        unset($res['ok'], $res['error'], $res['http']);
        // retry_after and friends ride along, like the friend-request throttle.
        Util::jsonOut(['ok' => false, 'error' => $error] + $res, $status);
    }
    unset($res['http']);
    Util::jsonOut($res);
}

switch ($action) {
    case 'create':
        tourney_out(Tournament::create($id, ($body['stakes'] ?? false) === true));
        // no break - tourney_out never returns

    case 'join':
        tourney_out(Tournament::join($id, tourney_tid($body, 'join')));

    case 'leave':
        tourney_out(Tournament::leave($id, tourney_tid($body, 'leave')));

    case 'start':
        tourney_out(Tournament::start($id, tourney_tid($body, 'start')));

    case 'state':
        tourney_out(Tournament::view($id, tourney_tid($body, 'state')));

    case 'result':
        $outcome = $body['outcome'] ?? null;
        if (!is_string($outcome) || !in_array($outcome, Tournament::OUTCOMES, true)) {
            Util::fail('invalid outcome');
        }
        // The mid is recorded for audit only. The server does not check it
        // against Starts: the pair's start.php call is a normal duel start
        // and the tournament merely notes which one this node was played on.
        $mid = $body['mid'] ?? null;
        if ($mid !== null && (!is_string($mid) || preg_match('/^[0-9a-f]{8,64}$/', $mid) !== 1)) {
            Util::fail('invalid mid');
        }
        tourney_out(Tournament::report(
            $id,
            tourney_tid($body, 'result'),
            tourney_nid($body),
            $outcome,
            tourney_score($body),
            $mid
        ));

    // Spectator-tree repair, both roles-only: 'standdown' is a primary that
    // is about to background and hands its feed on; 'orphan' is a secondary
    // that lost its primaries. Neither can touch a result.
    case 'standdown':
    case 'orphan':
        tourney_out(Tournament::redeal($id, tourney_tid($body, $action), tourney_nid($body), $action));
}

Util::fail('invalid action');
