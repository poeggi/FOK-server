<?php
declare(strict_types=1);

// Unit tests for the src/ classes, run against a throwaway data dir.
// No framework: assert() with zend.assertions, exit 1 on any failure.

$tmp = sys_get_temp_dir() . '/fok-test-' . getmypid();
putenv('FOK_DATA_DIR=' . $tmp);
// A failed run exits before its own cleanup below, and process ids are
// recycled - so a later run can open a database somebody else already
// populated and fail on assertions that count rows. Start from nothing.
foreach (glob($tmp . '/*') ?: [] as $f) {
    if (is_file($f)) {
        unlink($f);
    }
}
ini_set('zend.assertions', '1');
ini_set('assert.exception', '1');

require_once __DIR__ . '/../public/src/Util.php';
require_once __DIR__ . '/../public/src/Presence.php';
require_once __DIR__ . '/../public/src/Scores.php';
require_once __DIR__ . '/../public/src/Signals.php';
require_once __DIR__ . '/../public/src/Auth.php';
require_once __DIR__ . '/../public/src/Backup.php';
require_once __DIR__ . '/../public/src/Matchmaking.php';
require_once __DIR__ . '/../public/src/Starts.php';
require_once __DIR__ . '/../public/src/Friends.php';
require_once __DIR__ . '/../public/src/RelayRate.php';
require_once __DIR__ . '/../public/src/ConnTrack.php';
require_once __DIR__ . '/../public/src/Caps.php';
require_once __DIR__ . '/../public/src/RelayStore.php';
require_once __DIR__ . '/../public/src/Relay.php';
require_once __DIR__ . '/../public/src/Load.php';
require_once __DIR__ . '/../public/src/Vault.php';
require_once __DIR__ . '/../public/src/PStats.php';
require_once __DIR__ . '/../public/src/Debug.php';
require_once __DIR__ . '/../public/src/Ledger.php';
require_once __DIR__ . '/../public/src/Items.php';
require_once __DIR__ . '/../public/src/Bracket.php';
require_once __DIR__ . '/../public/src/Tournament.php';

// Util installs a fault handler that answers 500 and exits 0 - right for a
// request, fatal for a test run, where it would swallow a throwable (a
// renamed method, a type error) and let the suite pass blind. Override it:
// anything that escapes a test must FAIL the run loudly.
set_exception_handler(static function (Throwable $e): void {
    fwrite(STDERR, "UNCAUGHT: $e\n");
    exit(1);
});

$tests = 0;
function ok(bool $cond, string $what): void
{
    global $tests;
    $tests++;
    if (!$cond) {
        fwrite(STDERR, "FAIL: $what\n");
        exit(1);
    }
}

// Util: player ID validation
ok(Util::isValidId('c0ffee42'), 'valid id accepted');
ok(!Util::isValidId('C0FFEE42'), 'uppercase id rejected');
ok(!Util::isValidId('c0ffee4'), 'short id rejected');
ok(!Util::isValidId('c0ffee421'), 'long id rejected');
ok(!Util::isValidId(12345678), 'non-string id rejected');
ok(!Util::isValidId(null), 'null id rejected');

// Util: TLS floor - only a positively pre-1.2 transport is refused.
ok(Util::tlsBelow12('TLSv1') === true, 'TLS 1.0 is below the floor');
ok(Util::tlsBelow12('TLSv1.0') === true, 'TLS 1.0 (dotted) is below the floor');
ok(Util::tlsBelow12('TLSv1.1') === true, 'TLS 1.1 is below the floor');
ok(Util::tlsBelow12('SSLv3') === true, 'SSLv3 is below the floor');
ok(Util::tlsBelow12('TLSv1.2') === false, 'TLS 1.2 is accepted');
ok(Util::tlsBelow12('TLSv1.3') === false, 'TLS 1.3 is accepted');
ok(Util::tlsBelow12('TLSv2.0') === false, 'a future TLS is accepted');
ok(Util::tlsBelow12('') === false, 'an absent/unknown protocol is fail-open');

// Util: address-family classification for the peer-net hint.
ok(Util::ipInfo('1.2.3.4') === ['ip' => '1.2.3.4', 'family' => 4], 'ipv4 classified as family 4');
ok(Util::ipInfo('2a01:db8::5') === ['ip' => '2a01:db8::5', 'family' => 6], 'ipv6 classified as family 6');
ok(Util::ipInfo('::ffff:1.2.3.4') === ['ip' => '1.2.3.4', 'family' => 4], 'ipv4-mapped ipv6 unwrapped to family 4');
ok(Util::ipInfo('?')['family'] === 0, 'an unknown address is family 0');

// Presence: registration and counting
Presence::touch('aaaaaaaa', '1.2.3.4');
Presence::touch('bbbbbbbb', '5.6.7.8');
Presence::touch('aaaaaaaa', '1.2.3.9');
$c = Presence::counts();
ok($c['registered'] === 2, 'touch twice registers once');
ok($c['online'] === 2, 'both players online');
ok($c['playing'] === 0, 'no duels yet');

// Presence: the counters are CACHED - every hello returns them, so they
// must never be counted per request. Staleness up to FOK_COUNTS_TTL is
// the deliberate price. Written in behind the cache, so a recount is the
// only thing that could notice.
function freshCounts(): array
{
    Presence::flushCounts();
    return Presence::counts();
}
Presence::counts();
Db::get()->prepare('INSERT INTO players (id, ip, first_seen, last_seen, hello_count) VALUES (?, ?, ?, ?, 1)')
    ->execute(['eeee0001', '9.9.9.9', time(), time()]);
ok(Presence::counts()['registered'] === 2, 'repeat heartbeats are served from the cache');
ok(freshCounts()['registered'] === 3, 'counters recount once the cache goes stale');
// ... but a player joining must show up at once: nobody may watch their
// own first hello report zero online.
Presence::touch('dddddddd', '9.9.9.9');
ok(Presence::counts()['registered'] === 4, 'a new registration refreshes the counters at once');
Db::get()->exec("DELETE FROM players WHERE id IN ('eeee0001', 'dddddddd')");
Presence::flushCounts();

// Presence: duel pair is normalized, refresh from either side
Presence::touchDuel('bbbbbbbb', 'aaaaaaaa');
Presence::touchDuel('aaaaaaaa', 'bbbbbbbb');
$c = freshCounts();
ok($c['playing'] === 2, 'one duel counts two playing');

// Scores: parity with the FOK-snake local top-10 entry shape
$rank = Scores::submit('aaaaaaaa', '  TESTER  ', 100, 3, 2, 5, '{"hat":1}', 42, '[[1,2]]');
ok($rank === 1, 'first score ranks 1');
$rank = Scores::submit('bbbbbbbb', '', 200, 4, 1, 0, '{}', null, null);
ok($rank === 1, 'higher score takes rank 1');
$top = Scores::top();
ok(count($top) === 3, 'two submissions plus the seed entry');
ok($top[0]['name'] === 'ANONYMOUS', 'empty name becomes ANONYMOUS');
ok($top[1]['name'] === 'TESTER', 'name is trimmed');
ok($top[2]['name'] === 'SNAKE PLISSKEN', 'fresh db seeded with default entry');
ok($top[2]['score'] === 82, 'seed entry has 82 points');
ok($top[2]['date'] === '26.11.97', 'seed entry keeps the classic date');
foreach (['rank', 'player_id', 'name', 'score', 'level', 'diff', 'color', 'shopItems', 'completed', 'platform', 'date', 'created'] as $field) {
    ok(array_key_exists($field, $top[0]), "entry has $field");
}
ok($top[1]['color'] === 5, 'color preserved');
ok(is_object($top[1]['shopItems']) && $top[1]['shopItems']->hat === 1, 'shopItems preserved as object');
ok(preg_match('/^\d{2}\.\d{2}\.\d{2}$/', $top[0]['date']) === 1, 'date is DD.MM.YY');
ok($top[0]['completed'] === false, 'a score defaults to not completed');
ok($top[0]['platform'] === null, 'a score defaults to no platform');
$rank = Scores::submit('cccccccc', 'FINISHER', 500, 10, 1, 0, '{}', null, null, true);
ok(Scores::top()[0]['completed'] === true, 'a run that cleared the final level is marked completed');
ok(Scores::top()[1]['completed'] === false, 'a same-level run that did not finish stays not completed');
$long = Scores::submit('aaaaaaaa', str_repeat('X', 40), 1, 1, 1, 0, '{}', null, null, false, 'pc');
ok(mb_strlen(Scores::top()[4]['name']) === FOK_MAX_NAME_LEN, 'name capped at max length');
ok(Scores::top()[4]['platform'] === 'pc', 'a reported platform round-trips');

// Presence: targeted online + latency info
$info = Presence::infoOf(['aaaaaaaa', 'cccccccc']);
ok(($info['aaaaaaaa']['online'] ?? false) === true, 'known player reported online');
ok(!isset($info['cccccccc']), 'unknown player not in info map');
ok(Presence::infoOf([]) === [], 'empty friend list is fine');
ok($info['aaaaaaaa']['latency'] === null, 'no latency before first report');

// Presence: latency reports stick and average
Presence::touch('aaaaaaaa', '1.2.3.9', 40);
Presence::touch('bbbbbbbb', '5.6.7.8', 20);
Presence::touch('aaaaaaaa', '1.2.3.9');
$info = Presence::infoOf(['aaaaaaaa']);
ok($info['aaaaaaaa']['latency'] === 40, 'latency kept when a report omits it');

// Presence: names are recorded and kept
Presence::touch('aaaaaaaa', '1.2.3.9', null, 'ALPHA');
Presence::touch('aaaaaaaa', '1.2.3.9');
$info = Presence::infoOf(['aaaaaaaa', 'bbbbbbbb']);
ok($info['aaaaaaaa']['name'] === 'ALPHA', 'name recorded and kept when omitted');
ok($info['bbbbbbbb']['name'] === null, 'no name until reported');

// Friendships: handshake, auto-match, gating helpers, removal
$r = Friends::request('aaaaaaaa', 'bbbbbbbb');
ok($r['state'] === 'pending' && $r['changed'] === true, 'first request is pending and new');
$r = Friends::request('aaaaaaaa', 'bbbbbbbb');
ok($r['state'] === 'pending' && $r['changed'] === false, 'repeat request changes nothing (no re-notification)');
ok(!Friends::isFriend('aaaaaaaa', 'bbbbbbbb'), 'pending is not a friendship');
ok(!Friends::accept('aaaaaaaa', 'bbbbbbbb'), 'requester cannot accept own request');
$list = Friends::listOf('bbbbbbbb');
ok(count($list) === 1 && $list[0]['state'] === 'pending' && $list[0]['outgoing'] === false,
    'peer sees the incoming request');
ok(Friends::accept('bbbbbbbb', 'aaaaaaaa'), 'peer accepts the request');
ok(Friends::isFriend('aaaaaaaa', 'bbbbbbbb'), 'accepted friendship recognized both ways');
ok(Friends::acceptedOf('aaaaaaaa', ['bbbbbbbb', 'cccccccc']) === ['bbbbbbbb' => true],
    'acceptedOf filters to recorded friends');
