<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Presence.php';
require_once __DIR__ . '/../src/Scores.php';
require_once __DIR__ . '/../src/Backup.php';
require_once __DIR__ . '/../src/Alerts.php';
require_once __DIR__ . '/../src/Logs.php';
require_once __DIR__ . '/../src/Caps.php';
require_once __DIR__ . '/../src/Settings.php';
require_once __DIR__ . '/../src/ConnTrack.php';
require_once __DIR__ . '/../src/Vault.php';
require_once __DIR__ . '/../src/Debug.php';
require_once __DIR__ . '/../src/AdminData.php';
require_once __DIR__ . '/../src/EventAdmin.php';
require_once __DIR__ . '/../src/EventView.php';
require_once __DIR__ . '/../src/Ledger.php';
require_once __DIR__ . '/../src/Items.php';
require_once __DIR__ . '/../src/Tournament.php';
require_once __DIR__ . '/../src/Housekeeping.php';

Auth::requireLogin();
// The session is read once, for that check, and never written here: hold its
// lock any longer and the dashboard's own polls queue behind each other,
// every waiting one sitting in a PHP worker that a client cannot have.
session_write_close();
// The dashboard is a client like any other - three polls a second, each
// holding a worker - so it is counted like any other, under its own name in
// the per-script view rather than hidden from it (see Counters::cost).
Util::bump('admin');

$action = $_GET['action'] ?? '';
// The CPU baseline every other endpoint takes when it opens the database
// (see Load::markStart). The cards the dashboard polls are answered from
// shared memory and open nothing, so without this their CPU would read
// zero - and the one screen being measured must not be the one that
// under-reports. The handle itself is asked for where it is used: a tick
// carrying only presence and duels then costs no connection at all.
Load::markStart();

/**
 * State-changing actions are POST-only: a GET could be triggered cross-site
 * by top-level navigation despite the SameSite=Lax cookie.
 */
function requirePost(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        Util::fail('POST only', 405);
    }
}
/** A validated 8-hex client id, from GET (default) or POST. */
function requireId(string $src = 'GET'): string
{
    $id = $src === 'POST' ? ($_POST['id'] ?? '') : ($_GET['id'] ?? '');
    if (!Util::isValidId($id)) {
        Util::fail('invalid id');
    }
    return $id;
}
/** A validated 32-hex tournament id, from GET (default) or POST. */
function requireTid(string $src = 'GET'): string
{
    $tid = (string)($src === 'POST' ? ($_POST['tid'] ?? '') : ($_GET['tid'] ?? ''));
    if (preg_match('/^[0-9a-f]{32}$/', $tid) !== 1) {
        Util::fail('invalid tid');
    }
    return $tid;
}
/** A 4-character event id, from the shared code alphabet. */
function requireEid(string $src = 'GET'): string
{
    $eid = (string)($src === 'POST' ? ($_POST['eid'] ?? '') : ($_GET['eid'] ?? ''));
    if (preg_match('/^[' . Events::ALPHABET . ']{4}$/', $eid) !== 1) {
        Util::fail('invalid eid');
    }
    return $eid;
}

/**
 * The event fields a create or an edit may set. An EDIT only carries the
 * fields the form actually posted, so leaving one out leaves it alone;
 * a create fills in the rest from the defaults in Events::create.
 *
 * A datetime-local field posts an empty string for "no schedule", which
 * is a real value and must reach the column as null rather than as 0.
 */