Friends::remove('bbbbbbbb', 'aaaaaaaa');
ok(!Friends::isFriend('aaaaaaaa', 'bbbbbbbb'), 'removal deletes the friendship');
$r1 = Friends::request('11117777', '22227777');
$r2 = Friends::request('22227777', '11117777');
ok($r1['state'] === 'pending' && $r2['state'] === 'accepted' && $r2['changed'] === true,
    'crossing requests auto-match into a friendship');
Friends::remove('11117777', '22227777');

// Auto-accept while the peer is on the QR/add-friend screen
Presence::touch('bbbbbbbb', '5.6.7.8', null, null, true);
ok(Presence::isAutoAccepting('bbbbbbbb'), 'auto-accept flag set via touch');
$r = Friends::request('aaaaaaaa', 'bbbbbbbb');
Friends::forceAccept('aaaaaaaa', 'bbbbbbbb');
ok(Friends::isFriend('aaaaaaaa', 'bbbbbbbb'), 'forceAccept completes a pending handshake');
Friends::remove('aaaaaaaa', 'bbbbbbbb');
Presence::touch('bbbbbbbb', '5.6.7.8', null, null, false);
ok(!Presence::isAutoAccepting('bbbbbbbb'), 'hello without the flag clears auto-accept');
Presence::touch('bbbbbbbb', '5.6.7.8');
ok(!Presence::isAutoAccepting('bbbbbbbb'), 'null leaves the cleared flag untouched');

// Existence feedback (see Friends::exists, friend.php): true once a player
// row exists, false for an id never seen. Backs the exists:false reply that
// catches a mistyped peer id instead of recording a dead pending row.
ok(Friends::exists('aaaaaaaa'), 'exists() true for a registered id');
ok(!Friends::exists('deadface'), 'exists() false for an id never seen');

// Per-id request throttle (see Friends::rateHit): the anti-probe guard.
// Integer-second granularity, so a unit run inside one second exercises the
// interval and burst branches directly. The prober needs a players row (the
// live endpoint touches it before calling rateHit).
Presence::touch('f1f10001', '7.7.7.7');
Settings::set('friend_rate_interval', 1);
Settings::set('friend_rate_burst', 1000);
Settings::set('friend_rate_cooldown', 60);
ok(Friends::rateHit('f1f10001')['blocked'] === false, 'first request passes the throttle');
$g = Friends::rateHit('f1f10001');
ok($g['blocked'] === true && $g['why'] === 'interval' && $g['retry'] === 1,
    'a second request in the same second is too fast');
// Burst -> cooldown: a fresh prober, interval off so only the streak counts.
Presence::touch('f1f10002', '7.7.7.8');
Settings::set('friend_rate_interval', 0);
Settings::set('friend_rate_burst', 3);
$tripped = false;
for ($i = 0; $i < 4; $i++) {
    $g = Friends::rateHit('f1f10002');
    $tripped = $tripped || $g['tripped'];
}
ok($g['blocked'] === true && $g['why'] === 'cooldown' && $g['retry'] === 60,
    'a burst of requests trips the cooldown');
ok($tripped === true, 'the tripping request is flagged (drives the server-log warning)');
$g = Friends::rateHit('f1f10002');
ok($g['blocked'] === true && $g['tripped'] === false && $g['why'] === 'cooldown',
    'requests during the cooldown stay blocked and do not re-trip');
// A real pause (idle a whole cooldown) clears the streak: rewind last far
// enough, drop the cooldown, and the next request is allowed again.
Db::get()->prepare('UPDATE players SET friend_req_cooldown_until = 0, friend_req_last = ? WHERE id = ?')
    ->execute([time() - 120, 'f1f10002']);
ok(Friends::rateHit('f1f10002')['blocked'] === false, 'an idle gap of a cooldown clears the streak');
// Escalation: a second burst trip within the repeat window earns the long
// cooldown, not the short one (see Friends::rateHit). Burst once to set the
// last-trip marker, then rewind so the streak clears and the cooldown lifts
// but the marker stays recent, and burst again - now it escalates.
Presence::touch('f1f10003', '7.7.7.9');
Settings::set('friend_rate_interval', 0);
Settings::set('friend_rate_burst', 3);
Settings::set('friend_rate_cooldown', 60);
Settings::set('friend_rate_repeat_window', 600);
Settings::set('friend_rate_cooldown_hard', 3600);
for ($i = 0; $i < 4; $i++) {
    $g = Friends::rateHit('f1f10003');
}
ok($g['tripped'] === true && $g['escalated'] === false && $g['retry'] === 60,
    'the first burst trips the short cooldown, not escalated');
// Clear the streak and lift the cooldown, but keep the last-trip marker.
Db::get()->prepare('UPDATE players SET friend_req_cooldown_until = 0, friend_req_last = ? WHERE id = ?')
    ->execute([time() - 120, 'f1f10003']);
for ($i = 0; $i < 4; $i++) {
    $g = Friends::rateHit('f1f10003');
}
ok($g['tripped'] === true && $g['escalated'] === true && $g['retry'] === 3600,
    'a second burst within the repeat window escalates to the long cooldown');
Settings::set('friend_rate_interval', 1);
Settings::set('friend_rate_burst', 10);
Settings::set('friend_rate_cooldown', 60);

// Player expiry: stale players removed, friendships cancelled + notified
Settings::set('player_ttl_days', 1);
Presence::touch('dddd0001', '9.9.9.1');
Presence::touch('eeee0002', '9.9.9.2');
Friends::request('dddd0001', 'eeee0002');
Friends::accept('eeee0002', 'dddd0001');
Db::get()->prepare('UPDATE players SET last_seen = ? WHERE id = ?')
    ->execute([time() - 2 * 86400, 'dddd0001']);
ok(Presence::expireStale() === 1, 'stale player expired');
ok(Presence::infoOf(['dddd0001']) === [], 'expired player gone from the database');
ok(!Friends::isFriend('dddd0001', 'eeee0002'), 'friendship cancelled on expiry');
$got = Signals::take('eeee0002');
ok(count($got) === 1 && $got[0]['type'] === 'friend' && str_contains($got[0]['payload'], 'expired'),
    'friend notified of the expiry');
Settings::set('player_ttl_days', 0);
ok(Presence::expireStale() === 0, 'ttl 0 disables expiry');
Settings::set('player_ttl_days', 180);

// Matchmaking: first seeker waits, second gets matched, roles assigned
ok((Matchmaking::seek('11111111')['waiting'] ?? false) === true, 'first seeker waits');
$m = Matchmaking::seek('22222222');
ok(($m['matched'] ?? '') === '11111111', 'second seeker matched with first');
ok(($m['role'] ?? '') === 'answerer', 'newcomer is answerer');
$m = Matchmaking::seek('11111111');
ok(($m['matched'] ?? '') === '22222222', 'first seeker learns match on next poll');
ok(($m['role'] ?? '') === 'offerer', 'longer-waiting seeker is offerer');
ok((Matchmaking::seek('11111111')['waiting'] ?? false) === true, 'queue empty after delivery');
Matchmaking::cancel('11111111');
ok((Matchmaking::seek('33333333')['waiting'] ?? false) === true, 'cancelled seeker not matched');
Matchmaking::cancel('33333333');

// Matchmaking: a stale seeker (stopped polling) is never handed out as a
// match. Correctness rides on the peer-select's liveness predicate, not on
// the prune - which now runs on only a sampled fraction of seeks - so a live
// seeker keeps waiting whether or not this poll happened to prune the ghost.
Db::get()->exec('DELETE FROM mm_queue');
Db::get()->prepare('INSERT INTO mm_queue (id, since, last_poll) VALUES (?, ?, ?)')
    ->execute(['44444444', time() - 20, time() - 20]);
ok((Matchmaking::seek('55555555')['waiting'] ?? false) === true,
    'a stale seeker is never offered as a match');
Matchmaking::cancel('55555555');
Db::get()->exec('DELETE FROM mm_queue');

// Server-issued starts: both peers NAME the epoch, so the answer never
// depends on when either of them asks
$s1 = Starts::request('aaaaaaaa', 'bbbbbbbb', 0, 'first');
$s2 = Starts::request('bbbbbbbb', 'aaaaaaaa', 0, 'first');
ok($s1 === $s2, 'both peers receive the identical start pts');
ok($s1 > Util::nowMs(), 'start pts lies in the future');
ok($s1 <= Util::nowMs() + 3000, 'start lead is capped');

// The race a pair-only key lost: a peer whose request lands after the
// moment it is asking about must still be told THAT moment. Handing it a
// fresh one instead put the two players on different origins silently.
$passed = Util::nowMs() - 1000;
Db::get()->prepare('UPDATE starts SET start_pts = ? WHERE a = ? AND b = ?')
    ->execute([$passed, 'aaaaaaaa', 'bbbbbbbb']);
$late = Starts::request('bbbbbbbb', 'aaaaaaaa', 0, 'first');
ok($late === $passed, 'a late peer gets the same start, already in the past');

// Every halt of the run is a new epoch, and a new epoch is a new moment.
$s4 = Starts::request('aaaaaaaa', 'bbbbbbbb', 1, 'respawn');
ok($s4 > Util::nowMs(), 'a new epoch issues a fresh start');
ok(Starts::request('bbbbbbbb', 'aaaaaaaa', 1, 'respawn') === $s4, 'the peer joins the new epoch');

// A peer left behind WITHIN a run is told so, never handed a start it would
// misplace. An in-run reason (level/respawn/resume) is gated; a begin-play
// reason is exempt (see the reset test below), so this probes with 'level'.
ok(Starts::request('bbbbbbbb', 'aaaaaaaa', 0, 'level') === null, 'a stale in-run epoch is refused');

$startRow = (function (): array {
    $st = Db::get()->prepare('SELECT epoch, reason FROM starts WHERE a = ? AND b = ?');
    $st->execute(['aaaaaaaa', 'bbbbbbbb']);
    return $st->fetch();
})();
ok((int)$startRow['epoch'] === 1 && $startRow['reason'] === 'respawn', 'the pair records epoch and reason');
ok(in_array('resume', Starts::REASONS, true), 'a resume from pause is a start reason');

// A start that BEGINS play (first/rematch) must never be refused by a stale
// epoch line left over from a torn-down connection: a relay rematch reuses the
// hub with no new offer, so nothing calls Starts::forget (see signal.php), and
// the pair would otherwise sit at a 409 until the row aged out. The pair is at
// epoch 1 here; a fresh 'rematch' at epoch 0 RESETS the line rather than 409.
$reset = Starts::request('aaaaaaaa', 'bbbbbbbb', 0, 'rematch');
ok(is_int($reset) && $reset > Util::nowMs(), 'a begin-play start resets a stale epoch line instead of 409');
ok(Starts::request('bbbbbbbb', 'aaaaaaaa', 0, 'rematch') === $reset, 'and the peer joins the reset line');