function adminEventFields(bool $partial = false): array
{
    $out = [];
    foreach (['name', 'descr', 'ach_name', 'ach_desc', 'ach_icon'] as $k) {
        if (isset($_POST[$k]) || !$partial) {
            $out[$k] = (string)($_POST[$k] ?? '');
        }
    }
    foreach (['starts', 'ends'] as $k) {
        if (isset($_POST[$k]) || !$partial) {
            $v = trim((string)($_POST[$k] ?? ''));
            $out[$k] = $v === '' ? null : (int)$v;
        }
    }
    if (isset($_POST['closed']) || !$partial) {
        $out['closed'] = ($_POST['closed'] ?? '0') === '1';
    }
    if (isset($_POST['organizer'])) {
        $org = trim((string)$_POST['organizer']);
        if ($org !== '' && !Util::isValidId($org)) {
            Util::fail('invalid organizer');
        }
        $out['organizer'] = $org === '' ? null : $org;
    }
    if (isset($_POST['mode']) && in_array($_POST['mode'], ['upcoming', 'active'], true)) {
        $out['mode'] = (string)$_POST['mode'];
    }
    if (isset($_POST['monitor_allowed']) || !$partial) {
        $out['monitor_allowed'] = ($_POST['monitor_allowed'] ?? '1') === '1';
    }
    if (isset($_POST['monitor'])) {
        $mon = trim((string)$_POST['monitor']);
        if ($mon !== '' && !Util::isValidId($mon)) {
            Util::fail('invalid monitor');
        }
        $out['monitor'] = $mon === '' ? null : $mon;
    }
    return $out;
}

/**
 * The read-only payloads the dashboard polls, as data rather than as a
 * response. Several cards come due on the same tick, and an admin request
 * costs far more in fixed overhead than in the work it does - the includes,
 * the session, the database open - so 'batch' answers a whole tick in one
 * request. It reads them from here, out of the same function the single-card
 * cases use, so the two can never drift apart.
 */
function poll(string $action): ?array
{
    switch ($action) {
        case 'stats':
            return AdminData::stats();
        case 'conns':
            return ['now' => time(), 'online_window' => FOK_ONLINE_WINDOW + FOK_BEAT_JITTER,
                'conns' => ConnTrack::listPresence()];
        case 'duels':
            return ['now' => time(), 'duels' => ConnTrack::listDuels(),
                'tourneys' => Tournament::listLive()];
        case 'alerts':
            return ['unseen' => Alerts::unseenCount(), 'alerts' => Alerts::recent()];
        case 'load':
            return AdminData::hours();
        case 'load_min':
            return AdminData::minutes();
        case 'caps':
            return ['now' => time()] + Caps::withRequest(Caps::get());
        case 'events':
            return ['now' => time()] + EventAdmin::card();
        default:
            return null;
    }
}

/** Send an inline text/JSON body as a named download and stop. */
function download(string $filename, string $body, string $type = 'application/json'): never
{
    header('Content-Type: ' . $type);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $body;
    exit;
}

/**
 * The admin audit trail: one log line per state-changing action, naming what
 * was done, to what, and from where - the log's own timestamp says when. A
 * change nobody remembers making can then be traced to the call that made it.
 * Reads and downloads are deliberately absent: they change nothing, and
 * auditing them would bury the writes in noise.
 */
const AUDIT = [
    'set_debug' => 'set the client debug flag',
    'delete_player' => 'deleted player',
    'vault_reset' => 'reset the config-vault token of',
    'debug_delete' => 'deleted debug datasets',
    'delete_score' => 'deleted score',
    'alerts_seen' => 'marked the alerts seen',
    'alerts_clear' => 'cleared the alerts',
    'disputes_review' => 'marked the item disputes reviewed of',
    'caps_refresh' => 're-assessed the host capabilities',
    'log_clear' => 'cleared the server log',
    'clear_stats' => 'cleared the traffic statistics',
    'settings_save' => 'saved the settings',
    'config_import' => 'imported a settings file',
    'backup_create' => 'created a database backup',
    'backup_restore' => 'restored the database from an upload',
    'tourney_abort' => 'ended the tournament',
    'event_create' => 'opened the event',
    'event_edit' => 'edited the event',
    'event_run' => 'ran the event',
    'event_pause' => 'paused the event',
    'event_end' => 'ended the event',
    'event_roster' => 'changed the event roster of',
    'event_organizer' => 'named the organizer of',
    'event_delete' => 'deleted the event',
];
// The two that replace live state wholesale. A line in the log is not enough
// for these: an operator must find them on the dashboard without going
// looking, so they alert as well as being audited.
const AUDIT_ALERT = ['config_import', 'backup_restore'];