// The epoch counts halts within ONE connection, so the pair's next duel
// opens at epoch 0 again instead of being refused forever. The reset hangs
// off the handshake, NOT off bye: a P2P bye goes over the DataChannel and
// the server never sees it (see signal.php), so a rematch would otherwise
// hit the finished line and 409 until the row aged out.
Starts::forget('aaaaaaaa', 'bbbbbbbb');
$again = Starts::request('aaaaaaaa', 'bbbbbbbb', 0, 'first');
ok($again > Util::nowMs(), 'a rematch on a fresh epoch line gets a start');
// Pair-scoped: bye is not friendship-gated, so a stranger saying bye must
// not reach a duel it has nothing to do with.
Starts::request('aaaaaaaa', 'bbbbbbbb', 1, 'level');
Starts::forget('aaaaaaaa', 'cccccccc');
ok(Starts::request('bbbbbbbb', 'aaaaaaaa', 0, 'level') === null, "a stranger's bye leaves the pair's epoch alone");

// Force the database relay transport (relay_apcu=0) so this single-process
// test exercises its exactly-once/ordering deterministically, whether or not
// the CLI has APCu. The APCu transport is exercised by the staging smoke run
// on real FPM (test/smoke/05_admin.sh).
Settings::set('relay_apcu', 0);

// RelayRate: the relay table is drained on delivery, so the send rate is
// tracked as a running total per client. mark_time is pre-set so a full
// slice has already passed and the very next record() checks the rate.
Db::get()->prepare('INSERT INTO relay_rate (id, total, mark_total, mark_time, blocked_until) VALUES (?, ?, ?, ?, 0)')
    ->execute(['dddddddd', 1000, 0, time() - 3]);
RelayRate::record('dddddddd'); // ~334 msg/s over 3 s, far over the 128 default
ok(RelayRate::blocked('dddddddd'), 'a client over the sustained relay rate is blocked');
ok(!RelayRate::usesApcu(), 'rate-limiting follows the database transport when APCu is off');
ok(RelayRate::totalOf('dddddddd') === 1001, 'the running message total is readable for the admin gauge');
Db::get()->prepare('INSERT INTO relay_rate (id, total, mark_total, mark_time, blocked_until) VALUES (?, ?, ?, ?, 0)')
    ->execute(['eeeeeeee', 10, 0, time() - 3]);
RelayRate::record('eeeeeeee'); // ~3 msg/s, comfortably under the cap
ok(!RelayRate::blocked('eeeeeeee'), 'a client under the sustained relay rate is not blocked');
ok(!RelayRate::blocked('ffffffff'), 'an unseen client is never blocked');

// RelayStore on the database transport (forced above): exactly-once and
// ordered, and push() reports success so the caller does not turn it into a
// 503.
ok(!RelayStore::usingApcu(), 'the relay uses the database transport when APCu is off');
ok(RelayStore::push('11111111', '22222222', 'IN:1') === true, 'a relayed message enqueues');
RelayStore::push('11111111', '22222222', 'IN:2');
ok(RelayStore::hasAny('22222222', '11111111'), 'the receiver sees a pending message');
ok(!RelayStore::hasAny('11111111', '22222222'), 'the sender has nothing pending back');
ok(RelayStore::pending('22222222', '11111111') === 2, 'pending counts the receiver backlog from the sender');
ok(RelayStore::shouldTrackRelay('11111111', '22222222', time()),
    'the database transport tracks the pair on every message');
$drained = RelayStore::drain('22222222', '11111111');
ok(count($drained) === 2 && $drained[0]['payload'] === 'IN:1' && $drained[1]['payload'] === 'IN:2',
    'the backlog drains oldest first');
// created is exposed in whole seconds; age is ms the message spent on the server.
ok($drained[0]['created'] >= time() - 2 && $drained[0]['created'] <= time(),
    'created is exposed in whole seconds');
ok($drained[0]['age'] >= 0 && $drained[0]['age'] < 5000, 'age is milliseconds on the server');
ok(RelayStore::drain('22222222', '11111111') === [], 'a drained backlog is empty (exactly-once)');
ok(RelayStore::pending('22222222', '11111111') === 0, 'a drained backlog is no longer pending');