if (isset(AUDIT[$action])) {
    // Whatever names the target of this call, in the order the actions pass
    // it. It is client input, so it is cut down to a printable subset and
    // capped - a crafted field must not be able to forge log lines of its own.
    $target = (string)($_POST['eid'] ?? $_POST['id'] ?? $_POST['tid'] ?? $_POST['pins']
        ?? $_GET['id'] ?? '');
    $target = substr((string)preg_replace('/[^0-9a-zA-Z,_.-]/', '', $target), 0, 64);
    $what = AUDIT[$action] . ($target === '' ? '' : ' ' . $target);
    // Written when the response is on its way out, not here: an action that
    // gets rejected (a GET where POST is required, an invalid id) changed
    // nothing, and a trail claiming otherwise is worse than no trail.
    register_shutdown_function(static function () use ($action, $what): void {
        if (http_response_code() >= 400) {
            return;
        }
        Alerts::note('admin', $what . ' (from ' . Util::clientIp() . ')');
        if (in_array($action, AUDIT_ALERT, true)) {
            Alerts::raise('admin-' . $action, 'Admin ' . $what . ' - live state was replaced');
        }
    });
}

switch ($action) {
    // ---- dashboard cards (read-only) ----
    case 'stats':
    case 'load':
    case 'load_min':
        Util::jsonOut(['ok' => true] + poll($action));

    // A whole dashboard tick in one request: the cards that came due
    // together are named in 'of' and answered side by side, so the fixed
    // cost of an admin request is paid once instead of per card. Read-only
    // by construction - poll() knows the cards and nothing else.
    case 'batch':
        $out = ['ok' => true];
        foreach (array_slice(explode(',', (string)($_GET['of'] ?? '')), 0, 8) as $card) {
            $one = poll($card);
            if ($one === null) {
                Util::fail('not a pollable card');
            }
            $out[$card] = $one;
        }
        Util::jsonOut($out);

    case 'props':
        $ms = Util::nowMs();
        Util::jsonOut([
            'ok' => true,
            'pts_anchor' => '1970-01-01T00:00:00.000Z (unix epoch)',
            'utc_now' => gmdate('Y-m-d\TH:i:s', intdiv($ms, 1000)) . sprintf('.%03dZ', $ms % 1000),
            'pts_now' => $ms,
            'server_version' => FOK_SERVER_VERSION,
            'api_version' => FOK_API_VERSION,
            'env' => FOK_ENV,
            'php' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            // What the load-average alert is divided by (see Util::watch).
            'cores' => Util::cores(),
            // What the host actually gives the hot path. Shared hosting has
            // no shell and no phpinfo, so asking the running server is the
            // only way to find out - and each of these decides whether an
            // optimisation is available at all:
            //   opcache        - are the sources recompiled per request
            //   apcu           - is there shared memory between workers, the
            //                    prerequisite for keeping counters off the
            //                    single SQLite writer
            //   deferred_flush - can the response be handed over before the
            //                    bookkeeping runs (see Util::defer)
            'opcache' => extension_loaded('Zend OPcache') && (bool)ini_get('opcache.enable'),
            'apcu' => function_exists('apcu_enabled') && apcu_enabled(),
            'deferred_flush' => function_exists('fastcgi_finish_request'),
            'db_boot_us' => (int)round(Db::bootUs()),
        ]);

    case 'conns':
    case 'duels':
        Util::jsonOut(['ok' => true] + poll($action));

    // ---- clients ----
    case 'client':
        // One condensed, read-only view of everything known about a client,
        // gathered from the tables each subsystem already keeps (AdminData).
        $c = AdminData::client(requireId());
        if ($c === null) {
            Util::fail('unknown client', 404);
        }
        Util::jsonOut(['ok' => true] + $c);

    case 'set_debug':
        requirePost();
        // The wish only: the client honours it on its next hello and reports
        // back what it actually did (see the users card).
        Presence::setDebug(requireId('POST'), ($_POST['on'] ?? '') === '1');
        Util::jsonOut(['ok' => true]);

    case 'player_find':
        // Type-ahead for the two id fields on the event form. An operator
        // thinks in names and the schema is keyed on ids, so the form asks
        // here rather than making somebody copy an 8-hex string across.
        // Matches a name or an id prefix, newest-seen first, capped.
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q === '') {
            Util::jsonOut(['ok' => true, 'players' => []]);
        }
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $st = Db::get()->prepare("SELECT id, name, last_seen FROM players
            WHERE name LIKE ? ESCAPE '\' OR id LIKE ? ESCAPE '\'
            ORDER BY last_seen DESC LIMIT 20");
        $st->execute([$like, $like]);
        $found = [];
        foreach ($st->fetchAll() as $r) {
            $found[] = ['id' => (string)$r['id'], 'name' => $r['name'],
                'last_seen' => (int)$r['last_seen']];
        }
        $st->closeCursor();
        Util::jsonOut(['ok' => true, 'players' => $found]);

    case 'users':
        $db = Db::get();
        $total = (int)$db->query('SELECT COUNT(*) FROM players')->fetchColumn();
        $st = $db->query('SELECT id, name, ip, first_seen, last_seen, hello_count, latency, debug, debug_active FROM players ORDER BY last_seen DESC LIMIT 200');
        $users = Presence::overlay(array_map(static function (array $u) {
            $u['debug'] = (int)$u['debug'] === 1;
            $u['debug_active'] = (int)$u['debug_active'] === 1;
            return $u;
        }, $st->fetchAll()));
        Util::jsonOut(['ok' => true, 'total' => $total, 'online_window' => FOK_ONLINE_WINDOW + FOK_BEAT_JITTER,
            'now' => time(), 'users' => $users]);

    case 'delete_player':
        // Reads $_POST['id'], so a GET (no such field) fails as 'invalid id'
        // rather than deleting - that empty-id path is the guard here.
        $id = requireId('POST');
        // The player, their friendships and their presence go through the one
        // removal path the TTL sweep uses, so the two cannot disagree.
        Presence::forget($id);
        // Their item instances go too, which is where this path parts from the
        // sweep on purpose: expiry only says a player has been away, and their
        // property waits for them (see Presence::forget), while an operator
        // removing a client is taking it away. The ledger is append-only audit
        // and stays: it records that the instances existed and where they went.
        Db::get()->prepare('DELETE FROM items WHERE owner = ?')->execute([$id]);
        Util::jsonOut(['ok' => true]);

    // ---- config vault (per-client backup) ----
    case 'vault_export':
        // Manual recovery: download a client's config backup WITHOUT its
        // token, as the same snake-fok-backup.json the game imports.
        $id = requireId();
        $vault = Vault::peek($id);
        if ($vault === null) {
            Util::fail('no backup', 404);
        }
        download('snake-fok-backup-' . $id . '.json', $vault['payload']);

    case 'vault_reset':
        // Clear a client's backup token so it can re-enroll (its next backup
        // mints a fresh one); keeps the payload.
        requirePost();
        Util::jsonOut(['ok' => true, 'reset' => Vault::resetToken(requireId('POST'))]);

    // ---- debug datasets ----
    case 'debug_list':
        // ttl + now let the dashboard show when each one expires.
        Util::jsonOut(['ok' => true, 'now' => time(), 'ttl' => FOK_DEBUG_TTL,
            'datasets' => Debug::recent()]);

    case 'debug_get':
        // Download one dataset by its 4-digit PIN (the handle a user reads out).
        $pin = $_GET['pin'] ?? '';
        if (!preg_match('/^[0-9]{4}$/', $pin)) {
            Util::fail('invalid pin');
        }
        $ds = Debug::get($pin);
        if ($ds === null) {
            Util::fail('unknown pin', 404);
        }
        download('debug-' . $pin . '.json', $ds['payload']);

    case 'debug_delete':
        // Bulk-delete debug datasets by PIN (comma-separated).
        $pins = array_values(array_filter(
            explode(',', (string)($_POST['pins'] ?? '')),
            static fn($p) => preg_match('/^[0-9]{4}$/', $p) === 1
        ));
        Util::jsonOut(['ok' => true, 'deleted' => Debug::delete($pins)]);

    // ---- scores ----
    case 'scores':
        Util::jsonOut(['ok' => true, 'scores' => Scores::top()]);

    case 'delete_score':
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            Util::fail('invalid id');
        }
        Scores::delete($id);
        Util::jsonOut(['ok' => true]);

    // ---- alerts ----
    case 'alerts':
        Util::jsonOut(['ok' => true] + poll($action));

    case 'alerts_seen':
        requirePost();
        Alerts::markSeen();
        Util::jsonOut(['ok' => true]);

    case 'alerts_clear':
        requirePost();
        Util::jsonOut(['ok' => true, 'rows' => Alerts::clear()]);

    // ---- tournaments (see Tournament) ----
    case 'tourney':
        // One tournament in full, for the popup the Matches card opens: who
        // is seated, who is playing, and what it is waiting on. Read-only,
        // and inert - reading it runs no deadline (see Tournament::detail).
        $d = Tournament::detail(requireTid());
        if ($d === null) {
            Util::fail('unknown tournament', 404);
        }
        Util::jsonOut(['ok' => true] + $d);

    case 'tourney_abort':
        // The operator ends it for everyone. The release valve for a
        // tournament nobody is asking about any more: its deadlines are run
        // by a seated player's own request, so one everybody walked away
        // from stands where it stopped until its entry expires.
        requirePost();
        $res = Tournament::abort(requireTid('POST'));
        if ($res === null) {
            Util::fail('unknown tournament', 404);
        }
        if (($res['ok'] ?? false) !== true) {
            // A transition is already in flight on this one (503 busy): the
            // tournament is untouched, so this must not answer ok - the popup
            // would close on a tournament it did not end.
            Util::fail((string)($res['error'] ?? 'failed'), (int)($res['http'] ?? 500));
        }
        Util::jsonOut(['ok' => true]);

    // ---- events (see Events, EventAdmin) ----

    case 'events':
        Util::jsonOut(['ok' => true] + (array)poll('events'));

    case 'event':
        // One event in full, for the popup the Events card opens. Read-only,
        // and it carries no key: that exists on the print page alone.
        $d = EventAdmin::detail(requireEid());
        if ($d === null) {
            Util::fail('unknown event', 404);
        }
        Util::jsonOut(['ok' => true] + $d);

    case 'event_create':
        requirePost();
        $card = Events::create(adminEventFields());
        Util::jsonOut(['ok' => true, 'eid' => $card['eid']]);

    case 'event_edit':
        // The key is never edited: a new key is a new event, because the old
        // one is printed on something already in somebody's hands.
        requirePost();
        $eid = requireEid('POST');
        if (Events::card($eid) === null) {
            Util::fail('unknown event', 404);
        }
        $fields = adminEventFields(true);
        // Both of these move a ROW as well as a column, so neither goes
        // through the plain edit: an organizer is seated in its own event
        // and a reserved monitor stops being a participant.
        $monitor = array_key_exists('monitor', $fields) ? $fields['monitor'] : false;
        $organizer = array_key_exists('organizer', $fields) ? $fields['organizer'] : false;
        unset($fields['monitor'], $fields['organizer']);
        Events::edit($eid, $fields);
        if ($organizer !== false) {
            Events::setOrganizer($eid, $organizer);
        }
        if ($monitor !== false) {
            Events::setMonitorId($eid, $monitor);
        }
        Util::jsonOut(['ok' => true]);

    // run / pause / end. A SCHEDULED event walks itself, so only its end is
    // offered - an operator must be able to stop one, and nothing derived
    // can do that.
    case 'event_run':
    case 'event_pause':
    case 'event_end':
        requirePost();
        $eid = requireEid('POST');
        $card = Events::card($eid);
        if ($card === null) {
            Util::fail('unknown event', 404);
        }
        $mode = ['event_run' => 'active', 'event_pause' => 'paused',
                 'event_end' => 'ended'][$action];
        if (Events::isScheduled($card) && $mode !== 'ended') {
            Util::fail('scheduled', 409);
        }
        $was = Events::stateOf($card);
        Events::setMode($eid, $mode);
        $fresh = Events::card($eid);
        $state = $fresh === null ? $mode : Events::stateOf($fresh);
        if ($state !== $was) {
            EventView::announce($eid, ['event' => 'state', 'state' => $state]);
        }
        Util::jsonOut(['ok' => true, 'state' => $state]);

    case 'event_roster':
        // The organizer's own verb, same values, same path (Events::setMember)
        // - with ONE power more: setting 'member' on an id with no row creates
        // one, so an operator can seat somebody who never scanned anything.
        requirePost();
        $eid = requireEid('POST');
        if (Events::card($eid) === null) {
            Util::fail('unknown event', 404);
        }
        $peer = $_POST['id'] ?? null;
        if (!Util::isValidId($peer)) {
            Util::fail('invalid id');
        }
        $set = $_POST['set'] ?? null;
        if (!in_array($set, ['member', 'none', 'banned'], true)) {
            Util::fail('invalid set');
        }
        $was = Events::rowOf($eid, $peer);
        $changed = Events::setMember($eid, $peer, $set, 'admin');
        if ($changed && $set === 'member' && $was !== null && $was['state'] === 'pending') {
            Signals::send($eid, $peer, 'event', (string)json_encode(
                ['event' => 'accepted', 'eid' => $eid], JSON_UNESCAPED_SLASHES
            ));
        }
        Util::jsonOut(['ok' => true]);

    case 'event_organizer':
        // An event whose organizer expired keeps running on its schedule and
        // nobody can work its door until this names another.
        requirePost();
        $eid = requireEid('POST');
        if (Events::card($eid) === null) {
            Util::fail('unknown event', 404);
        }
        $peer = (string)($_POST['id'] ?? '');
        if ($peer !== '' && !Util::isValidId($peer)) {
            Util::fail('invalid id');
        }
        Events::setOrganizer($eid, $peer === '' ? null : $peer);
        Util::jsonOut(['ok' => true]);

    case 'event_delete':
        // Terminal, and it takes the archive with it - the record of every
        // evening the event ran. The one verb no organizer has.
        requirePost();
        $eid = requireEid('POST');
        if (Events::card($eid) === null) {
            Util::fail('unknown event', 404);
        }
        $members = EventAdmin::memberIds($eid);
        $gone = EventAdmin::delete($eid);
        foreach ($members as $mid) {
            Events::forgetMine($mid);
        }
        Util::jsonOut(['ok' => true] + $gone);

    // ---- item registry (see Items, Ledger) ----
    case 'items':
        Util::jsonOut(['ok' => true] + AdminData::items());

    case 'items_verify':
        // Walk the hash chain from the newest checkpoint forward and report
        // whether it is intact (and where it breaks if not). A read, but
        // POST-only so it is never triggered by a cross-site navigation.
        requirePost();
        Util::jsonOut(['ok' => true, 'verify' => Ledger::verify(Db::get())]);

    case 'item':
        // One instance in full: the registry row, what the ledger still holds
        // about it, and - for a frozen one - the verdict an operator has to
        // act on. Read-only; the acting is the case below.
        $uid = (string)($_GET['uid'] ?? '');
        if (!Items::isValidUid($uid)) {
            Util::fail('invalid uid');
        }
        $item = AdminData::item($uid);
        if ($item === null) {
            Util::fail('unknown item', 404);
        }
        Util::jsonOut(['ok' => true] + $item);

    case 'disputes':
        // Every tampering verdict recorded against one player, for the popup
        // the review queue opens. Read-only; both ways of acting on one are
        // separate cases (item_resolve for an instance still frozen,
        // disputes_review for the review itself).
        $pid = (string)($_GET['id'] ?? '');
        if (!Util::isValidId($pid)) {
            Util::fail('invalid id');
        }
        $d = AdminData::disputes($pid);
        if ($d === null) {
            Util::fail('unknown player', 404);
        }
        Util::jsonOut(['ok' => true] + $d);

    case 'disputes_review':
        // The operator has read this player's findings: take them off the
        // queue. It does NOT undo a verdict or move an item - the tally is
        // the forensic record and never moves backwards, and an instance
        // still frozen is released through item_resolve, deliberately as a
        // second decision.
        requirePost();
        $pid = (string)($_POST['id'] ?? '');
        if (!Util::isValidId($pid)) {
            Util::fail('invalid id');
        }
        if (!Items::reviewDisputes($pid)) {
            Util::fail('nothing to review', 409);
        }
        Util::jsonOut(['ok' => true]);

    case 'item_resolve':
        // The operator's verdict on a frozen instance: hand it to a player, or
        // drop it from the registry when no id is given. Only a FROZEN one can
        // be named, so this is the release valve for a terminal state and never
        // a second way to grant an item (see Items::resolve).
        requirePost();
        $uid = (string)($_POST['uid'] ?? '');
        if (!Items::isValidUid($uid)) {
            Util::fail('invalid uid');
        }
        $to = (string)($_POST['to'] ?? '');
        if ($to !== '' && !Util::isValidId($to)) {
            Util::fail('invalid id');
        }
        if (!Items::resolve($uid, $to)) {
            Util::fail('not a frozen instance', 409);
        }
        Util::jsonOut(['ok' => true]);

    // ---- host capabilities ----
    case 'caps':
        Util::jsonOut(['ok' => true] + poll('caps'));

    case 'caps_refresh':
        requirePost();   // re-assessment is a write
        Util::jsonOut(['ok' => true, 'now' => time()] + Caps::withRequest(Caps::refresh()));

    // ---- server log ----
    case 'log':
        Util::jsonOut(['ok' => true] + Logs::tail());

    case 'log_clear':
        requirePost();
        Logs::clear();
        Util::jsonOut(['ok' => true]);

    // Empties the traffic history the Server performance card is drawn from.
    // The item-registry buckets and the lifetime game totals share the table
    // and are deliberately not touched (see Counters::clearHistory).
    case 'clear_stats':
        requirePost();
        require_once __DIR__ . '/../src/Counters.php';
        Util::jsonOut(['ok' => true] + Counters::clearHistory());

    // ---- settings ----
    case 'settings':
        Util::jsonOut(['ok' => true, 'settings' => Settings::all()]);

    case 'housekeeping':
        Util::jsonOut(['ok' => true] + Housekeeping::report());

    case 'config_export':
        $map = [];
        foreach (Settings::all() as $s) {
            $map[$s['key']] = $s['value'];
        }
        download('fok-config.json', (string)json_encode($map, JSON_PRETTY_PRINT));

    case 'config_import':
        requirePost();
        $map = json_decode((string)($_POST['config'] ?? ''), true);
        if (!is_array($map) || $map === []) {
            Util::fail('invalid config JSON');
        }
        foreach ($map as $key => $value) {
            if (!is_string($key) || !isset(Settings::DEFS[$key])) {
                Util::fail("unknown setting $key");
            }
            if (!is_int($value) || $value < 0 || $value > 1000000000) {
                Util::fail("invalid value for $key");
            }
        }
        foreach ($map as $key => $value) {
            Settings::set($key, $value);
        }
        Util::jsonOut(['ok' => true, 'settings' => Settings::all()]);

    case 'settings_save':
        requirePost();
        foreach (Settings::DEFS as $key => $def) {
            if (!isset($_POST[$key])) {
                continue;
            }
            $value = filter_var($_POST[$key], FILTER_VALIDATE_INT);
            if ($value === false || $value < 0 || $value > 1000000000) {
                Util::fail("invalid value for $key");
            }
            Settings::set($key, $value);
        }
        Util::jsonOut(['ok' => true, 'settings' => Settings::all()]);

    // ---- database backups ----
    case 'backup_create':
        requirePost();
        Util::jsonOut(['ok' => true, 'name' => Backup::create()]);

    case 'backup_list':
        Util::jsonOut(['ok' => true, 'backups' => Backup::list()]);

    case 'backup_download':
        $name = $_GET['file'] ?? '';
        if (!Backup::isValidName($name) || !is_file(FOK_BACKUP_DIR . '/' . $name)) {
            Util::fail('unknown backup', 404);
        }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . (string)filesize(FOK_BACKUP_DIR . '/' . $name));
        readfile(FOK_BACKUP_DIR . '/' . $name);
        exit;

    case 'backup_restore':
        if (!isset($_FILES['db']) || $_FILES['db']['error'] !== UPLOAD_ERR_OK) {
            Util::fail('upload failed');
        }
        try {
            $snapshot = Backup::restore($_FILES['db']['tmp_name']);
        } catch (RuntimeException $e) {
            Util::fail($e->getMessage());
        }
        Util::jsonOut(['ok' => true, 'snapshot' => $snapshot]);

    default:
        Util::fail('unknown action', 404);
}