// The debug flag: the admin's wish and the client's report are separate
ok(Presence::touch('eeeeeeee', '1.2.3.4') === false, 'debug is off for a new player');
Presence::setDebug('eeeeeeee', true);
ok(Presence::touch('eeeeeeee', '1.2.3.4') === true, 'the server hands the wish back on hello');
$dbgOf = function (string $id): array {
    $st = Db::get()->prepare('SELECT debug, debug_active FROM players WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch();
};
ok((int)$dbgOf('eeeeeeee')['debug_active'] === 0, 'the wish alone does not mark the client active');
Presence::touch('eeeeeeee', '1.2.3.4', null, null, null, true);
ok((int)$dbgOf('eeeeeeee')['debug_active'] === 1, 'the client reports it honoured the wish');
Presence::setDebug('eeeeeeee', false);
ok(Presence::touch('eeeeeeee', '1.2.3.4', null, null, null, true) === false, 'the wish can be withdrawn');
ok((int)$dbgOf('eeeeeeee')['debug_active'] === 1, 'a client debugging by itself still reports active');
// Endpoints other than hello pass null and must not clear the report.
Presence::touch('eeeeeeee', '1.2.3.4');
ok((int)$dbgOf('eeeeeeee')['debug_active'] === 1, 'a non-hello touch leaves the debug report alone');

// Signals: mailbox drains exactly once, order preserved
ok(!Signals::any('bbbbbbbb'), 'any() false on empty mailbox');
Signals::send('aaaaaaaa', 'bbbbbbbb', 'invite', 'hi');
ok(Signals::any('bbbbbbbb'), 'any() true with pending signal');
Signals::send('aaaaaaaa', 'bbbbbbbb', 'ice', 'cand1');
ok(Signals::take('aaaaaaaa') === [], 'no signals for sender');
$got = Signals::take('bbbbbbbb');
ok(count($got) === 2, 'both signals delivered');
ok($got[0]['type'] === 'invite' && $got[1]['type'] === 'ice', 'oldest first');
ok($got[0]['from'] === 'aaaaaaaa', 'sender reported');
ok(Signals::take('bbbbbbbb') === [], 'mailbox drained on read');

// Signals: mailbox flood cap
for ($i = 0; $i < FOK_MAILBOX_CAP; $i++) {
    ok(Signals::send('aaaaaaaa', 'bbbbbbbb', 'ice', "c$i"), "send $i under cap accepted");
}
ok(!Signals::send('aaaaaaaa', 'bbbbbbbb', 'ice', 'over'), 'send over mailbox cap rejected');
ok(count(Signals::take('bbbbbbbb')) === FOK_MAILBOX_CAP, 'capped mailbox drains fully');

// Signals: expired messages are dropped, but an invite that expires
// UNDELIVERED must fail loudly back to the sender, never just evaporate.
Db::get()->prepare('INSERT INTO signals (from_id, to_id, type, payload, created) VALUES (?, ?, ?, ?, ?)')
    ->execute(['aaaaaaaa', 'bbbbbbbb', 'invite', 'old', time() - FOK_SIGNAL_TTL - 1]);
ok(Signals::take('bbbbbbbb') === [], 'expired signal not delivered');
$receipt = Signals::take('aaaaaaaa');
ok(count($receipt) === 1, 'sender gets a receipt for the expired invite');
ok($receipt[0]['type'] === 'undelivered', 'receipt is an undelivered signal');
ok($receipt[0]['from'] === 'bbbbbbbb', 'receipt names the peer that never picked it up');
ok(str_contains($receipt[0]['payload'], '"type":"invite"'), 'receipt names the lost message type');

// Signals: the receipt must survive a FULL mailbox - a flood must not be
// able to swallow the one message that says the connection failed.
Db::get()->prepare('INSERT INTO signals (from_id, to_id, type, payload, created) VALUES (?, ?, ?, ?, ?)')
    ->execute(['aaaaaaaa', 'bbbbbbbb', 'invite', 'old', time() - FOK_SIGNAL_TTL - 1]);
for ($i = 0; $i < FOK_MAILBOX_CAP; $i++) {
    Signals::send('cccccccc', 'aaaaaaaa', 'ice', "flood$i");
}
ok(!Signals::send('cccccccc', 'aaaaaaaa', 'ice', 'over'), 'mailbox really is full');
$flooded = Signals::take('aaaaaaaa');
ok(count(array_filter($flooded, static fn(array $s) => $s['type'] === 'undelivered')) === 1,
    'receipt is delivered even past a full mailbox');
Signals::take('bbbbbbbb');

// Signals: an expiring message nobody waits on generates no receipt
Db::get()->prepare('INSERT INTO signals (from_id, to_id, type, payload, created) VALUES (?, ?, ?, ?, ?)')
    ->execute(['aaaaaaaa', 'bbbbbbbb', 'ice', 'old', time() - FOK_SIGNAL_TTL - 1]);
ok(Signals::take('bbbbbbbb') === [], 'expired ice not delivered');
ok(Signals::take('aaaaaaaa') === [], 'no receipt for an expired ice candidate');

// Signals: an expired message must not wake a long poll (any() and take()
// have to agree on the TTL, or poll.php answers 200 with an empty list)
Db::get()->prepare('INSERT INTO signals (from_id, to_id, type, payload, created) VALUES (?, ?, ?, ?, ?)')
    ->execute(['aaaaaaaa', 'bbbbbbbb', 'ice', 'old', time() - FOK_SIGNAL_TTL - 1]);
ok(!Signals::any('bbbbbbbb'), 'expired signal does not count as pending');
Signals::take('bbbbbbbb');

// ConnTrack: the duel state both peers are in, inferred from the
// signaling traffic the server relays anyway. A client shows on the Duels
// card only while it is in a duel phase (listDuels); presence - every
// online client - is a separate, fuller list (listPresence).
function duelOf(string $id): array
{
    foreach (ConnTrack::listDuels() as $c) {
        if ($c['id'] === $id) {
            return $c;
        }
    }
    return [];
}
function onPresence(string $id): bool
{
    foreach (ConnTrack::listPresence() as $c) {
        if ($c['id'] === $id) {
            return true;
        }
    }
    return false;
}
ok(duelOf('aaaaaaaa') === [], 'an untracked client is not on the Duels card');
ok(onPresence('aaaaaaaa'), 'but every online client is on the presence card');
ConnTrack::note('aaaaaaaa', 'bbbbbbbb', 'invite');
ok(duelOf('aaaaaaaa')['state'] === 'inviting', 'inviter is inviting');
ok(duelOf('aaaaaaaa')['peer'] === 'bbbbbbbb', 'inviter tracks its peer');
ok(duelOf('bbbbbbbb')['state'] === 'invited', 'invited peer sees the invite');
ok(duelOf('bbbbbbbb')['peer'] === 'aaaaaaaa', 'invited peer tracks the inviter');
ok(duelOf('aaaaaaaa')['mode'] === 'p2p', 'plain invite means p2p');
ok(onPresence('aaaaaaaa'), 'a dueling client is still on the presence card too');
ConnTrack::note('bbbbbbbb', 'aaaaaaaa', 'accept');
ok(duelOf('aaaaaaaa')['state'] === 'connecting', 'accept moves both to connecting');
ok(duelOf('bbbbbbbb')['state'] === 'connecting', 'accepting peer is connecting too');
ConnTrack::note('aaaaaaaa', 'bbbbbbbb', 'ice');
ok(duelOf('aaaaaaaa')['state'] === 'connecting', 'ice keeps connecting');
ConnTrack::playing('aaaaaaaa', 'bbbbbbbb');
ok(duelOf('aaaaaaaa')['state'] === 'playing', 'duel heartbeat means playing');
ok(duelOf('aaaaaaaa')['mode'] === 'p2p', 'playing keeps the negotiated mode');

// bye no longer wipes the pair: both sides keep a short-lived 'ended' row
// so the duel lingers on the Duels card for FOK_DUEL_LINGER seconds.
ConnTrack::note('aaaaaaaa', 'bbbbbbbb', 'bye');
ok(duelOf('aaaaaaaa')['state'] === 'ended', 'bye ends the duel but it lingers');
ok(duelOf('bbbbbbbb')['state'] === 'ended', 'the peer side lingers as ended too');
ok(duelOf('aaaaaaaa')['peer'] === 'bbbbbbbb', 'the ended row still names the peer');
Db::get()->prepare('UPDATE conn SET updated = ? WHERE id IN (?, ?)')
    ->execute([time() - FOK_DUEL_LINGER - 1, 'aaaaaaaa', 'bbbbbbbb']);
ok(duelOf('aaaaaaaa') === [], 'past the linger the ended duel drops off the card');

// ConnTrack: the no-P2P bit is honored from either side and sticks within
// a duel; reopening a just-ended pairing starts its mode clean.
ConnTrack::note('bbbbbbbb', 'aaaaaaaa', 'invite-relay');
ok(duelOf('bbbbbbbb')['mode'] === 'relay', 'invite-relay declares relay');
ok(duelOf('aaaaaaaa')['mode'] === 'relay', 'the invited peer sees relay too');
ConnTrack::note('aaaaaaaa', 'bbbbbbbb', 'accept');
ok(duelOf('aaaaaaaa')['mode'] === 'relay', 'a plain accept cannot downgrade to p2p');
ConnTrack::note('aaaaaaaa', 'bbbbbbbb', 'bye');
ConnTrack::note('aaaaaaaa', 'bbbbbbbb', 'invite');
ConnTrack::note('bbbbbbbb', 'aaaaaaaa', 'accept-relay');
ok(duelOf('aaaaaaaa')['mode'] === 'relay', 'accept-relay declares relay from the other side');

// ConnTrack: an UNDECLARED p2p -> relay fallback still shows as relay, and
// a plain invite reopening the ended pairing resets the mode to p2p first.
ConnTrack::note('aaaaaaaa', 'bbbbbbbb', 'bye');
ConnTrack::note('aaaaaaaa', 'bbbbbbbb', 'invite');
ok(duelOf('aaaaaaaa')['mode'] === 'p2p', 'plain invite starts out p2p');
Relay::markRelaying('aaaaaaaa', 'bbbbbbbb');
ok(duelOf('aaaaaaaa')['mode'] === 'relay', 'relay traffic reports relay without a declaration');
ok(duelOf('aaaaaaaa')['state'] === 'playing', 'relay traffic means the game runs');

// ConnTrack: the ICE-burst write skip (see ConnTrack::set) elides a redundant
// same-state 'connecting' refresh, but it must never swallow a mode change.
// Build a fresh connecting/p2p pair, prove a same-state ice leaves the mode
// alone, then let a relay declaration arrive inside the throttle window: the
// p2p -> relay upgrade still has to land on both sides.
ConnTrack::note('aaaaaaaa', 'bbbbbbbb', 'bye');
ConnTrack::note('aaaaaaaa', 'bbbbbbbb', 'invite');
ConnTrack::note('bbbbbbbb', 'aaaaaaaa', 'accept');
ok(duelOf('aaaaaaaa')['mode'] === 'p2p', 'a fresh accept negotiates p2p');
ConnTrack::note('aaaaaaaa', 'bbbbbbbb', 'ice');
ok(duelOf('aaaaaaaa')['mode'] === 'p2p', 'a skipped ice refresh leaves the mode untouched');
ConnTrack::note('aaaaaaaa', 'bbbbbbbb', 'accept-relay');
ok(duelOf('aaaaaaaa')['mode'] === 'relay', 'a relay upgrade lands even inside the connecting burst window');
ok(duelOf('bbbbbbbb')['mode'] === 'relay', 'the peer side sees the in-window upgrade too');

// ConnTrack: a duel that goes quiet (no bye reached us) is shown as ended
// for the linger window, then drops off.
Db::get()->prepare('UPDATE conn SET updated = ? WHERE id = ?')
    ->execute([time() - FOK_CONN_TTL - 1, 'aaaaaaaa']);
ok(duelOf('aaaaaaaa')['state'] === 'ended', 'a quiet duel reads as ended');
Db::get()->prepare('UPDATE conn SET updated = ? WHERE id = ?')
    ->execute([time() - FOK_CONN_TTL - FOK_DUEL_LINGER - 1, 'aaaaaaaa']);
ok(duelOf('aaaaaaaa') === [], 'past the linger the quiet duel drops off the card');
ConnTrack::note('aaaaaaaa', 'bbbbbbbb', 'invite');
ConnTrack::forget('aaaaaaaa');
ok(duelOf('aaaaaaaa') === [], 'a forgotten client is off the Duels card');
ok(duelOf('bbbbbbbb') === [], 'forget drops the peer side as well');

// ConnTrack: a client with no player row is on neither card.
ok(!onPresence('cccccccc'), 'an unknown client is not on the presence card');
ok(duelOf('cccccccc') === [], 'nor on the Duels card');

// ConnTrack: a quick-match seeker shows as matchmaking only while it is
// actively polling; one that went quiet drops off (as the matchmaker does).
Db::get()->exec('DELETE FROM conn');
Db::get()->exec('DELETE FROM mm_queue');
Db::get()->prepare('INSERT INTO mm_queue (id, since, last_poll) VALUES (?, ?, ?)')
    ->execute(['aaaaaaaa', time(), time()]);
ok(duelOf('aaaaaaaa')['state'] === 'matchmaking', 'an active seeker shows as matchmaking');
Db::get()->prepare('UPDATE mm_queue SET last_poll = ? WHERE id = ?')
    ->execute([time() - FOK_MATCH_WINDOW - 1, 'aaaaaaaa']);
ok(duelOf('aaaaaaaa') === [], 'a seeker that stopped polling drops off the Duels card');
Db::get()->exec('DELETE FROM mm_queue');

// Relay: relay admission is counted from the hub traffic a pair
// really caused, not from queued messages (gone the instant the receiver
// drains them) and not from what a client claims.
Db::get()->exec('DELETE FROM conn');
ok(Relay::activePairs() === 0, 'no relayed pairs on a quiet server');
ok(!Relay::isRelaying('aaaaaaaa', 'bbbbbbbb'), 'idle pair holds no relay slot');
Relay::markRelaying('aaaaaaaa', 'bbbbbbbb');
ok(Relay::activePairs() === 1, 'relaying pair counted once');
ok(Relay::isRelaying('aaaaaaaa', 'bbbbbbbb'), 'relaying pair holds its slot');
ok(Relay::isRelaying('bbbbbbbb', 'aaaaaaaa'), 'slot is held from either side');
Relay::markRelaying('bbbbbbbb', 'aaaaaaaa');
ok(Relay::activePairs() === 1, 'both directions are still one pair');
Db::get()->prepare('UPDATE conn SET relay_seen = ? WHERE id IN (?, ?)')
    ->execute([time() - FOK_RELAY_WINDOW - 1, 'aaaaaaaa', 'bbbbbbbb']);
ok(Relay::activePairs() === 0, 'a pair that stopped relaying frees its slot');

// Relay: a DECLARATION must never take a relay slot. accept-relay is
// not friendship-gated, so if a claim counted, a handful of invented
// pairs would deny the relay to everyone.
Db::get()->exec('DELETE FROM conn');
ConnTrack::note('aaaaaaaa', 'bbbbbbbb', 'invite-relay');
ok(duelOf('aaaaaaaa')['mode'] === 'relay', 'declaration is tracked as relay mode');
ok(Relay::activePairs() === 0, 'a no-p2p declaration takes no relay slot');
ok(!Relay::isRelaying('aaaaaaaa', 'bbbbbbbb'), 'declaring pair holds no slot yet');
Relay::markRelaying('aaaaaaaa', 'bbbbbbbb');
ok(Relay::activePairs() === 1, 'real hub traffic takes the slot');

// Relay vs teardown: bye and decline are not friendship-gated either, so a
// stranger must not be able to end someone else's connection - let alone
// drop the slot of a live relayed duel and get it turned away on resume.
ok(Relay::isRelaying('aaaaaaaa', 'bbbbbbbb'), 'duel is relaying before the stranger');
ConnTrack::note('cccccccc', 'aaaaaaaa', 'bye');
ok(duelOf('aaaaaaaa')['peer'] === 'bbbbbbbb', "a stranger's bye leaves the connection alone");
ok(Relay::isRelaying('aaaaaaaa', 'bbbbbbbb'), "a stranger's bye cannot drop the relay slot");
ConnTrack::playing('aaaaaaaa', 'bbbbbbbb');
ok(Relay::isRelaying('aaaaaaaa', 'bbbbbbbb'), 'the duel heartbeat keeps the relay slot');
// peerLeft is the relay leave signal: a live duel is NOT gone; the real
// peer's bye makes it gone at once (a relayed peer holding a GET reads this
// instead of waiting out its liveness timeout).
ok(!Relay::peerLeft('aaaaaaaa', 'bbbbbbbb'), 'a live duel does not read as the peer having left');
ConnTrack::note('bbbbbbbb', 'aaaaaaaa', 'bye');
ok(duelOf('aaaaaaaa')['state'] === 'ended', "the real peer's bye ends it (it lingers)");
ok(!Relay::isRelaying('aaaaaaaa', 'bbbbbbbb'), 'and frees the relay slot at once');
ok(Relay::peerLeft('aaaaaaaa', 'bbbbbbbb'), "the real peer's bye reads as gone");
Db::get()->exec('DELETE FROM conn');

// An early fetch - fetchColumn(), fetch() - that leaves its statement open
// pins this connection to a read snapshot. Once ANOTHER connection commits,
// the next write here fails instantly with SQLITE_BUSY, and neither
// busy_timeout nor a retry can do anything about it (the busy handler is not
// even called). This is invisible single-threaded, so drive it with a second
// connection: every read below must leave the connection able to write.
$other = new PDO('sqlite:' . FOK_DB_FILE, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$other->exec('PRAGMA busy_timeout = 4000');
// NOTE: a statement held only in a local that goes out of scope is destroyed
// on return, which closes its cursor - so calling such a helper proves
// nothing. Only a handle still IN SCOPE when the request writes again is
// dangerous, which is the shape this drives directly.
$writeWorks = static function (bool $close) use ($other): bool {
    $st = Db::get()->prepare('SELECT 1 FROM players LIMIT 1');
    $st->execute();
    $st->fetchColumn();                       // stops early: statement stays open
    if ($close) {
        $st->closeCursor();
    }
    $other->exec("INSERT INTO alerts (type, message, created, seen) VALUES ('cursor', 'x', " . time() . ', 1)');
    try {
        Db::get()->exec("DELETE FROM alerts WHERE type = 'cursor'");
        $ok = true;
    } catch (PDOException $e) {
        $ok = false;
    }
    $st->closeCursor();
    return $ok;
};
ok(!$writeWorks(false), 'an in-scope open read cursor blocks the next write');
ok($writeWorks(true), 'closeCursor releases the snapshot and the write goes through');
$other = null;   // no live handle may outlive this (see the restore test)

// Load: per-minute gauges accumulate in memory and flush in one write.
// (Keep no statement handle alive across the run - a live PDOStatement
// pins its connection open and breaks the later restore test on Windows.)
$loadVal = static function (string $metric): int {
    $st = Db::get()->prepare('SELECT value FROM loadmin WHERE bucket = ? AND metric = ?');
    $st->execute([gmdate('YmdHi'), $metric]);
    return (int)$st->fetchColumn();
};
// Exact gauges for the assertions below: in production one request in
// load_sample flushes and stands in for the rest (see Load::flush).
Settings::set('load_sample', 1);
Load::flush();                            // drain anything pending from above
Db::get()->exec('DELETE FROM loadmin');   // one write: counted as db load
Load::tick('msg_out', 3);
Load::tick('msg_out', 2);
Load::flush();
ok($loadVal('msg_out') === 5, 'msg_out accumulates then flushes in one write');

// The PDO wrapper counts a write query as db load, a read as none.
Load::flush();
Db::get()->exec('DELETE FROM loadmin');   // exactly one write since the flush
Db::get()->query('SELECT 1');             // a read: must not count
Load::flush();
ok($loadVal('db_w') === 1, 'the wrapper counts one write and no reads');

// lastMinute reports the previous COMPLETE minute's totals.
Db::get()->exec('DELETE FROM loadmin');
$prevMin = gmdate('YmdHi', time() - 60);
Db::get()->prepare('INSERT INTO loadmin (bucket, metric, value) VALUES (?, ?, ?), (?, ?, ?)')
    ->execute([$prevMin, 'msg_out', 7, $prevMin, 'db_w', 4]);
$lm = Load::lastMinute();
ok($lm['out'] === 7, 'lastMinute reports the previous minute messages out');
ok($lm['db_writes'] === 4, 'lastMinute reports the previous minute db writes');
ok(array_key_exists('in', $lm), 'lastMinute carries messages-in from the req_min counter');
Db::get()->exec('DELETE FROM loadmin');

// Vault: token-secured per-player config backup, one row per id.
ok(Vault::restore('aaaaaaaa', 'x') === null, 'restore of a fresh id is null (no backup)');
$v1 = Vault::backup('aaaaaaaa', '{"v":1,"settings":{}}', null);
ok(is_array($v1) && $v1['updated'] > 0, 'first backup succeeds and reports a timestamp');
ok(isset($v1['token']) && strlen($v1['token']) === 32, 'first backup mints a 128-bit token');
$tok = $v1['token'];
$r = Vault::restore('aaaaaaaa', $tok);
ok($r !== null && $r !== false && $r['payload'] === '{"v":1,"settings":{}}', 'restore with the token returns the payload');
ok(Vault::restore('aaaaaaaa', 'wrongtoken') === false, 'restore with a wrong token is refused');
ok(Vault::backup('aaaaaaaa', 'take-over', 'wrongtoken') === null, 'overwrite with a wrong token is refused');
ok(Vault::backup('aaaaaaaa', 'take-over', null) === null, 'overwrite without a token is refused');
$v2 = Vault::backup('aaaaaaaa', 'updated-blob', $tok);
ok(is_array($v2) && $v2['token'] === $tok, 'a later backup keeps the same token');
ok(Vault::restore('aaaaaaaa', $tok)['payload'] === 'updated-blob', 'the later backup replaced the payload');
ok(Vault::restore('bbbbbbbb', $tok) === null, 'another id keeps its own empty slot');
$v3 = Vault::backup('bbbbbbbb', 'other', null);
ok($v3['token'] !== $tok, 'a different id gets a different token');
ok(Vault::peek('aaaaaaaa')['payload'] === 'updated-blob', 'peek reads a backup without the token (admin recovery)');
ok(Vault::peek('aaaaaaaa')['enrolled'] === true, 'a backup is enrolled while it has a token');
ok(Vault::peek('cccccccc') === null, 'peek is null for an id with no backup');
// resetToken lets a client that lost its token re-enroll on its next backup.
ok(Vault::resetToken('aaaaaaaa') === true, 'reset clears the token');
ok(Vault::peek('aaaaaaaa')['enrolled'] === false, 'after a reset the backup is no longer enrolled');
ok(Vault::restore('aaaaaaaa', $tok) === false, 'the old token no longer restores after a reset');
$v4 = Vault::backup('aaaaaaaa', 'reenrolled', null);
ok(is_array($v4) && $v4['token'] !== $tok, 'the next backup mints a fresh token');
ok(Vault::peek('aaaaaaaa')['payload'] === 'reenrolled', 'the payload survives the reset and re-enroll');
ok(Vault::resetToken('cccccccc') === false, 'reset is a no-op for an id with no backup');
Db::get()->exec('DELETE FROM vault');

// PStats: per-player self-reported gameplay stats - monotonic, capped and
// write-throttled (see PStats, api/stats.php).
Db::get()->exec('DELETE FROM pstats');
$ps = PStats::submit('e1e1e1e1',
    ['games' => 5, 'levels' => 10, 'best_level' => 3, 'deaths' => 4,
     'duels' => 2, 'duels_won' => 1, 'play_seconds' => 600]);
ok($ps['games'] === 5 && $ps['best_level'] === 3, 'first submit stores and echoes the counters');
ok(PStats::get('e1e1e1e1')['games'] === 5, 'get reads the stored stats');
ok(PStats::get('a5a5a5a5')['games'] === 0 && PStats::get('a5a5a5a5')['updated'] === 0,
    'an id with nothing stored reads as zeros');
// Age the row past the write throttle so the next submit persists.
$age = static function (string $id): void {
    Db::get()->prepare('UPDATE pstats SET updated = ? WHERE id = ?')->execute([time() - 60, $id]);
};
$age('e1e1e1e1');
$ps = PStats::submit('e1e1e1e1', ['games' => 1, 'best_level' => 2]);
ok($ps['games'] === 5 && $ps['best_level'] === 3, 'a lower submit never lowers the stored totals');
$age('e1e1e1e1');
$ps = PStats::submit('e1e1e1e1', ['games' => 9, 'best_level' => 2]);
ok($ps['games'] === 9 && PStats::get('e1e1e1e1')['games'] === 9, 'a higher field grows and persists');
ok($ps['best_level'] === 3, 'a field is held while another in the same submit grows');
$age('e1e1e1e1');
$ps = PStats::submit('e1e1e1e1', ['best_level' => 500, 'play_seconds' => 5000000000]);
ok($ps['best_level'] === 99, 'best_level is clamped to 99, not rejected');
ok($ps['play_seconds'] === FOK_PSTATS_SECONDS_MAX, 'play_seconds is clamped to its cap');
$age('e1e1e1e1');
$ps = PStats::submit('e1e1e1e1', ['deaths' => 'x', 'duels' => -3, 'duels_won' => 7]);
ok($ps['deaths'] === 4 && $ps['duels'] === 2, 'malformed and negative fields are ignored');
ok($ps['duels_won'] === 7, 'a valid field still applies when a sibling is malformed');
// Write throttle: a second submit within the window echoes but does not persist.
PStats::submit('f0f0f0f0', ['games' => 1]);
$ps = PStats::submit('f0f0f0f0', ['games' => 2]);
ok($ps['games'] === 2, 'a throttled submit still echoes the merged value');
ok(PStats::get('f0f0f0f0')['games'] === 1, 'the throttled growth is not yet persisted');
Db::get()->exec('DELETE FROM pstats');

// Debug: a bundle gets a 4-digit PIN, retrievable, purged after the TTL.
$dbgCount = static function (string $pin): int {
    $s = Db::get()->prepare('SELECT COUNT(*) FROM debug WHERE pin = ?');
    $s->execute([$pin]);
    return (int)$s->fetchColumn();
};
Db::get()->exec('DELETE FROM debug');
$dpin = Debug::submit('{"logs":[1,2]}');
ok(preg_match('/^[0-9]{4}$/', $dpin) === 1, 'submit returns a 4-digit pin');
ok(Debug::get($dpin)['payload'] === '{"logs":[1,2]}', 'get returns the dataset verbatim');
$dother = $dpin === '0000' ? '0001' : '0000';
ok(Debug::get($dother) === null, 'an unknown pin is null');
$dpin2 = Debug::submit('{"a":1}');
ok($dpin2 !== $dpin, 'a second submit gets a different pin');
ok(count(Debug::recent()) === 2, 'recent lists both datasets');
Db::get()->prepare('UPDATE debug SET created = ? WHERE pin = ?')->execute([time() - FOK_DEBUG_TTL - 1, $dpin]);
ok(Debug::get($dpin) === null, 'an expired dataset is not returned');
Debug::submit('{"b":2}');   // its prune deletes the expired row
ok($dbgCount($dpin) === 0, 'a submit purges expired datasets');
Db::get()->exec('DELETE FROM debug');
$da = Debug::submit('{"a":1}');
$db2 = Debug::submit('{"b":2}');
Debug::submit('{"c":3}');
ok(Debug::delete([$da, $db2]) === 2, 'delete removes the named datasets');
ok(Debug::get($da) === null && Debug::get($db2) === null, 'a deleted dataset is gone');
ok(count(Debug::recent()) === 1, 'delete leaves the others');
ok(Debug::delete([]) === 0, 'delete of nothing is a no-op');
Db::get()->exec('DELETE FROM debug');

// peer-net: a confirmed pairing hands each side the other's IP + family,
// plus its own, as a server-generated 'peer-net' signal.
Db::get()->exec('DELETE FROM signals');
Presence::touch('a1a1a1a1', '1.2.3.4');
Presence::touch('b2b2b2b2', '2a01:db8::9');
Presence::announceNet('a1a1a1a1', 'b2b2b2b2');
$pnA = Signals::take('a1a1a1a1');
$pnB = Signals::take('b2b2b2b2');
ok(count($pnA) === 1 && $pnA[0]['type'] === 'peer-net', 'each side gets one peer-net signal');
$dA = json_decode($pnA[0]['payload'], true);
ok($dA['peer'] === 'b2b2b2b2' && $dA['ip'] === '2a01:db8::9' && $dA['family'] === 6, 'the hint carries the peer ip and family');
ok($dA['self_ip'] === '1.2.3.4' && $dA['self_family'] === 4, 'the hint carries the recipient own ip and family');
$dB = json_decode($pnB[0]['payload'], true);
ok($dB['peer'] === 'a1a1a1a1' && $dB['ip'] === '1.2.3.4' && $dB['family'] === 4, 'the mirror hint points the other way');
Presence::announceNet('a1a1a1a1', 'zzzzzzzz');
ok(Signals::take('a1a1a1a1') === [], 'a never-seen peer yields no hint');
Db::get()->exec('DELETE FROM signals');

// Auth: verify against hash file, lockout after repeated failures
file_put_contents(FOK_ADMIN_HASH_FILE, password_hash('u:p', PASSWORD_DEFAULT));
ok(Auth::login('u', 'p', '9.9.9.9'), 'correct credentials accepted');
ok(!Auth::login('u', 'wrong', '9.9.9.8'), 'wrong password rejected');
ok(!Auth::login('wrong', 'p', '9.9.9.8'), 'wrong user rejected');
for ($i = 0; $i < FOK_ADMIN_MAX_FAILS; $i++) {
    Auth::login('u', 'wrong', '9.9.9.7');
}
ok(!Auth::login('u', 'p', '9.9.9.7'), 'locked out after repeated failures');
ok(Auth::login('u', 'p', '9.9.9.6'), 'other IP unaffected by lockout');

// Settings: defaults fall through, overrides stick
ok(Settings::int('mailbox_cap') === FOK_MAILBOX_CAP, 'setting falls back to default');
Settings::set('chat_max_len', 99);
ok(Settings::int('chat_max_len') === 99, 'setting override readable');
$all = Settings::all();
ok(is_string($all[0]['label']) && $all[0]['label'] !== '', 'settings carry labels');
$threw = false;
try {
    Settings::set('bogus_key', 1);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
ok($threw, 'unknown setting rejected');

// Alerts: raised by failed admin logins above, de-duplicated, seen-tracking
ok(Alerts::unseenCount() > 0, 'failed logins raised alerts');
Alerts::raise('test-x', 'first');
Alerts::raise('test-x', 'second within cooldown');
$testX = array_filter(Alerts::recent(), static fn(array $a) => $a['type'] === 'test-x');
ok(count($testX) === 1, 'same alert type de-duplicated within cooldown');
Alerts::markSeen();
ok(Alerts::unseenCount() === 0, 'mark seen clears unseen count');
Alerts::raise('test-y', 'new after seen');
ok(Alerts::unseenCount() === 1, 'new alert counts as unseen');

// Alerts: player IDs in a message resolve to the current name at read time
// (player aaaaaaaa was named ALPHA above); an unknown 8-hex token stays bare.
Alerts::raise('test-name', 'PTS from player aaaaaaaa (sender deadbeef)');
$named = array_values(array_filter(
    Alerts::recent(), static fn(array $a) => $a['type'] === 'test-name'))[0];
ok($named['message'] === 'PTS from player aaaaaaaa "ALPHA" (sender deadbeef)',
    'alert names known player id, leaves unknown id bare');

// Auth: lockout threshold is configurable at runtime
Settings::set('admin_max_fails', 2);
Auth::login('u', 'wrong', '9.9.9.5');
Auth::login('u', 'wrong', '9.9.9.5');
ok(!Auth::login('u', 'p', '9.9.9.5'), 'configured lower lockout threshold applies');
Settings::set('admin_max_fails', FOK_ADMIN_MAX_FAILS);

// Util::defer: the server's own bookkeeping runs AFTER the answer is out.
// There is no FPM here so nothing is flushed and the queue runs inline -
// what matters is that it runs at all, exactly once, and that a failing
// job cannot take the rest with it.
ini_set('error_log', $tmp . '/php-error.log');
$ran = [];
Util::defer(function () use (&$ran) { $ran[] = 'a'; });
Util::defer(function () use (&$ran) { $ran[] = 'b'; });
ok($ran === [], 'deferred work does not run at defer time');
Util::runDeferred();
ok($ran === ['a', 'b'], 'deferred work runs, in order');
Util::runDeferred();
ok($ran === ['a', 'b'], 'the queue is drained exactly once');
Util::defer(function () { throw new RuntimeException('boom'); });
Util::defer(function () use (&$ran) { $ran[] = 'c'; });
Util::runDeferred();
ok($ran === ['a', 'b', 'c'], 'a failing deferred job does not stop the rest');

// The point of all of it: the counter writes leave the caller's latency.
$countOf = function (string $metric): int {
    $st = Db::get()->prepare('SELECT COALESCE(SUM(value), 0) FROM counters WHERE metric = ?');
    $st->execute([$metric]);
    return (int)$st->fetchColumn();
};
$before = $countOf('unittest');
Util::bump('unittest');
ok($countOf('unittest') === $before, 'bump writes nothing before the answer is out');
Util::runDeferred();
ok($countOf('unittest') === $before + 1, 'bump lands once the answer is out');

// Both counters ride in ONE statement now (one write lock instead of two),
// so prove the second row is still really written.
$reqMinOf = function (): int {
    $st = Db::get()->prepare("SELECT COALESCE(value, 0) FROM counters WHERE bucket = ? AND metric = 'req_min'");
    $st->execute([gmdate('YmdHi')]);
    return (int)$st->fetchColumn();
};
$rm = $reqMinOf();
Util::bump('unittest');
Util::runDeferred();
ok($reqMinOf() === $rm + 1, 'the per-minute request counter rides along');

// ... and that its value is still FOUND among the returned rows. Miss it
// and reqPerMin reads 0, the sampling never hits a multiple of 25, and the
// traffic alert dies silently - monitoring that fails quietly is worse
// than none.
Settings::set('alert_req_per_min', 1);
Db::get()->exec("DELETE FROM counters WHERE metric = 'req_min'");
Db::get()->exec("DELETE FROM alerts WHERE type = 'traffic'");
for ($i = 0; $i < 25; $i++) {
    Util::bump('unittest');
    Util::runDeferred();
}
$traffic = array_filter(Alerts::recent(), static fn(array $a) => $a['type'] === 'traffic');
ok($traffic !== [], 'the returned req_min value still reaches the traffic alert');
Settings::set('alert_req_per_min', 600);

// ---- Item registry (API 4.0) ----------------------------------------
// The HTTP smoke walks the whole claim ladder over the wire; what is left
// for here is what a browserless request cannot see - the MAC construction,
// the hash chain, checkpoint truncation, and conservation at the row level.

// The attestation tag. The 32-hex secret keys the HMAC as 16 RAW bytes and
// the tick is unpadded decimal: the two traps a client implementer hits
// first, so they are pinned here against a literal recomputation.
$sec = str_repeat('ab', 16);
$peerSec = str_repeat('cd', 16);
$mac = Ledger::mac($sec, 'm1', 12, 'ws');
ok(strlen($mac) === 16 && ctype_xdigit($mac), 'an attestation tag is 16 hex characters');
ok($mac === substr(hash_hmac('sha256', 'm1|12|ws', (string)hex2bin($sec)), 0, 16),
    'the tag is the truncated HMAC over mid|tick|ws_digest');
ok(Ledger::verifyTag($sec, 'm1', 12, 'ws', $mac), 'a correct tag verifies');
ok(!Ledger::verifyTag($sec, 'm1', 13, 'ws', $mac), 'a tag does not carry to another tick');
ok(!Ledger::verifyTag($sec, 'm1', 12, 'ws2', $mac), 'a tag does not carry to another ownership digest');
ok(!Ledger::verifyTag($peerSec, 'm1', 12, 'ws', $mac), 'the peer secret does not verify our tag');
ok(Ledger::mac('nothex', 'm1', 12, 'ws') === '', 'a malformed secret yields no tag at all');
ok(!Ledger::verifyTag('nothex', 'm1', 12, 'ws', ''), 'and no tag never verifies as a match');

// The chain. Its job is tamper-EVIDENCE: an edited row must be findable,
// which is the only reason to hash rows together at all.
$idb = Db::get();
$idb->exec('DELETE FROM ledger');
$idb->exec('DELETE FROM items');
$idb->exec('DELETE FROM matches');
$r1 = Ledger::append($idb, 'mint', 'u1', '', 'aa11aa11', '', 0, 1000);
ok($r1['prev'] === str_repeat('0', 64), 'the first row chains to the genesis hash');
$r2 = Ledger::append($idb, 'transfer', 'u1', 'aa11aa11', 'bb22bb22', 'm1', 7, 1001);
ok($r2['prev'] === $r1['hash'], 'every later row chains to the one before it');
ok(Ledger::verify($idb) === ['ok' => true, 'from' => 0, 'checked' => 2, 'break' => null],
    'an untouched chain verifies end to end');
$idb->prepare('UPDATE ledger SET tick = 99 WHERE n = ?')->execute([$r2['n']]);
$v = Ledger::verify($idb);
ok(!$v['ok'] && $v['break'] === $r2['n'], 'editing a row is caught, and named');
$idb->prepare('UPDATE ledger SET tick = 7 WHERE n = ?')->execute([$r2['n']]);
ok(Ledger::verify($idb)['ok'], 'and putting it back makes it verify again');

// Checkpoint: fold the state digest in, drop everything older, keep verifying.
$cp = Ledger::checkpoint($idb, 2000);
ok($cp['deleted'] === 2, 'a checkpoint drops every row it folded in');
ok(Ledger::rows($idb) === 1, 'leaving the checkpoint itself as the new anchor');
ok(Ledger::verify($idb)['ok'], 'and the chain verifies from that checkpoint forward');

// Conservation: a transfer MOVES the single row that IS the ownership.
Presence::touch('aa11aa11', '1.2.3.4');
Presence::touch('bb22bb22', '1.2.3.4');
$m = Items::openMatch($idb, 'aa11aa11', 'bb22bb22', Util::nowMs());
$uid = Items::mint('aa11aa11', 'crown', 'box')['uid'];
ok(count(Items::owned('aa11aa11')) === 1, 'a mint puts one instance in the wardrobe');
$res = Items::claim('aa11aa11', $m['mid'], $uid, 'aa11aa11', 'bb22bb22', 5, 0, 'ws',
    Ledger::mac($m['sec_a'], $m['mid'], 5, 'ws'), null);
ok($res['ok'] && $res['state'] === 'settled' && $res['seq'] === 1, 'losing an item settles at once');
ok(Items::owned('aa11aa11') === [], 'the instance left the loser');
ok(count(Items::owned('bb22bb22')) === 1, 'and arrived at the winner');
ok((int)$idb->query('SELECT COUNT(*) FROM items')->fetchColumn() === 1,
    'a transfer moves the row and can never mint one');
$res = Items::claim('aa11aa11', $m['mid'], $uid, 'aa11aa11', 'bb22bb22', 6, 0, 'ws',
    Ledger::mac($m['sec_a'], $m['mid'], 6, 'ws'), null);
ok(!$res['ok'] && $res['error'] === 'counterfeit', 'claiming an item you no longer own is counterfeit');

// A held gain settles only once the grace has passed with no contradiction.
$m2 = Items::openMatch($idb, 'aa11aa11', 'bb22bb22', Util::nowMs());
$u2 = Items::mint('aa11aa11', 'cape', 'shop')['uid'];
$tagB = Ledger::mac($m2['sec_b'], $m2['mid'], 9, 'ws2');
$res = Items::claim('bb22bb22', $m2['mid'], $u2, 'aa11aa11', 'bb22bb22', 9, 0, 'ws2', $tagB, null);
ok($res['ok'] && $res['state'] === 'held', 'an unwitnessed gain is held, not granted');
ok(Items::owned('aa11aa11')[0]['uid'] === $u2, 'and the item stays with the sender meanwhile');
Settings::set('claim_grace_ms', 0);
$res = Items::claim('bb22bb22', $m2['mid'], $u2, 'aa11aa11', 'bb22bb22', 9, 0, 'ws2', $tagB, null);
ok($res['ok'] && $res['state'] === 'settled', 'the same claim settles once the grace has passed');
Settings::set('claim_grace_ms', 60000);

// The legacy amnesty: one-time, idempotent, and the server's own list wins.
Presence::touch('cc33cc33', '1.2.3.4');
ok(count(Items::seed('cc33cc33', ['cap', 'cap', 'NOT AN ID', 'scarf'], null)) === 2,
    'the legacy seed dedupes repeats and drops malformed ids');
ok(count(Items::seed('cc33cc33', ['jetpack'], null)) === 2,
    'the amnesty is one-time: a second seed mints nothing');
Presence::touch('dd44dd44', '1.2.3.4');
$vaulted = Items::seed('dd44dd44', ['from_client'], ['from_vault']);
ok(count($vaulted) === 1 && $vaulted[0]['item_id'] === 'from_vault',
    'the list the server already holds wins over the one the client submits');

// Minting is client-trusted, so it is capped per hour rather than proved.
Settings::set('mint_max_per_hour', 1);
ok(isset(Items::mint('cc33cc33', 'boots', 'box')['uid']), 'the first mint of the hour goes through');
ok(isset(Items::mint('cc33cc33', 'boots', 'box')['throttled']), 'the next one is throttled');
Settings::set('mint_max_per_hour', 60);
// mint queues a deferred prune; run it here rather than let the shutdown
// handler reopen the database after the cleanup below has closed it.
Util::runDeferred();

// Backup: create produces a valid snapshot, restore brings data back
$name = Backup::create();
ok(Backup::isValidName($name), 'backup name has expected format');
ok(is_file(FOK_BACKUP_DIR . '/' . $name), 'backup file exists');
Db::get()->exec('DELETE FROM scores');
ok(Scores::top() === [], 'scores wiped');
Backup::restore(FOK_BACKUP_DIR . '/' . $name);
ok(count(Scores::top()) === 5, 'restore brings scores back');
$bad = $tmp . '/not-a-db';
file_put_contents($bad, 'hello world');
$threw = false;
try {
    Backup::restore($bad);
} catch (RuntimeException $e) {
    $threw = true;
}
ok($threw, 'restore rejects a non-SQLite file');

// Restore must not depend on the caller having dropped its DB handle first.
// admin/api.php holds $db = Db::get() at global scope for the WHOLE request,
// the restore included - the one configuration this never exercised, because
// the tests above (and no other caller) happen to hold no live reference. Pin
// one open across the restore, exactly as the real request does.
$name = Backup::create();
$live = Db::get();
$live->exec('DELETE FROM scores');
Backup::restore(FOK_BACKUP_DIR . '/' . $name);
ok(count(Scores::top()) === 5, 'restore works with a live handle held open (as admin/api.php does)');
unset($live);

// ---- Tournaments (API 4.1) -------------------------------------------
// Bracket is pure math with a normative spec (docs/API.md "Tournament
// mode"), and a client renders what it computes - so the rules are pinned
// here against the document rather than against the implementation.

// The seating shuffle is a permutation, and it is the SEED that decides it:
// same seed, same order out, which is what lets a client verify a draw.
$tids = ['aa11aa11', 'bb22bb22', 'cc33cc33', 'dd44dd44', 'ee55ee55'];
$s1 = Bracket::seats($tids, 'deadbeef00000000');
$s2 = Bracket::seats($tids, 'deadbeef00000000');
ok($s1 === $s2, 'seating is deterministic in the seed');
$sorted = $s1;
sort($sorted);
ok($sorted === $tids, 'seating is a permutation - nobody is lost or duplicated');
ok(Bracket::seats($tids, '0000000000000000') === Bracket::seats($tids, 'zzzz'),
    'a zero or unparseable seed falls back to one fixed state, not to no shuffle');

// The match-count table from the spec, in full. This is the number that
// decides whether an evening finishes, so it is pinned exactly.
$counts = [];
for ($n = 2; $n <= 10; $n++) {
    $counts[] = count(Bracket::schedule($n));
}
ok($counts === [1, 3, 6, 10, 12, 14, 16, 18, 20], 'the round-1 match counts match the spec');

for ($n = 5; $n <= 10; $n++) {
    $deg = array_fill(0, $n, 0);
    $seen = [];
    foreach (Bracket::schedule($n) as [$a, $b]) {
        $deg[$a]++;
        $deg[$b]++;
        $seen["$a:$b"] = true;
    }
    ok($deg === array_fill(0, $n, 4), "N=$n gives every player exactly 4 matches");
    ok(count($seen) === 2 * $n, "N=$n pairs nobody twice");
}
// The rest spread: matches run one at a time and everyone else is watching,
// so back-to-back play is the thing to avoid. It is only ACHIEVABLE on the
// sparse schedule - a dense 3 or 4 player round-robin runs out of disjoint
// pairs and the ordering falls back, which is why they start at 5 here.
for ($n = 5; $n <= 10; $n++) {
    $back = 0;
    $prev = null;
    foreach (Bracket::schedule($n) as $e) {
        if ($prev !== null && array_intersect($prev, $e) !== []) {
            $back++;
        }
        $prev = $e;
    }
    ok($back === 0, "N=$n never makes anyone play two matches in a row");
}

ok(Bracket::advancers(2) === 2 && Bracket::advancers(3) === 2 && Bracket::advancers(4) === 2,
    'a small field still advances two, so a knockout always exists');
ok(Bracket::advancers(9) === 5 && Bracket::advancers(10) === 5, 'otherwise the best half advance');

// The tie-break ladder, one step at a time. Rows are seat/id/pts/diff.
$row = static fn(int $seat, string $id, float $pts, int $diff): array
    => ['seat' => $seat, 'id' => $id, 'pts' => $pts, 'diff' => $diff];
$byPts = Bracket::rank([$row(0, 'aa11aa11', 1.0, 0), $row(1, 'bb22bb22', 3.0, 0)], [], 'seed');
ok(array_column($byPts, 'id') === ['bb22bb22', 'aa11aa11'], 'points rank first');
ok(array_column($byPts, 'rank') === [1, 2], 'and the rank is stamped 1-based');
$tied = [$row(0, 'aa11aa11', 2.0, -5), $row(1, 'bb22bb22', 2.0, 40)];
ok(array_column(Bracket::rank($tied, ['0:1' => 0], 'seed'), 'id') === ['aa11aa11', 'bb22bb22'],
    'a tied PAIR is decided by their own decisive meeting, not by score difference');
ok(array_column(Bracket::rank($tied, [], 'seed'), 'id') === ['bb22bb22', 'aa11aa11'],
    'without a meeting the tie falls through to score difference');
// Three tied players have no complete sub-tournament between them in a
// sparse schedule, so head-to-head must NOT apply - difference decides.
$three = [$row(0, 'aa11aa11', 2.0, 1), $row(1, 'bb22bb22', 2.0, 9), $row(2, 'cc33cc33', 2.0, 5)];
ok(array_column(Bracket::rank($three, ['0:1' => 0], 'seed'), 'id')
    === ['bb22bb22', 'cc33cc33', 'aa11aa11'], 'head-to-head does not apply to a group of three');
$dead = [$row(0, 'aa11aa11', 2.0, 7), $row(1, 'bb22bb22', 2.0, 7)];
$coin = Bracket::rank($dead, [], 'seed');
ok($coin === Bracket::rank($dead, [], 'seed'), 'the final coin toss is reproducible');
ok(Bracket::coin('seed', 'aa11aa11') < Bracket::coin('seed', 'bb22bb22')
    ? $coin[0]['id'] === 'aa11aa11' : $coin[0]['id'] === 'bb22bb22',
    'and it is the seeded hash, lowest first');

// The knockout fold. [1,8,4,5,2,7,3,6] is the spec's worked example, and it
// is what keeps the top two seeds apart until the final.
ok(Bracket::size(5) === 8 && Bracket::size(4) === 4 && Bracket::size(2) === 2, 'bracket size rounds up');
ok(Bracket::positions(8) === [1, 8, 4, 5, 2, 7, 3, 6], 'the seed fold matches the spec');
ok(Bracket::positions(1) === [1], 'and a one-slot fold is the base case');

$b8 = Bracket::build([10, 11, 12, 13, 14]);          // 5 advancers -> 8 slots
ok(count($b8) === 7, 'an 8-slot bracket is 7 nodes');
ok($b8[count($b8) - 1]['nid'] === 'final', 'the last node is the final');
ok(Bracket::hearts('final') === 3 && Bracket::hearts('ko1.1') === 2,
    'only the final is played at 3 hearts');
$byNid = [];
foreach ($b8 as $node) {
    $byNid[$node['nid']] = $node;
}
ok($byNid['ko1.1']['a'] === 10 && $byNid['ko1.1']['b'] === null,
    'the top seed draws a phantom, which is what a bye is');
ok($byNid['ko1.2']['a'] === 13 && $byNid['ko1.2']['b'] === 14,
    'and the only real first-round match is seed 4 against seed 5');
ok($byNid['ko1.1']['to'] === 'ko2.1' && $byNid['ko1.1']['slot'] === 0
    && $byNid['ko1.2']['to'] === 'ko2.1' && $byNid['ko1.2']['slot'] === 1,
    'adjacent nodes feed the two slots of the same next node');
ok($byNid['ko2.1']['to'] === 'final' && $byNid['final']['to'] === null, 'and the final feeds nothing');
ok($byNid['ko1.1']['round'] === 2 && $byNid['final']['round'] === 4,
    'round 1 is the sparse round, so the knockout stages start at 2');

// ---- The tournament itself, end to end -------------------------------
// Four players, every match reported by both sides, run to a podium. The
// point is the MACHINE: that the cursor advances, that the bracket is built
// from the standings, and that a settled final ends it.
$tp = ['70000001', '70000002', '70000003', '70000004'];
foreach ($tp as $p) {
    Presence::touch($p, '127.0.0.1', null, 'P' . substr($p, -1));
}
$made = Tournament::create($tp[0], false);
ok($made['ok'] === true && strlen($made['tid']) === 32, 'creating a tournament yields a tid');
ok(strlen($made['code']) === Tournament::CODE_LEN
    && strpbrk($made['code'], '01OIL') === false, 'the join code avoids the ambiguous glyphs');
$tid = $made['tid'];
ok(Tournament::create($tp[0], false)['http'] === 409, 'one open tournament per host');
foreach ([1, 2, 3] as $i) {
    ok(Tournament::join($tp[$i], $tid)['ok'] === true, "player $i joins");
}
ok(count(Tournament::join($tp[1], $tid)['players']) === 4, 'joining twice is a no-op, not an error');
ok(Tournament::start($tp[1], $tid)['http'] === 403, 'only the host may start it');
ok(Tournament::start($tp[0], $tid)['ok'] === true, 'the host starts it');

$view = Tournament::view($tp[0], $tid);
ok(count($view['schedule']) === 6, '4 players play all 6 pairs in round 1');
ok($view['cursor'] === 'r1.1' && $view['roles'] !== null, 'the first match is dealt at once');
ok(count($view['roles']['players']) === 2
    && $view['roles']['feeder'] === $view['roles']['players'][0],
    'the roles sheet names two players and makes the first the feeder');
ok(in_array($view['roles']['you'], ['play', 'spectate'], true), 'and tells the caller its own part');
ok($view['roles']['hm'] === 2, 'round-1 matches are played at 2 hearts');

// A spectator of the current match may never report it.
$spectator = null;
foreach ($tp as $p) {
    if (!in_array($p, $view['roles']['players'], true)) {
        $spectator = $p;
        break;
    }
}
ok(Tournament::report($spectator, $tid, 'r1.1', 'win', [9, 0], null)['http'] === 403,
    'a spectator cannot report a match it did not play');

// A lone win is HELD; the loser's matching report confirms it.
$pair = $view['roles']['players'];
ok(Tournament::report($pair[0], $tid, 'r1.1', 'win', [12, 7], null)['state'] === 'held',
    'a lone win waits for the opponent');
ok(Tournament::report($pair[1], $tid, 'r1.1', 'loss', [7, 12], null)['state'] === 'confirmed',
    'and the opponent agreeing confirms it');
$view = Tournament::view($tp[0], $tid);
ok($view['schedule'][0]['winner'] === $pair[0], 'the winner is recorded');
ok($view['schedule'][0]['score'] === [12, 7] || $view['schedule'][0]['score'] === [7, 12],
    'the score is stored in seat order, whichever side reported it');
ok($view['cursor'] === 'r1.2', 'and the cursor moves on by itself');

// The rest of it: whoever is listed first wins, reported by both sides.
for ($step = 0; $step < 40; $step++) {
    $view = Tournament::view($tp[0], $tid);
    if ($view['state'] !== 'running' || $view['cursor'] === null) {
        break;
    }
    [$x, $y] = $view['roles']['players'];
    Tournament::report($x, $tid, $view['cursor'], 'win', [10, 3], null);
    Tournament::report($y, $tid, $view['cursor'], 'loss', [3, 10], null);
}
$view = Tournament::view($tp[0], $tid);
ok($view['state'] === 'done', 'reporting every match runs the tournament to the end');
ok(count($view['bracket']) === 1 && $view['bracket'][0]['nid'] === 'final',
    '4 players advance 2, so the knockout is the final alone');
ok($view['bracket'][0]['hm'] === 3, 'and the final is a normal 3-heart duel');
ok(count($view['standings']) === 4 && $view['standings'][0]['rank'] === 1, 'the standings are ranked');
// A point per match, and JSON gives whole values back as ints, so the
// total is compared numerically rather than by type.
$pts = (float)array_sum(array_column($view['standings'], 'pts'));
ok($pts === 6.0, 'every one of the 6 round-1 matches awarded exactly one point');
ok($view['standings'][0]['id'] === $view['bracket'][0]['winner']
    || $view['standings'][1]['id'] === $view['bracket'][0]['winner'],
    'and the final was played by two of the advancers');

// ---- A loss settles alone, and a contradiction freezes ----------------
$q = ['71000001', '71000002'];
foreach ($q as $p) {
    Presence::touch($p, '127.0.0.1');
}
$two = Tournament::create($q[0], true);
$tid2 = $two['tid'];
ok($two['stakes'] === true, 'the stakes flag is carried through');
Tournament::join($q[1], $tid2);
ok(Tournament::start($q[1], $tid2)['http'] === 403, 'still host only with two players');
Tournament::start($q[0], $tid2);
$v2 = Tournament::view($q[0], $tid2);
ok(count($v2['schedule']) === 1 && $v2['cursor'] === 'r1.1', 'two players play one round-1 match');

// Nobody lies to lose, so one report is enough.
$p2 = $v2['roles']['players'];
ok(Tournament::report($p2[1], $tid2, 'r1.1', 'loss', [2, 8], null)['state'] === 'settled',
    'a reported loss settles at once');
$v2 = Tournament::view($q[0], $tid2);
ok($v2['cursor'] === 'final', 'and both of them advance to the final');

// Two winners cannot both be right: the node freezes rather than guessing.
ok(Tournament::report($p2[0], $tid2, 'final', 'win', [5, 1], null)['state'] === 'held',
    'the first report of the final is held');
ok(Tournament::report($p2[1], $tid2, 'final', 'win', [5, 1], null)['state'] === 'frozen',
    'two claimed wins freeze the node');
$v2 = Tournament::view($q[0], $tid2);
ok($v2['state'] === 'running' && $v2['bracket'][0]['state'] === 'frozen',
    'a frozen final blocks the tournament instead of crowning a guess');
ok($v2['bracket'][0]['winner'] === null, 'and it has no winner at all');

// ---- Forfeits and lobbies --------------------------------------------
$f = ['72000001', '72000002', '72000003'];
foreach ($f as $p) {
    Presence::touch($p, '127.0.0.1');
}
$tid3 = Tournament::create($f[0], false)['tid'];
Tournament::join($f[1], $tid3);
Tournament::join($f[2], $tid3);
ok(count(Tournament::announce('127.0.0.1')) >= 1, 'an open lobby is announced on the host address');
ok(Tournament::announce('10.9.9.9') === [], 'but never to another address');
Tournament::start($f[0], $tid3);
$v3 = Tournament::view($f[0], $tid3);
ok(count($v3['schedule']) === 3, '3 players play all 3 pairs');
Tournament::leave($f[2], $tid3);
$v3 = Tournament::view($f[0], $tid3);
$gone = 0;
foreach ($v3['schedule'] as $node) {
    if (in_array($f[2], $node['players'], true) && $node['state'] === 'settled') {
        $gone++;
    }
}
ok($gone === 2, 'leaving a running tournament forfeits both of that player\'s matches');
foreach ($v3['schedule'] as $node) {
    if (in_array($f[2], $node['players'], true)) {
        ok($node['score'] === null, 'a walkover has no score, so it moves no tie-break difference');
        break;
    }
}
ok(Tournament::leave($f[2], $tid3)['ok'] === true, 'and leaving twice is harmless');

// The host owns the lobby, and only the lobby.
$tid4 = Tournament::create('73000001', false)['tid'];
Tournament::leave('73000001', $tid4);
ok(Tournament::load($tid4)['state'] === 'abandoned', 'the host leaving an unstarted lobby ends it');
ok(Tournament::join('73000002', $tid4)['http'] === 404, 'which cannot then be joined');

// ---- A node nobody can play must not be re-dealt ---------------------
// Both finalists vanish mid-match: the walkover deadline finds neither of
// them present and voids the node. A drawn knockout node is normally
// REPLAYED - a knockout has to produce a winner - but a void one has nobody
// to replay it, and re-dealing it deals the same unplayable match again
// while the tournament waits for a result that can never come.
$v = ['74000001', '74000002'];
foreach ($v as $p) {
    Presence::touch($p, '127.0.0.1');
}
$tid5 = Tournament::create($v[0], false)['tid'];
Tournament::join($v[1], $tid5);
Tournament::start($v[0], $tid5);
$v5 = Tournament::view($v[0], $tid5);
$p5 = $v5['roles']['players'];
Tournament::report($p5[1], $tid5, 'r1.1', 'loss', [0, 9], null);
$v5 = Tournament::view($v[0], $tid5);
ok($v5['cursor'] === 'final', 'both of two players reach the final');
// Both of them go dark, and the match has been in flight long enough.
Settings::set('tournament_walkover_ms', 1);
Db::get()->prepare('UPDATE players SET last_seen = 1 WHERE id = ? OR id = ?')->execute($v);
$v5 = Tournament::view($v[0], $tid5);
ok($v5['bracket'][0]['state'] === 'void' && $v5['bracket'][0]['winner'] === null,
    'a final neither side could play is voided, never replayed');
ok($v5['state'] === 'done' && $v5['cursor'] === null,
    'and the tournament ends rather than waiting on it forever');
Settings::set('tournament_walkover_ms', 180000);
foreach ($v as $p) {
    Presence::touch($p, '127.0.0.1');
}

// ---- A decided node cannot be reopened by a late report --------------
$w = ['75000001', '75000002'];
foreach ($w as $p) {
    Presence::touch($p, '127.0.0.1');
}
$tid6 = Tournament::create($w[0], false)['tid'];
Tournament::join($w[1], $tid6);
Tournament::start($w[0], $tid6);
$v6 = Tournament::view($w[0], $tid6);
$p6 = $v6['roles']['players'];
ok(Tournament::report($p6[1], $tid6, 'r1.1', 'loss', [1, 9], null)['state'] === 'settled',
    'the loser settles the match on its own');
// The same player now claims the opposite. Applied, it would freeze a node
// nobody was disputing; the winner never even reported it.
$late = Tournament::report($p6[1], $tid6, 'r1.1', 'win', [9, 1], null);
ok($late['state'] === 'settled', 'a late report is answered with the state that already stands');
$v6 = Tournament::view($w[0], $tid6);
ok($v6['schedule'][0]['state'] === 'settled' && $v6['schedule'][0]['winner'] === $p6[0],
    'and cannot re-decide, freeze or replay what is already closed');


// Cleanup
Db::close();
foreach (glob($tmp . '/backups/*') ?: [] as $f) {
    unlink($f);
}
foreach (glob($tmp . '/*') ?: [] as $f) {
    if (is_file($f)) {
        unlink($f);
    }
}
@rmdir($tmp . '/backups');
@rmdir($tmp);

echo "OK ($tests assertions)\n";
