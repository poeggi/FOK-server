<?php
declare(strict_types=1);

// Unit tests for the src/ classes, run against a throwaway data dir.
// No framework: ok() below, exit 1 on the first failure.

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

require_once __DIR__ . '/../public/src/Util.php';
require_once __DIR__ . '/../public/src/Counters.php';
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
require_once __DIR__ . '/../public/src/Holds.php';
require_once __DIR__ . '/../public/src/Pace.php';
require_once __DIR__ . '/../public/src/Skew.php';
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
require_once __DIR__ . '/../public/src/TourneyStore.php';
require_once __DIR__ . '/../public/src/Stats.php';
require_once __DIR__ . '/../public/src/AdminData.php';
require_once __DIR__ . '/../public/src/Housekeeping.php';

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

/**
 * Runs $body inside ONE utc minute. Counters bucket by gmdate('YmdHi'), so a
 * test that writes in one call and reads back in another is asserting about
 * the clock as much as about the code: let the minute turn in between and the
 * read looks in a bucket the write never touched. The body runs again when
 * that happened - it is written to be repeatable - and the last pass's value
 * is what comes back.
 */
function inOneMinute(callable $body)
{
    $out = null;
    for ($try = 0; $try < 5; $try++) {
        $minute = gmdate('YmdHi');
        $out = $body();
        if (gmdate('YmdHi') === $minute) {
            break;
        }
    }
    return $out;
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

// Util: the cleartext gate. Only what the server itself observed counts, and
// "off" is what Apache sets on a plain connection - an empty string is not the
// only way to be insecure.
ok(Util::isSecureTransport('on', '443') === true, 'HTTPS on is secure');
ok(Util::isSecureTransport('1', '80') === true, 'a truthy HTTPS var alone is secure');
ok(Util::isSecureTransport('', '443') === true, 'port 443 alone is secure');
ok(Util::isSecureTransport('off', '80') === false, 'HTTPS off is cleartext');
ok(Util::isSecureTransport('OFF', '80') === false, 'HTTPS off is case-insensitive');
ok(Util::isSecureTransport('', '80') === false, 'no HTTPS var and port 80 is cleartext');
ok(Util::isSecureTransport('', '') === false, 'nothing known is cleartext, not fail-open');

// Util: address-family classification for the peer-net hint.
ok(Util::ipInfo('1.2.3.4') === ['ip' => '1.2.3.4', 'family' => 4], 'ipv4 classified as family 4');
ok(Util::ipInfo('2a01:db8::5') === ['ip' => '2a01:db8::5', 'family' => 6], 'ipv6 classified as family 6');
ok(Util::ipInfo('::ffff:1.2.3.4') === ['ip' => '1.2.3.4', 'family' => 4], 'ipv4-mapped ipv6 unwrapped to family 4');
ok(Util::ipInfo('?')['family'] === 0, 'an unknown address is family 0');
// A network key, not an address: NAT makes one v4 address a whole household,
// while on v6 the household is the /64 and every device in it differs.
ok(Util::ipNet('1.2.3.4') === '1.2.3.4', 'a NATed ipv4 address is its own network');
ok(Util::ipNet('::ffff:1.2.3.4') === '1.2.3.4', 'and so is the mapped form of it');
ok(Util::ipNet('2a01:db8:1:2:3:4:5:6') === Util::ipNet('2a01:db8:1:2:ffff::9'),
    'two ipv6 devices on one lan share a network');
ok(Util::ipNet('2a01:db8:1:2::1') !== Util::ipNet('2a01:db8:1:3::1'),
    'a neighbouring /64 is a different network');
ok(Util::ipNet('2a01:db8:1:2::1') !== Util::ipNet('1.2.3.4'),
    'and a v6 network is never a v4 address');

// Presence: registration and counting
Presence::touch('aaaaaaaa', '1.2.3.4');
Presence::touch('bbbbbbbb', '5.6.7.8');
Presence::touch('aaaaaaaa', '1.2.3.9');
$c = Presence::counts();
ok($c['registered'] === 2, 'touch twice registers once');
ok($c['online'] === 2, 'both players online');
ok($c['playing'] === 0, 'no duels yet');
Presence::touch('cccccccc', '2a01:db8:1:2::7');
Presence::flushCounts();
$f = Presence::families();
ok($f['v6'] === 1, 'a client that came in over v6 is counted as v6');
ok($f['v4'] === 2, 'and the rest of the online clients are v4');
Presence::forget('cccccccc');
Presence::flushCounts();

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
Presence::forget('eeee0001');
Presence::forget('dddddddd');

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

// The roster hello's `friends_list` and friend.php `list` both serve
// (Friends::rosterOf): every row listOf returns, decorated with the peer
// status of its ACCEPTED half only.
Presence::touch('a05a0001', '4.4.4.1');
Presence::touch('a05a0002', '4.4.4.2', 25, 'BRAVO');
Presence::touch('a05a0003', '4.4.4.3', 30, 'CHARLIE');
Friends::request('a05a0001', 'a05a0002');
Friends::accept('a05a0002', 'a05a0001');
Friends::request('a05a0003', 'a05a0001');
$roster = array_column(Friends::rosterOf('a05a0001'), null, 'id');
ok(count($roster) === 2, 'the roster carries every row the plain list does');
ok($roster['a05a0002']['state'] === 'accepted' && $roster['a05a0002']['outgoing'] === true
    && $roster['a05a0002']['name'] === 'BRAVO' && $roster['a05a0002']['online'] === true
    && $roster['a05a0002']['latency'] === 25,
    'an accepted row is decorated with the peer status');
ok($roster['a05a0003']['state'] === 'pending' && $roster['a05a0003']['outgoing'] === false
    && $roster['a05a0003']['name'] === null && $roster['a05a0003']['online'] === false
    && $roster['a05a0003']['latency'] === null,
    'a pending row is listed but says nothing about the peer');
ok(Friends::rosterOf('a05a0004') === [], 'a player with no friends gets an empty roster');
Friends::remove('a05a0001', 'a05a0002');
Friends::remove('a05a0001', 'a05a0003');

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
Settings::set('player_ttl_days', 365);

// The quick-match queue lives in shared memory, so a test that needs a
// stale seeker or an empty queue edits the entries directly.
function mmSeeker(string $id, int $since, int $poll): void
{
    apcu_store(FOK_APCU_NS . 'mm:q:' . $id, ['since' => $since, 'poll' => $poll], FOK_MATCH_WINDOW);
}
function mmWipe(): void
{
    apcu_delete(new APCUIterator('/^' . preg_quote(FOK_APCU_NS . 'mm:', '/') . '/'));
}

// Matchmaking: first seeker waits, second gets matched, roles assigned
mmWipe();
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
// the entry expiring: expiry is lazy, so the ghost is still there to read.
mmWipe();
mmSeeker('44444444', time() - 20, time() - 20);
ok((Matchmaking::seek('55555555')['waiting'] ?? false) === true,
    'a stale seeker is never offered as a match');
Matchmaking::cancel('55555555');

// Matchmaking: the player who started looking first offers, whichever way
// the two ids happen to sort. Two seekers of one quick-match pair arrive
// milliseconds apart, so the wait is compared to the microsecond - to whole
// seconds these two are simultaneous and only the id order is left.
mmWipe();
ok((Matchmaking::seek('ffff0001')['waiting'] ?? false) === true, 'the higher id seeks first');
$m = Matchmaking::seek('00110011');
ok(($m['matched'] ?? '') === 'ffff0001', 'the lower id that came second is the one that pairs them');
ok(($m['role'] ?? '') === 'answerer', 'the newcomer answers');
ok((Matchmaking::seek('ffff0001')['role'] ?? '') === 'offerer',
    'and the one that waited longer offers, though its id sorts after');

// Matchmaking: two seekers that cannot be told apart by arrival at all fall
// back on their ids, so exactly one of them attempts the pair and neither
// can hand the other a match at the same moment and end up matched twice.
mmWipe();
$t = time();
mmSeeker('66666666', $t, $t);
mmSeeker('77777777', $t, $t);
ok((Matchmaking::seek('66666666')['waiting'] ?? false) === true,
    'the lower id of an equally old pair does not attempt it');
$m = Matchmaking::seek('77777777');
ok(($m['matched'] ?? '') === '66666666', 'the higher id is the one that pairs them');
ok(($m['role'] ?? '') === 'answerer', 'and takes the answerer role');
ok((Matchmaking::seek('66666666')['matched'] ?? '') === '77777777',
    'the peer collects the match on its next poll');

// Matchmaking: a seeker that is itself mid-attempt is not handed a match on
// top of it - the attempt that finds it busy waits for its next poll.
mmWipe();
mmSeeker('66666666', $t - 5, $t);
apcu_store(FOK_APCU_NS . 'mm:m:66666666', ['kind' => 'busy'], 2);
ok((Matchmaking::seek('77777777')['waiting'] ?? false) === true,
    'a busy seeker is not paired over its own attempt');

// Matchmaking: a cancel that arrives after the pairing cannot undo it - the
// peer was already told, and is waiting for the handshake.
mmWipe();
ok((Matchmaking::seek('66666666')['waiting'] ?? false) === true, 'seeker queues up');
ok((Matchmaking::seek('77777777')['matched'] ?? '') === '66666666', 'and is paired');
Matchmaking::cancel('66666666');
ok((Matchmaking::seek('66666666')['matched'] ?? '') === '77777777',
    'a cancel after delivery still lets the peer collect the match');
mmWipe();

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

// A row past the keep window is one no stale-epoch guard can reach any more,
// so request() answers as if it were not there - which is what lets the
// deletion move off the start path onto the hour (see Starts::prune).
Starts::forget('aaaaaaaa', 'bbbbbbbb');
Starts::request('aaaaaaaa', 'bbbbbbbb', 3, 'first');
Db::get()->prepare('UPDATE starts SET start_pts = ? WHERE a = ? AND b = ?')
    ->execute([Util::nowMs() - 600000, 'aaaaaaaa', 'bbbbbbbb']);
$aged = Starts::request('aaaaaaaa', 'bbbbbbbb', 3, 'first');
ok(is_int($aged) && $aged > Util::nowMs(),
    'a start past the keep window reads as absent, and a fresh one is issued');

// One duel is one count: the second peer and every repeat are answered from
// the stored row, so the tally counts duels rather than requests.
$startedBefore = Stats::all()['duel_started'] ?? 0;
ok(Starts::request('bbbbbbbb', 'aaaaaaaa', 3, 'first') === $aged, 'the peer joins that same start');
ok((Stats::all()['duel_started'] ?? 0) === $startedBefore,
    'and a start already issued counts no second duel');

// The relay hub and its rate guard live in APCu and have no database
// transport at all (see RelayStore), so start from a clean keyspace: a
// backlog left behind by an earlier run would make the counts below depend
// on history.
apcu_delete(new APCUIterator('/^fok:r[qr]:/'));

// RelayRate: the backlog is drained on delivery, so the send rate is tracked
// as a running total per client. The slice mark is pre-set so a full slice
// has already passed and the very next record() checks the rate.
apcu_store('fok:rr:dddddddd:t', 1000, 3600);
apcu_store('fok:rr:dddddddd:m', ['t' => 0, 's' => time() - 3], 3600);
RelayRate::record('dddddddd'); // ~334 msg/s over 3 s, far over the 128 default
ok(RelayRate::blocked('dddddddd'), 'a client over the sustained relay rate is blocked');
ok(RelayRate::totalOf('dddddddd') === 1001, 'the running message total is readable for the admin gauge');
$rateDetail = RelayRate::detail('dddddddd');
ok($rateDetail !== null && $rateDetail['total'] === 1001 && $rateDetail['blocked_until'] > time(),
    'the admin popup reads the total and the live block');
ok(RelayRate::detail('ffffffff') === null, 'a client that never relayed has no rate detail');
apcu_store('fok:rr:eeeeeeee:t', 10, 3600);
apcu_store('fok:rr:eeeeeeee:m', ['t' => 0, 's' => time() - 3], 3600);
RelayRate::record('eeeeeeee'); // ~3 msg/s, comfortably under the cap
ok(!RelayRate::blocked('eeeeeeee'), 'a client under the sustained relay rate is not blocked');
ok(!RelayRate::blocked('ffffffff'), 'an unseen client is never blocked');

// RelayStore: exactly-once and ordered, and push() reports success so the
// caller does not turn it into a 429.
ok(RelayStore::push('11111111', '22222222', 'IN:1') === true, 'a relayed message enqueues');
RelayStore::push('11111111', '22222222', 'IN:2');
ok(RelayStore::hasAny('22222222', '11111111'), 'the receiver sees a pending message');
ok(!RelayStore::hasAny('11111111', '22222222'), 'the sender has nothing pending back');
ok(RelayStore::pending('22222222', '11111111') === 2, 'pending counts the receiver backlog from the sender');
ok(RelayStore::shouldTrackRelay('11111111', '22222222', time()),
    "the pair's first message refreshes its liveness marker");
ok(!RelayStore::shouldTrackRelay('11111111', '22222222', time()),
    'the next one is throttled off the single writer');
$drained = RelayStore::drain('22222222', '11111111');
ok(count($drained) === 2 && $drained[0]['payload'] === 'IN:1' && $drained[1]['payload'] === 'IN:2',
    'the backlog drains oldest first');
// created is exposed in whole seconds; age is ms the message spent on the server.
ok($drained[0]['created'] >= time() - 2 && $drained[0]['created'] <= time(),
    'created is exposed in whole seconds');
ok($drained[0]['age'] >= 0 && $drained[0]['age'] < 5000, 'age is milliseconds on the server');
ok(RelayStore::drain('22222222', '11111111') === [], 'a drained backlog is empty (exactly-once)');
ok(RelayStore::pending('22222222', '11111111') === 0, 'a drained backlog is no longer pending');
// A bye must leave nothing behind: an undelivered input reaching the pair's
// NEXT duel would be an input from the wrong game.
RelayStore::push('11111111', '22222222', 'IN:3');
RelayStore::markAdmitted('11111111', '22222222');
ok(RelayStore::admitted('22222222', '11111111'), 'a relaying pair is admitted from either side');
RelayStore::forgetPair('11111111', '22222222');
ok(!RelayStore::hasAny('22222222', '11111111'), 'a bye drops the undelivered backlog');
ok(!RelayStore::admitted('11111111', '22222222'), 'and releases the relay slot for a rematch');

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
// sweepNow() only lifts the sweep's once-a-second rate gate; the receipt
// itself is produced by the ordinary sweep inside take().
Signals::sendAged('aaaaaaaa', 'bbbbbbbb', 'invite', 'old', FOK_SIGNAL_TTL + 1);
ok(Signals::take('bbbbbbbb') === [], 'expired signal not delivered');
Signals::sweepNow();
$receipt = Signals::take('aaaaaaaa');
ok(count($receipt) === 1, 'sender gets a receipt for the expired invite');
ok($receipt[0]['type'] === 'undelivered', 'receipt is an undelivered signal');
ok($receipt[0]['from'] === 'bbbbbbbb', 'receipt names the peer that never picked it up');
ok(str_contains($receipt[0]['payload'], '"type":"invite"'), 'receipt names the lost message type');

// Signals: the receipt must survive a FULL mailbox - a flood must not be
// able to swallow the one message that says the connection failed.
Signals::sendAged('aaaaaaaa', 'bbbbbbbb', 'invite', 'old', FOK_SIGNAL_TTL + 1);
for ($i = 0; $i < FOK_MAILBOX_CAP; $i++) {
    Signals::send('cccccccc', 'aaaaaaaa', 'ice', "flood$i");
}
ok(!Signals::send('cccccccc', 'aaaaaaaa', 'ice', 'over'), 'mailbox really is full');
Signals::sweepNow();
$flooded = Signals::take('aaaaaaaa');
ok(count(array_filter($flooded, static fn(array $s) => $s['type'] === 'undelivered')) === 1,
    'receipt is delivered even past a full mailbox');
Signals::take('bbbbbbbb');

// Signals: an expiring message nobody waits on generates no receipt
Signals::sendAged('aaaaaaaa', 'bbbbbbbb', 'ice', 'old', FOK_SIGNAL_TTL + 1);
ok(Signals::take('bbbbbbbb') === [], 'expired ice not delivered');
Signals::sweepNow();
ok(Signals::take('aaaaaaaa') === [], 'no receipt for an expired ice candidate');

// Signals: an expired message must not wake a long poll (any() and take()
// have to agree on the TTL, or poll.php answers 200 with an empty list)
Signals::sendAged('aaaaaaaa', 'bbbbbbbb', 'ice', 'old', FOK_SIGNAL_TTL + 1);
ok(!Signals::any('bbbbbbbb'), 'expired signal does not count as pending');
Signals::take('bbbbbbbb');

// Signals: a sequence that has simply EXPIRED is not an eviction and must
// not be reported as one. apcu_inc applies its TTL to the key it CREATES
// and not to the ones it goes on to increment, while the ack is rewritten -
// TTL and all - every time the mailbox is read. So a mailbox in use for
// longer than the sequence TTL outlives its own sequence as a matter of
// routine, and the repair is simply to re-seed the ack.
$desyncs = static fn(): int => count(array_filter(
    Alerts::recent(),
    static fn(array $a): bool => str_contains($a['message'], 'Signal seq/ack desync')
));
Signals::send('aaaaaaaa', 'bbbbbbbb', 'ice', 'one');
Signals::take('bbbbbbbb');                          // the ack now stands at 1
$wasDesync = $desyncs();
apcu_delete('fok:sg:bbbbbbbb:seq');                 // ...and the sequence times out
ok(!Signals::any('bbbbbbbb'), 'an expired sequence reads as an empty mailbox');
ok($desyncs() === $wasDesync, 'and routine expiry raises no alert');
Signals::send('aaaaaaaa', 'bbbbbbbb', 'ice', 'two');
ok(Signals::any('bbbbbbbb'), 'the re-seeded mailbox sees the next signal');
ok(count(Signals::take('bbbbbbbb')) === 1, 'and delivers it');

// The other half: a sequence still PRESENT and below the ack cannot happen
// without an eviction, and that one is worth waking somebody for.
Signals::send('aaaaaaaa', 'bbbbbbbb', 'ice', 'three');
apcu_store('fok:sg:bbbbbbbb:ack', 99, 3600);
$wasDesync = $desyncs();
ok(!Signals::any('bbbbbbbb'), 'an evicted sequence reads as empty too');
ok($desyncs() === $wasDesync + 1, 'but a real eviction is alerted');
apcu_delete(new APCUIterator('/^fok:sg:bbbbbbbb:/'));

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
// The tracked connections live in shared memory, so a test that needs an
// aged or a cleared one edits the entries directly - the same way the
// mailbox tests above reach into the signal keys.
function connPoke(string $id, array $fields): void
{
    $e = ConnTrack::stateOf($id);
    if ($e !== null) {
        apcu_store(ConnTrack::key($id), $fields + $e, ConnTrack::TTL);
    }
}
function connWipe(): void
{
    apcu_delete(new APCUIterator('/^' . preg_quote(FOK_APCU_NS . 'conn:', '/') . '/'));
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
ok(duelOf('aaaaaaaa')['peer'] === 'bbbbbbbb', 'the ended entry still names the peer');
connPoke('aaaaaaaa', ['updated' => time() - FOK_DUEL_LINGER - 1]);
connPoke('bbbbbbbb', ['updated' => time() - FOK_DUEL_LINGER - 1]);
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

// ConnTrack: an ICE burst is a run of same-state 'connecting' signals, each
// of which re-applies the mode rule (see ConnTrack::set). A same-state ice
// must leave a negotiated mode alone, and a relay declaration arriving in
// the middle of the burst must still upgrade both sides - the p2p -> relay
// bit is the one thing in such a burst that means anything.
ConnTrack::note('aaaaaaaa', 'bbbbbbbb', 'bye');
ConnTrack::note('aaaaaaaa', 'bbbbbbbb', 'invite');
ConnTrack::note('bbbbbbbb', 'aaaaaaaa', 'accept');
ok(duelOf('aaaaaaaa')['mode'] === 'p2p', 'a fresh accept negotiates p2p');
ConnTrack::note('aaaaaaaa', 'bbbbbbbb', 'ice');
ok(duelOf('aaaaaaaa')['mode'] === 'p2p', 'a same-state ice refresh leaves the mode untouched');
ConnTrack::note('aaaaaaaa', 'bbbbbbbb', 'accept-relay');
ok(duelOf('aaaaaaaa')['mode'] === 'relay', 'a relay upgrade lands in the middle of the connecting burst');
ok(duelOf('bbbbbbbb')['mode'] === 'relay', 'the peer side sees the mid-burst upgrade too');

// ConnTrack: a duel that goes quiet (no bye reached us) is shown as ended
// for the linger window, then drops off.
connPoke('aaaaaaaa', ['updated' => time() - FOK_CONN_TTL - 1]);
ok(duelOf('aaaaaaaa')['state'] === 'ended', 'a quiet duel reads as ended');
connPoke('aaaaaaaa', ['updated' => time() - FOK_CONN_TTL - FOK_DUEL_LINGER - 1]);
ok(duelOf('aaaaaaaa') === [], 'past the linger the quiet duel drops off the card');
ConnTrack::note('aaaaaaaa', 'bbbbbbbb', 'invite');
ConnTrack::forget('aaaaaaaa');
ok(duelOf('aaaaaaaa') === [], 'a forgotten client is off the Duels card');
ok(duelOf('bbbbbbbb') === [], 'forget drops the peer side as well');

// ConnTrack: a client with no player row is on neither card - not even one
// that IS tracked, which is a real state (an admin delete drops the player
// row) and used to be excluded by the card's JOIN.
ok(!onPresence('cccccccc'), 'an unknown client is not on the presence card');
ok(duelOf('cccccccc') === [], 'nor on the Duels card');
ConnTrack::note('cccccccc', 'dddddddd', 'invite');
ok(ConnTrack::stateOf('cccccccc') !== null, 'an unknown client can still be tracked');
ok(duelOf('cccccccc') === [], 'but it has no name, so it stays off the Duels card');

// ConnTrack: an id of nothing but digits is a valid id, and the entries it
// is looked up under are an array keyed by id - where PHP turns it into an
// integer.
connWipe();
Presence::touch('11111111', '10.11.12.1', null, 'ONES');
Presence::touch('22222222', '10.11.12.2', null, 'TWOS');
ConnTrack::note('11111111', '22222222', 'invite');
ok(duelOf('11111111')['id'] === '11111111', 'an all-digit id is on the card as a string');
ok(duelOf('22222222')['peer'] === '11111111', 'and names its peer as one');
ConnTrack::forget('22222222');
ok(duelOf('11111111') === [], 'forgetting the peer of an all-digit id drops both sides');

// ConnTrack: a quick-match seeker shows as matchmaking only while it is
// actively polling; one that went quiet drops off (as the matchmaker does).
connWipe();
mmWipe();
mmSeeker('aaaaaaaa', time(), time());
ok(duelOf('aaaaaaaa')['state'] === 'matchmaking', 'an active seeker shows as matchmaking');
mmSeeker('aaaaaaaa', time(), time() - FOK_MATCH_WINDOW - 1);
ok(duelOf('aaaaaaaa') === [], 'a seeker that stopped polling drops off the Duels card');
mmWipe();

// Relay: relay admission is counted from the hub traffic a pair
// really caused, not from queued messages (gone the instant the receiver
// drains them) and not from what a client claims.
connWipe();
ok(Relay::activePairs() === 0, 'no relayed pairs on a quiet server');
ok(!Relay::isRelaying('aaaaaaaa', 'bbbbbbbb'), 'idle pair holds no relay slot');
Relay::markRelaying('aaaaaaaa', 'bbbbbbbb');
ok(Relay::activePairs() === 1, 'relaying pair counted once');
ok(Relay::isRelaying('aaaaaaaa', 'bbbbbbbb'), 'relaying pair holds its slot');
ok(Relay::isRelaying('bbbbbbbb', 'aaaaaaaa'), 'slot is held from either side');
Relay::markRelaying('bbbbbbbb', 'aaaaaaaa');
ok(Relay::activePairs() === 1, 'both directions are still one pair');
connPoke('aaaaaaaa', ['relay_seen' => time() - FOK_RELAY_WINDOW - 1]);
connPoke('bbbbbbbb', ['relay_seen' => time() - FOK_RELAY_WINDOW - 1]);
ok(Relay::activePairs() === 0, 'a pair that stopped relaying frees its slot');

// Relay: a DECLARATION must never take a relay slot. accept-relay is
// not friendship-gated, so if a claim counted, a handful of invented
// pairs would deny the relay to everyone.
connWipe();
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

// A rematch INSIDE the write throttle. The pair's slot is stamped at most
// once per FOK_RELAY_TRACK_THROTTLE (RelayStore::shouldTrackRelay), so a bye
// that zeroed the stamp and left that marker standing would leave the pair's
// next relayed duel unmarked for the rest of the window: it would hold no
// slot at all, uncounted by the duel cap and absent from the admin cards,
// while running.
connWipe();
ok(RelayStore::shouldTrackRelay('aaaaaaaa', 'bbbbbbbb', time()),
    'the first relayed message of a duel marks the pair');
Relay::markRelaying('aaaaaaaa', 'bbbbbbbb');
ok(Relay::activePairs() === 1, 'which is how it holds its slot');
ConnTrack::note('bbbbbbbb', 'aaaaaaaa', 'bye');
ok(Relay::activePairs() === 0, 'the bye hands the slot straight back');
ok(RelayStore::shouldTrackRelay('aaaaaaaa', 'bbbbbbbb', time()),
    'and takes the throttle with it, so a rematch re-marks the pair at once');
Relay::markRelaying('aaaaaaaa', 'bbbbbbbb');
ok(Relay::activePairs() === 1, 'so the rematch holds a slot of its own');
connWipe();

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

// Load: the gauges accumulate in memory during the request and hand off to
// the counter buffer once, after it - no write of their own (see Load::flush).
// (Keep no statement handle alive across the run - a live PDOStatement pins
// its connection open and breaks the later restore test on Windows.)
$dropGauges = static function (): void {
    Db::get()->exec("DELETE FROM counters WHERE metric IN ('n:msg_out', 'n:db_w')");
};
// Folds the RUNNING minute too, so the value is in the table instead of still
// buffered; summed over the minute buckets because every assertion below starts
// from an empty one, so the total IS the value, and a minute turning mid-test
// can no longer hide half of it in the next bucket. Minute buckets only: a fold
// files the same count into the hour as well, and both would count it twice.
$loadVal = static function (string $metric): int {
    Counters::flushDue(gmdate('YmdHi', time() + 60));
    $st = Db::get()->prepare('SELECT COALESCE(SUM(value), 0) FROM counters
                              WHERE metric = ? AND length(bucket) = 12');
    $st->execute(["n:$metric"]);
    $n = (int)$st->fetchColumn();
    $st->closeCursor();
    return $n;
};
Load::flush();      // drain anything pending from above
$dropGauges();      // one write: counted as db load
Load::tick('msg_out', 3);
Load::tick('msg_out', 2);
Load::flush();
ok($loadVal('msg_out') === 5, 'msg_out accumulates then folds as one total');

// The PDO wrapper counts a write query as db load, a read as none - and the
// fold's own write is none of it, or the monitoring would book itself to
// whichever request happened to carry it (see Load::untracked).
Load::flush();
$dropGauges();                     // exactly one write since the flush
Db::get()->query('SELECT 1');      // a read: must not count
Load::flush();
ok($loadVal('db_w') === 1, 'the wrapper counts one write, no reads and no fold');
$dropGauges();

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
    $n = (int)$s->fetchColumn();
    $s->closeCursor();
    return $n;
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
Signals::purgeAll();
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
Signals::purgeAll();

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

// The overrides are cached in shared memory: a settings read sits on nearly
// every path, and the table behind it changes only when an operator saves.
// The save is what drops the cache, so a stale value cannot outlive it.
Settings::int('chat_max_len');
ok(is_array(apcu_fetch(FOK_APCU_NS . 'cfg')), 'a settings read caches the overrides');
Settings::set('chat_max_len', 77);
ok(apcu_fetch(FOK_APCU_NS . 'cfg') === false, 'and a save drops that cache');
ok(Settings::int('chat_max_len') === 77, 'so the next read answers with the saved value');

// The capability assessment is cached the same way and keyed by release, so
// a deploy re-probes a host that may have changed under it.
apcu_delete(FOK_APCU_NS . 'caps:' . FOK_SERVER_VERSION);
$caps = Caps::refresh();
ok(is_array(apcu_fetch(FOK_APCU_NS . 'caps:' . FOK_SERVER_VERSION)),
    'a capability assessment is cached in shared memory');
ok(apcu_fetch(FOK_APCU_NS . 'caps:' . FOK_SERVER_VERSION)['version'] === FOK_SERVER_VERSION,
    'under a key only this release reads');
Caps::forget();
ok(apcu_fetch(FOK_APCU_NS . 'caps:' . FOK_SERVER_VERSION) === false,
    'and a restore drops it, so a foreign database cannot answer for this host');
ok(Caps::get()['version'] === $caps['version'], 'after which the stored assessment answers again');

// Alerts: the lockout above alerted, de-duplicated, seen-tracking. A single
// failed login deliberately does NOT alert - it is noted (see Alerts).
ok(Alerts::unseenCount() > 0, 'the admin lockout raised an alert');
ok(array_filter(Alerts::recent(), static fn(array $a) => $a['type'] === 'admin-fail') === [],
    'a single failed login raises no alert');
Alerts::note('test-note', 'read back, not acted on');
ok(array_filter(Alerts::recent(), static fn(array $a) => $a['type'] === 'test-note') === [],
    'a note stores no alert row');
Alerts::raise('test-x', 'first');
Alerts::raise('test-x', 'second within cooldown');
$testX = array_filter(Alerts::recent(), static fn(array $a) => $a['type'] === 'test-x');
ok(count($testX) === 1, 'same alert type de-duplicated within cooldown');
// Shared memory answers the repeat for free, but it is never the truth: a
// flushed segment (a pool restart) must not turn one sustained condition
// into a burst of rows.
apcu_delete(FOK_APCU_NS . 'alert:test-x');
ok(Alerts::raise('test-x', 'third with the gate flushed') === false,
    'and still de-duplicated from the table when the shared-memory gate is gone');
ok(count(array_filter(Alerts::recent(), static fn(array $a) => $a['type'] === 'test-x')) === 1,
    'so a flush costs a query, never a duplicate row');
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
// A fold writes each metric twice - into the hour bucket and into its own
// minute bucket, the two windows the dashboard offers - so a reader has to
// say which of them it means. Ten digits is an hour, twelve is a minute.
// (The length goes into the statement, not into a parameter: PDO binds a
// parameter as text, and SQLite never equates text with the integer length()
// hands back, so the bound form matches nothing.)
$countIn = function (int $digits): callable {
    return function (string $metric) use ($digits): int {
        $st = Db::get()->prepare('SELECT COALESCE(SUM(value), 0) FROM counters
                                  WHERE metric = ? AND length(bucket) = ' . $digits);
        $st->execute([$metric]);
        $n = (int)$st->fetchColumn();
        $st->closeCursor();
        return $n;
    };
};
$countOf = $countIn(10);
$minuteOf = $countIn(12);
$before = $countOf('unittest');
Util::bump('unittest');
ok($countOf('unittest') === $before, 'bump writes nothing before the answer is out');
Util::runDeferred();
// The count is real but buffered: the database sees one write per closed
// minute, not one per request (see Counters).
ok($countOf('unittest') === $before, 'and still nothing - the count is held in shared memory');
Counters::flushDue(gmdate('YmdHi', time() + 60));
ok($countOf('unittest') === $before + 1, 'a closed minute is folded into the database');
Counters::flushDue(gmdate('YmdHi', time() + 60));
ok($countOf('unittest') === $before + 1, 'and folding it again does not double-count');
// The same fold, in the same statement, also files the minute on its own:
// the Live tab offers both windows and an hour is not a minute times sixty.
ok($minuteOf('unittest') > 0, 'the fold files the minute bucket as well as the hour');

// The endpoint metric and the shared request counter are folded in ONE
// statement per closed minute, so prove the second row is still written.
$reqMinOf = function (): int {
    $st = Db::get()->query("SELECT COALESCE(SUM(value), 0) FROM counters WHERE metric = 'req_min'");
    $n = (int)$st->fetchColumn();
    $st->closeCursor();
    return $n;
};
$rm = $reqMinOf();
Util::bump('unittest');
Util::runDeferred();
Counters::flushDue(gmdate('YmdHi', time() + 60));
ok($reqMinOf() === $rm + 1, 'the per-minute request counter rides along');
// A minute's requests are counted in shared memory and written once, so the
// writer sees one statement no matter how many requests arrived in it.
$rm = $reqMinOf();
for ($i = 0; $i < 20; $i++) {
    Util::bump('unittest');
}
Util::runDeferred();
ok($reqMinOf() === $rm, 'twenty requests in one minute write nothing yet');
Counters::flushDue(gmdate('YmdHi', time() + 60));
ok($reqMinOf() === $rm + 20, 'and land as a single folded write');

// ... and that hit() still RETURNS that running total to its caller. Miss
// it and reqPerMin reads 0, the sampling never hits a multiple of 25, and
// the traffic alert dies silently - monitoring that fails quietly is worse
// than none. The alert reads shared memory, so it fires on the live minute
// rather than waiting for the fold.
Settings::set('alert_req_per_min', 1);
$traffic = inOneMinute(static function (): array {
    Db::get()->exec("DELETE FROM counters WHERE metric = 'req_min'");
    Db::get()->exec("DELETE FROM alerts WHERE type = 'traffic'");
    for ($i = 0; $i < 25; $i++) {
        Util::bump('unittest');
        Util::runDeferred();
    }
    return array_filter(Alerts::recent(), static fn(array $a) => $a['type'] === 'traffic');
});
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
// A replay is answered from the ledger, and counted by nothing: the
// transaction that settled the transfer already counted this claim, and a
// client that re-sends must not be able to inflate its own tally.
$tally = static function (string $id): array {
    $st = Db::get()->prepare(
        'SELECT claims_ok, claims_untagged, claims_disputed FROM players WHERE id = ?'
    );
    $st->execute([$id]);
    $row = $st->fetch();
    $st->closeCursor();
    return $row === false ? [] : $row;
};
$countedBefore = $tally('aa11aa11');
$res = Items::claim('aa11aa11', $m['mid'], $uid, 'aa11aa11', 'bb22bb22', 5, 0, 'ws',
    Ledger::mac($m['sec_a'], $m['mid'], 5, 'ws'), null);
ok($res['ok'] && $res['state'] === 'confirmed', 'the identical claim replayed reads as already done');
ok($tally('aa11aa11') === $countedBefore, 'and is counted once, not once per retry');
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

// The window a match accepts claims in runs from its DUEL, not from the mint.
$m3 = Items::openMatch($idb, 'aa11aa11', 'bb22bb22', Util::nowMs());
$u3 = Items::mint('aa11aa11', 'boots', 'box')['uid'];
$idb->prepare('UPDATE matches SET opened = ? WHERE mid = ?')
    ->execute([Util::nowMs() - 3600000, $m3['mid']]);
$res = Items::claim('aa11aa11', $m3['mid'], $u3, 'aa11aa11', 'bb22bb22', 11, 0, 'ws3',
    Ledger::mac($m3['sec_a'], $m3['mid'], 11, 'ws3'), null);
ok(!$res['ok'] && $res['error'] === 'item_out_of_match',
    'a claim arriving after the window closed is refused');
Presence::touchDuel('aa11aa11', 'bb22bb22');
$res = Items::claim('aa11aa11', $m3['mid'], $u3, 'aa11aa11', 'bb22bb22', 12, 0, 'ws3',
    Ledger::mac($m3['sec_a'], $m3['mid'], 12, 'ws3'), null);
ok($res['ok'] && $res['state'] === 'settled',
    'while a duel still reporting in keeps its match claimable however long it has run');
Items::openMatch($idb, 'cc33cc33', 'dd44dd44', Util::nowMs());
$st = $idb->prepare('SELECT COUNT(*) FROM matches WHERE mid = ?');
$st->execute([$m3['mid']]);
ok((int)$st->fetchColumn() === 1, 'and the prune spares a match whose duel is alive');
$st->closeCursor();

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

// The round ladder: a round is played at the level of its own number, so the
// size of the lobby is what decides how deep the final gets - and it stops at
// the last level the game actually has.
ok(Bracket::level(1, 10) === 1 && Bracket::level(2, 10) === 2 && Bracket::level(4, 10) === 4,
    'each round is played one level deeper than the one before it');
ok(Bracket::level(0, 10) === 1, 'and nothing is ever played below level 1');
ok(Bracket::level(12, 10) === 10, 'the ladder stops at the last level the game has');
ok(Bracket::stage(1, 6) === 'group' && Bracket::stage(4, 1) === 'final'
    && Bracket::stage(3, 2) === 'semi' && Bracket::stage(2, 4) === 'quarter',
    'a stage is named after the number of matches in it');
ok(Bracket::stage(2, 8) === 'ko', 'and a wider round has no common name of its own');
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
ok($view['roles']['hm'] === 2 && $view['roles']['lvl'] === 1 && $view['roles']['stage'] === 'group',
    'round-1 matches are the group stage, played at 2 hearts and at level 1');

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

// The rest of round 1: whoever is listed first wins, reported by both sides.
for ($step = 0; $step < 40; $step++) {
    $view = Tournament::view($tp[0], $tid);
    if ($view['state'] !== 'running' || $view['cursor'] === null) {
        break;
    }
    [$x, $y] = $view['roles']['players'];
    Tournament::report($x, $tid, $view['cursor'], 'win', [10, 3], null);
    Tournament::report($y, $tid, $view['cursor'], 'loss', [3, 10], null);
}

// ---- The break between two rounds ------------------------------------
// A finished round does NOT roll straight into the next one: it stops on a
// scoreboard everybody gets to read, and waits for the host to press on.
$view = Tournament::view($tp[0], $tid);
ok($view['state'] === 'running' && $view['cursor'] === null && $view['break'] !== null,
    'a finished round stops on a break instead of dealing the next one');
$brk = $view['break'];
ok($brk['done'] === 1 && $brk['next'] === 2 && $brk['stage'] === 'final',
    'the board names the round that ended and the stage about to start');
ok($view['round'] === 2, 'and the round runs ahead into the break, as it does at every boundary');
ok($brk['lvl'] === 2 && $view['bracket'][0]['lvl'] === 2,
    'the next round is played one level deeper than round 1');
ok(count($brk['rows']) === 4 && count($brk['advancers']) === 2,
    'every player is on the board, and half of them are through');
ok($brk['rows'][0]['adv'] === true && $brk['rows'][3]['adv'] === false,
    'ordered so that whoever is through comes first');
ok($brk['rows'][0]['name'] !== null, 'and a row carries the name a scoreboard has to print');
$played = 0;
foreach ($brk['rows'] as $r) {
    $played += $r['w'] + $r['l'] + $r['d'];
}
ok($played === 12, 'w/l/d count the round that just ended - 6 matches, two sides each');
$early = Tournament::proceed($tp[0], $tid);
ok($early['http'] === 409 && $early['retry_ms'] > 0,
    'a continue that beats the minimum wait is refused, with how long is left');
Settings::set('tournament_break_ms', 0);
ok(Tournament::proceed('79999999', $tid)['http'] === 403, 'a stranger cannot press on');
ok(Tournament::proceed($tp[1], $tid)['http'] === 403, 'and neither can a player who is not the host');
ok(Tournament::proceed($tp[0], $tid)['ok'] === true, 'the host presses on');
$view = Tournament::view($tp[0], $tid);
ok($view['break'] === null && $view['cursor'] === 'final', 'and the knockout is dealt at last');
ok($view['roles']['lvl'] === 2 && $view['roles']['stage'] === 'final',
    'the roles sheet hands the two finalists the level and the stage they are in');
ok(Tournament::proceed($tp[0], $tid)['ok'] === true, 'pressing on again is a no-op, not an error');

// The final, played the same way, and the podium.
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
ok($view['break'] === null, 'and a tournament that is over is not waiting on anything');
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
ok($v2['cursor'] === null && $v2['break']['next'] === 2,
    'a two-player round 1 stops on the break as well');
ok($v2['break']['rows'][0]['adv'] === true && $v2['break']['rows'][1]['adv'] === true,
    'where both of them are through, because a knockout needs two');
ok(Tournament::proceed($q[0], $tid2)['ok'] === true, 'the host presses on');
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
$seeker = '72000009';
Presence::touch($seeker, '127.0.0.1');
ok(count(Tournament::announce($seeker, '127.0.0.1')) >= 1, 'an open lobby is announced on the host address');
ok(Tournament::announce('7200000a', '10.9.9.9') === [], 'but never to another address');
// The case the announce exists for and could not serve: two devices in one
// room, on ipv6, where nothing is NATed and the addresses never match.
Presence::touch('72000004', '2a01:db8:7:7::1');
$tid4 = Tournament::create('72000004', false)['tid'];
ok(count(Tournament::announce('7200000b', '2a01:db8:7:7:aaaa::9')) >= 1,
    'an ipv6 lobby is announced to the rest of the host /64');
ok(Tournament::announce('7200000c', '2a01:db8:7:8::9') === [], 'but not to the next /64 along');

// DUAL STACK, the case a single stored network could not serve: the host's
// last request came in over ipv6 and the joiner's over ipv4, which is what
// two browsers on one line do when each picks a family for itself. The host
// is on BOTH networks and has to be matched on either.
$dual = '72000005';
Presence::touch($dual, '203.0.113.9');            // seen once over ipv4
Presence::touch($dual, '2a01:db8:9:9::1');        // and now over ipv6
$tid5 = Tournament::create($dual, false)['tid'];
ok(count(Tournament::announce('72000006', '2a01:db8:9:9:beef::2')) >= 1,
    'a dual-stack host is announced to the ipv6 side of its line');
ok(count(Tournament::announce('72000007', '203.0.113.9')) >= 1,
    'and to the ipv4 side of the same line, though its last hello was ipv6');
ok(Tournament::announce('72000008', '203.0.113.10') === [],
    'but not to the ipv4 address next door');
// The joiner is the dual-stack one just as often: it asks over ipv4 while
// the network it shares with the host is the ipv6 one it used a moment ago.
Presence::touch('7200000d', '2a01:db8:9:9:cafe::7');
ok(count(Tournament::announce('7200000d', '198.51.100.4')) >= 1,
    'a joiner asking from its other family is still matched on the network it shares');
// Proof that the two above are served by player_nets and not by the player
// row: the row holds the LAST family only, so an ipv4 match off it is not
// possible. One row per family is also the whole bound on the table's size.
$netsOf = static function (string $id): array {
    $st = Db::get()->prepare('SELECT family, net FROM player_nets WHERE id = ? ORDER BY family');
    $st->execute([$id]);
    $rows = $st->fetchAll();
    $st->closeCursor();
    return array_column($rows, 'net', 'family');
};
$st = Db::get()->prepare('SELECT ipnet FROM players WHERE id = ?');
$st->execute([$dual]);
$dualRow = (string)$st->fetchColumn();
$st->closeCursor();
ok($dualRow === '2a01:db8:9:9::/64', 'the player row itself only remembers the last family');
ok($netsOf($dual) === [4 => '203.0.113.9', 6 => '2a01:db8:9:9::/64'],
    'both networks are kept, one row per family');
Presence::touch($dual, '203.0.113.55');
ok($netsOf($dual) === [4 => '203.0.113.55', 6 => '2a01:db8:9:9::/64'],
    'moving network overwrites that family rather than adding a row');

// A host that stopped being seen drops out of the announce - the lobby is
// still joinable by code, it just is not claimed to be in the room any more.
// The window is deliberately wider than presence: a host waiting in a lobby
// is a background tab, and those are throttled to about one hello a minute.
$age = static function (string $id, int $secs): void {
    $t = time() - $secs;
    Db::get()->prepare('UPDATE players SET last_seen = ? WHERE id = ?')->execute([$t, $id]);
    Db::get()->prepare('UPDATE player_nets SET seen = ? WHERE id = ?')->execute([$t, $id]);
};
$age($dual, 300);
ok(Tournament::announce('72000006', '2a01:db8:9:9:beef::2') === [],
    'a host last seen 5 minutes ago is no longer announced');
$age($dual, 90);
ok(count(Tournament::announce('72000006', '2a01:db8:9:9:beef::2')) >= 1,
    'but one throttled to a hello a minute still is, where 60s would have dropped it');

// CLIENT-REPORTED NETWORKS. The server sees one address family per request
// and cannot ask a browser for the other, so the second one is only ever
// known because the client discovered it (STUN) and said so. It is a claim,
// not evidence, and the rules that keeps it honest are asserted here.
ok(Util::isPublicIp('203.0.113.9'), 'a public v4 address counts as a network');
ok(Util::isPublicIp('2a01:db8:9:9::1'), 'so does a global v6 one');
ok(!Util::isPublicIp('192.168.1.20'), 'an rfc1918 address does not');
ok(!Util::isPublicIp('10.0.0.5'), 'nor another private range');
ok(!Util::isPublicIp('127.0.0.1'), 'nor loopback');
ok(!Util::isPublicIp('fe80::1'), 'nor a v6 link-local');
ok(!Util::isPublicIp('fd00::1'), 'nor a v6 unique-local');
ok(!Util::isPublicIp('a1b2c3d4-e5f6.local'), 'nor an mDNS placeholder, which is not an address at all');

// The case the whole feature exists for: this host has ONLY ever been seen
// over v6, so its v4 network cannot be observed - it has to be claimed.
$claimer = '72000010';
Presence::touch($claimer, '2a01:db8:11:11::1');
ok($netsOf($claimer) === [6 => '2a01:db8:11:11::/64'],
    'a v6-only client has no v4 network the server could have seen');
Presence::claim($claimer, ['198.51.100.77', 'fe80::dead', '192.168.0.9']);
ok($netsOf($claimer) === [4 => '198.51.100.77', 6 => '2a01:db8:11:11::/64'],
    'a claimed public v4 is recorded, and the private candidates beside it are dropped');
$tid6 = Tournament::create($claimer, false)['tid'];
ok(count(Tournament::announce('72000011', '198.51.100.77')) >= 1,
    'so a v4-only seeker is told about a lobby opened by a v6-only host');
Tournament::leave($claimer, $tid6);

// A claim never displaces what the server saw for itself. The v6 row above
// is observed and fresh, so a client claiming a different /64 for that
// family - which is what a forged or simply stale report looks like - is
// ignored rather than believed.
Presence::claim($claimer, ['2a01:db8:99:99::5']);
ok($netsOf($claimer) === [4 => '198.51.100.77', 6 => '2a01:db8:11:11::/64'],
    'a claim is ignored while the observed row for that family is fresh');
// And a claim cannot be churned: the first one is a minute old at most, so
// the next different one waits rather than sweeping networks per heartbeat.
Presence::claim($claimer, ['198.51.100.78']);
ok($netsOf($claimer) === [4 => '198.51.100.77', 6 => '2a01:db8:11:11::/64'],
    'nor can a claim be rewritten faster than an observation would be');
// Once it has gone stale it may be corrected - a real client does move.
Db::get()->prepare('UPDATE player_nets SET seen = ? WHERE id = ? AND family = 4')
    ->execute([time() - 120, $claimer]);
Presence::claim($claimer, ['198.51.100.78']);
ok($netsOf($claimer) === [4 => '198.51.100.78', 6 => '2a01:db8:11:11::/64'],
    'a stale claim is replaced by the next one the client sends');
// The server observing that family for real outranks the claim at once.
Presence::touch($claimer, '198.51.100.90');
ok($netsOf($claimer) === [4 => '198.51.100.90', 6 => '2a01:db8:11:11::/64'],
    'and an observation takes the row back from a claim immediately');
ok(count(Tournament::announce('72000012', '198.51.100.78')) === 0,
    'the replaced network stops matching');

// The trust rule on its own, with the churn guard out of the way: an
// observation that is too old to be refreshed but young enough for the
// announce to still act on it OUTRANKS a claim. Only once it has aged out
// of the announce entirely - it is doing no work by then - may a claim take
// the row. This is the boundary that decides whether telling the server
// "I am on /64 X" can put you in a stranger's room.
$ageNet = static function (string $id, int $family, int $secs) use ($claimer): void {
    Db::get()->prepare('UPDATE player_nets SET seen = ? WHERE id = ? AND family = ?')
        ->execute([time() - $secs, $id, $family]);
};
$ageNet($claimer, 6, 120);
Presence::claim($claimer, ['2a01:db8:99:99::5']);
ok($netsOf($claimer)[6] === '2a01:db8:11:11::/64',
    'a stale observation still outranks a claim while the announce would act on it');
$ageNet($claimer, 6, 400);
Presence::claim($claimer, ['2a01:db8:99:99::5']);
ok($netsOf($claimer)[6] === '2a01:db8:99:99::/64',
    'but once it has aged out of the announce, a claim may take the row');

Tournament::leave($dual, $tid5);
Tournament::leave('72000004', $tid4);
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

// The host ends it for everyone, before a ball is kicked...
$tid4 = Tournament::create('73000001', false)['tid'];
Tournament::leave('73000001', $tid4);
ok(Tournament::load($tid4)['state'] === 'abandoned', 'the host leaving an unstarted lobby ends it');
ok(Tournament::join('73000002', $tid4)['http'] === 404, 'which cannot then be joined');

// ...and once it is being played. Host and guest send the identical `leave`;
// the server is the only thing that makes the two mean different things, and
// the client has always told the host it means END TOURNAMENT FOR ALL.
$h = ['73100001', '73100002', '73100003'];
foreach ($h as $p) {
    Presence::touch($p, '127.0.0.1');
}
$tidH = Tournament::create($h[0], false)['tid'];
Tournament::join($h[1], $tidH);
Tournament::join($h[2], $tidH);
Tournament::start($h[0], $tidH);
foreach ($h as $p) {
    Signals::take($p);                  // clear the deal, so only the exit is read back
}
$before = Stats::all();
ok(Tournament::leave($h[0], $tidH)['ok'] === true, 'the host may leave a running tournament');
$tH = Tournament::load($tidH);
ok($tH['state'] === 'abandoned', 'which ends it rather than playing on without them');
ok($tH['data']['cursor'] === null, 'with nothing still pointing at a match nobody will play');
$told = 0;
foreach ($h as $p) {
    foreach (Signals::take($p) as $sig) {
        $e = json_decode($sig['payload'], true);
        if (($e['event'] ?? '') === 'lobby' && ($e['state'] ?? '') === 'abandoned') {
            $told++;
        }
    }
}
ok($told === 3, 'and every participant is dropped, the host included');
$after = Stats::all();
ok(($after['tourney_finished'] ?? 0) === ($before['tourney_finished'] ?? 0) + 1,
    'a tournament abandoned mid-run leaves the trace its played matches earned');
ok(Tournament::leave($h[1], $tidH)['ok'] === true, 'and a guest leaving afterwards is a no-op');

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
Tournament::proceed($v[0], $tid5);
$v5 = Tournament::view($v[0], $tid5);
ok($v5['cursor'] === 'final', 'both of two players reach the final');
// Both of them go dark, and the match has been in flight long enough.
// Zero, not one: the deadline is measured from the millisecond the final
// was dealt, which is the millisecond the report above dealt it, so any
// positive threshold is a race with the clock rather than a test.
Settings::set('tournament_walkover_ms', 0);
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

// ---- A break the host never presses through clears itself -------------
// The host is one browser tab among several, and it can close. The break
// has its own lazy deadline for exactly that: the tournament goes on by
// itself rather than staying wedged on a scoreboard nobody can dismiss.
$g = ['76000001', '76000002'];
foreach ($g as $p) {
    Presence::touch($p, '127.0.0.1');
}
$tid7 = Tournament::create($g[0], false)['tid'];
Tournament::join($g[1], $tid7);
Tournament::start($g[0], $tid7);
$v7 = Tournament::view($g[0], $tid7);
Tournament::report($v7['roles']['players'][1], $tid7, 'r1.1', 'loss', [1, 9], null);
Settings::set('tournament_break_ms', 60000);        // nobody could press in time
$v7 = Tournament::view($g[0], $tid7);
ok($v7['break'] !== null && $v7['cursor'] === null, 'the tournament waits on the board');
ok($v7['break']['wait'] === 60000 && $v7['break']['auto'] === 120000,
    'which tells the client both how long it must stay up and when it goes by itself');
Settings::set('tournament_break_ttl_ms', 0);
$v7 = Tournament::view($g[0], $tid7);
ok($v7['break'] === null && $v7['cursor'] === 'final',
    'and past the deadline it continues on its own, without anyone pressing');
Settings::set('tournament_break_ttl_ms', 120000);
Settings::set('tournament_break_ms', 0);

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


// ---- What outlives a tournament --------------------------------------
// Tournament state is disposable and lives in shared memory, so these
// counters are the only record that any of it ever happened.
$totals = Stats::all();
ok(($totals['tourney_created'] ?? 0) > 0, 'every created tournament is counted');
ok(($totals['tourney_finished'] ?? 0) > 0, 'and so is every one that played out');
ok(($totals['tourney_matches'] ?? 0) > 0, 'with the matches it actually played');
ok(($totals['duel_started'] ?? 0) > 0, 'a 1:1 is counted where play begins');
// A tournament lock is released by the worker that took it and by nobody
// else: the lease is short so a dead worker frees the tournament, which
// means it can also expire under a live one - and the next holder must not
// have the lock deleted out from under them.
ok(TourneyStore::lock('hklocktest'), 'a tournament lock is taken');
apcu_store('fok:tlock:hklocktest', 'another worker', 5);
TourneyStore::unlock('hklocktest');
ok(apcu_fetch('fok:tlock:hklocktest') === 'another worker',
    'and an unlock that no longer holds it leaves it alone');
apcu_delete('fok:tlock:hklocktest');
// The lifetime bucket shares the counters table with the per-minute request
// counts and the hourly traffic buckets, and that lookup is a STRING
// comparison - so anything that is not a YmdH stamp, whether a name or a
// twelve-digit minute, must never be drawn as an hour on the load graph.
$load = AdminData::hours()['hours'];
$odd = array_filter(array_keys($load), static fn($b): bool =>
    !ctype_digit((string)$b) || strlen((string)$b) !== 10);
ok($odd === [], 'the load graph sees only real hour buckets, never a total or a minute');

// ---- The store's claims ----------------------------------------------
// A host may run one tournament at a time, and a join code is unique among
// the OPEN ones. Both are apcu_add(), which is the whole reason a create
// needs no transaction any more.
Settings::set('tournament_create_cooldown', 0);
$c1 = Tournament::create('77000001', false);
ok($c1['ok'] === true, 'a host with nothing running can create');
ok(Tournament::create('77000001', false)['http'] === 409, 'but not a second one at the same time');
ok(TourneyStore::byCode($c1['code'])['tid'] === $c1['tid'], 'the join code finds the lobby');
Tournament::leave('77000001', $c1['tid']);
ok(TourneyStore::byCode($c1['code']) === null, 'and is released the moment it stops being open');
ok(Tournament::create('77000001', false)['ok'] === true, 'as is the host, who can create again');
// The TTL is the gap a tournament may go untouched, not a lifetime: every
// transition re-stores it. The three states do not share one clock, because a
// lobby, a bracket in play and a podium are worth keeping for different
// lengths of time.
$c2 = Tournament::create('77000002', false);
$k2 = 'fok:t:' . $c2['tid'];
ok(apcu_key_info($k2)['ttl'] === Settings::int('tournament_join_ttl'),
    'an open lobby is held for the join TTL');
Tournament::leave('77000002', $c2['tid']);
ok(apcu_key_info($k2)['ttl'] === Settings::int('tournament_done_ttl'),
    'and a tournament nobody can still play only as long as its result is worth reading');
Settings::set('tournament_create_cooldown', 10);

// The long-poll worker budget (Holds). Slots stand in for the OTHER workers
// of the pool, which a single test process has no other way to have.
Settings::set('hold_max_workers', 3);
apcu_delete(new APCUIterator('/^fok:hold:/'));
ok(Holds::inUse() === 0, 'no long poll is holding a worker to start with');
for ($i = 0; $i < 3; $i++) {
    apcu_add("fok:hold:$i", 999, 20);
}
ok(Holds::inUse() === 3, 'a full budget reads as full');
ok(Holds::claim() === false, 'a hold over the budget is refused, not queued');
// A refusal is not a rejection: poll.php drops the WAIT and answers, so the
// caller still gets its mailbox. That is the endpoint's half; here it is
// enough that the budget says no.
apcu_delete('fok:hold:1');
ok(Holds::claim() === true, 'and is admitted again the moment a slot frees');
ok(Holds::inUse() === 3, 'taking the freed slot rather than a fourth');
Holds::release();
ok(Holds::inUse() === 2, 'releasing hands the slot straight back');
// The TTL is a safety net for a worker that dies mid-hold, so a slot can
// expire under a hold that is still running and be handed to somebody else.
// The late release must not then evict the new owner.
ok(Holds::claim() === true, 'a fresh hold takes the free slot');
apcu_store('fok:hold:1', 4242, 20);
Holds::release();
ok(apcu_fetch('fok:hold:1') === 4242,
    'a release cannot take back a slot that expired and was handed on');
apcu_delete(new APCUIterator('/^fok:hold:/'));
Settings::set('hold_max_workers', 0);
ok(Holds::claim() === true && Holds::inUse() === 0,
    'and the budget switched off holds nothing at all');
Settings::set('hold_max_workers', FOK_HOLD_MAX_WORKERS);

// Client pacing (Pace). The same budget from the other side: Holds is what
// happens at the wall, this is what the server says before it.
Settings::set('hold_max_workers', 4);
apcu_delete(new APCUIterator('/^fok:hold:/'));
$p = Pace::forTier(Pace::TIER_LOBBY);
ok($p['hold'] === true, 'an idle pool lets even a lobby hold a long poll');
ok($p['hello_ms'] === Settings::int('pace_hello_ms'),
    'and asks for no more than the ordinary heartbeat');
ok($p['poll_ms'] === FOK_POLL_WAIT_MAX * 1000, 'the poll wait is the one the server can serve');
ok(!array_key_exists('spread_ms', $p), 'and the retired jitter budget is gone from the block');
ok($p['gap_ms'] === Settings::int('pace_gap_ms'),
    'and the plain gap between the client\'s own background requests');
// Half the budget spent: the client that is only browsing gives way first.
apcu_add('fok:hold:0', 1, 20);
apcu_add('fok:hold:1', 1, 20);
ok(Pace::forTier(Pace::TIER_LOBBY)['hold'] === false,
    'a half-spent budget withdraws the lobby hold');
ok(Pace::forTier(Pace::TIER_LOBBY)['hello_ms'] > Settings::int('pace_hello_ms'),
    'and stretches its heartbeat');
ok(Pace::forTier(Pace::TIER_TOURNEY)['hold'] === true,
    'a tournament screen keeps its hold that long');
ok(Pace::forTier(Pace::TIER_DUEL)['gap_ms'] === Settings::int('pace_gap_ms') * 2,
    'pressure widens the gap for every tier - a duel spaces its background too');
ok(Pace::forTier(Pace::TIER_DUEL)['hold'] === true, 'and so does a duel');
// Three quarters: only the duel is still worth a held worker.
apcu_add('fok:hold:2', 1, 20);
ok(Pace::forTier(Pace::TIER_TOURNEY)['hold'] === false,
    'three quarters spent takes the tournament hold too');
ok(Pace::forTier(Pace::TIER_DUEL)['hold'] === true,
    'the duel handshake is the last thing to give way');
ok(Pace::forTier(Pace::TIER_DUEL)['hello_ms'] === Settings::int('pace_hello_ms'),
    'and a duel heartbeat is never stretched - it is how the server knows the game runs');
// The ceiling is real: pacing may not stretch a client past being counted.
Settings::set('pace_hello_max_ms', 31000);
ok(Pace::forTier(Pace::TIER_LOBBY)['hello_ms'] === 31000,
    'the heartbeat is clamped to the ceiling');
Settings::set('pace_hello_ms', 0);
ok(Pace::forTier(Pace::TIER_LOBBY)['hello_ms'] >= 5000,
    'and a zeroed setting cannot turn into a flood');
// And the gap has a ceiling of its own: pressure may space a client's
// background work, never stall it.
Settings::set('pace_gap_ms', 1500);
ok(Pace::forTier(Pace::TIER_LOBBY)['gap_ms'] === 2000, 'the widened gap is clamped');
Settings::set('pace_gap_ms', 0);
ok(Pace::forTier(Pace::TIER_LOBBY)['gap_ms'] === 0, 'and zeroing the setting turns it off');
Settings::set('pace_gap_ms', FOK_PACE_GAP_MS);
Settings::set('pace_hello_ms', FOK_PACE_HELLO_MS);
Settings::set('pace_hello_max_ms', FOK_PACE_HELLO_MAX_MS);
apcu_delete(new APCUIterator('/^fok:hold:/'));
Settings::set('hold_max_workers', FOK_HOLD_MAX_WORKERS);

// The pair clock cross-check (Skew). One caller's figure means nothing; the
// DIFFERENCE between the pair's two is the only clock error the server can
// see - and it is a hint, never a refusal (start.php issues the start either
// way; that half is in the smoke suite).
apcu_delete(new APCUIterator('/^fok:skew:/'));
ok(Skew::note('sk110001', 'sk220002', 0, 40) === false,
    'the first caller of a start has nothing to be compared against');
ok(Skew::note('sk110001', 'sk220002', 0, 900) === false,
    'a retry from the same client is not compared against itself');
ok(Skew::note('sk220002', 'sk110001', 0, 55) === false,
    'two anchors a few ms apart agree');
ok(Skew::wanted('sk110001') === false, 'so nobody is asked to re-anchor');
// The peers reversed: the pair key must not depend on who asked first.
apcu_delete(new APCUIterator('/^fok:skew:/'));
ok(Skew::note('sk220002', 'sk110001', 1, 30) === false, 'the other side may open the epoch');
ok(Skew::note('sk110001', 'sk220002', 1, 900) === true,
    'and a pair whose proofs disagree grossly is told to re-anchor');
ok(Skew::wanted('sk220002') === true,
    'the caller already answered picks the verdict up on its next start');
ok(Skew::wanted('sk220002') === false, 'delivered once, then it stops nagging');
// The epoch scopes it: the next halt is its own comparison.
ok(Skew::note('sk110001', 'sk220002', 2, 900) === false,
    'a new epoch starts the comparison over');
Settings::set('start_pair_skew_ms', 0);
apcu_delete(new APCUIterator('/^fok:skew:/'));
ok(Skew::note('sk110001', 'sk220002', 3, 10) === false
    && Skew::note('sk220002', 'sk110001', 3, 9000) === false,
    'and the tolerance switched off compares nothing at all');
Settings::set('start_pair_skew_ms', FOK_START_PAIR_SKEW_MS);
apcu_delete(new APCUIterator('/^fok:skew:/'));

// Housekeeping: one removal path, and only rows no reader can reach go.
Presence::touch('hk110001', '9.9.9.11');
Presence::touch('hk220002', '9.9.9.12');
Friends::request('hk110001', 'hk220002');
Friends::accept('hk220002', 'hk110001');
Vault::backup('hk110001', '{"cfg":1}', null);
PStats::submit('hk110001', ['games' => 3]);
Items::mint('hk110001', 'crown', 'box');
Presence::forget('hk110001');
ok(Presence::infoOf(['hk110001']) === [], 'forget removes the player row');
ok(!Friends::isFriend('hk110001', 'hk220002'), 'and the friendships with it');
$st = Db::get()->prepare('SELECT COUNT(*) FROM player_nets WHERE id = ?');
$st->execute(['hk110001']);
ok((int)$st->fetchColumn() === 0, 'and the networks it was seen on');
$st->closeCursor();
ok(Vault::peek('hk110001') !== null, 'but the config backup outlives the player row');
ok(PStats::get('hk110001')['games'] === 3, 'and so do the career stats');
ok(count(Items::owned('hk110001')) === 1, 'and the wardrobe: an id comes back with its client');

Presence::touchDuel('hk220002', 'hk330003');
Db::get()->exec("INSERT INTO duels (a, b, started, last_seen) VALUES ('hk440004', 'hk550005', 0, 0)");
Db::get()->exec("INSERT INTO alerts (type, message, created, seen) VALUES ('hk', 'read', 0, 1)");
Db::get()->exec("INSERT INTO alerts (type, message, created, seen) VALUES ('hk', 'unread', 0, 0)");
Db::get()->exec("INSERT INTO settings (key, value) VALUES ('retired_last_release', 7)");
// The two the duel paths hand over rather than delete under their own lock
// (see Starts::prune and Items::pruneMatches): a start no epoch guard can
// reach, and a match no claim could name. The live pair above keeps its own.
Db::get()->exec("INSERT INTO starts (a, b, start_pts, created, epoch, reason, mid)
                 VALUES ('hk440004', 'hk550005', 0, 0, 0, 'first', '')");
$hkStale = Items::openMatch(Db::get(), 'hk660006', 'hk770007', 1);
$hkLive = Items::openMatch(Db::get(), 'hk220002', 'hk330003', 1);
$swept = Housekeeping::sweep();
ok(($swept['duels'] ?? 0) === 1, 'the sweep forgets a duel pair past the TTL');
$st = Db::get()->prepare('SELECT COUNT(*) FROM duels WHERE a = ?');
$st->execute(['hk220002']);
ok((int)$st->fetchColumn() === 1, 'and leaves the pair that played just now');
$st->closeCursor();
ok(($swept['alerts'] ?? 0) === 1, 'it removes an alert that was read long ago');
$st = Db::get()->prepare('SELECT COUNT(*) FROM alerts WHERE type = ?');
$st->execute(['hk']);
ok((int)$st->fetchColumn() === 1, 'but never one nobody has read');
$st->closeCursor();
ok(($swept['settings'] ?? 0) === 1, 'and a settings row whose key no release knows');
$st = Db::get()->prepare('SELECT COUNT(*) FROM settings WHERE key = ?');
$st->execute(['player_ttl_days']);
ok((int)$st->fetchColumn() === 1, 'while the keys a release does know stay');
$st->closeCursor();
$st = Db::get()->prepare('SELECT COUNT(*) FROM starts WHERE a = ?');
$st->execute(['hk440004']);
ok((int)$st->fetchColumn() === 0, 'it drops a start past its keep window');
$st->closeCursor();
$st = Db::get()->prepare('SELECT COUNT(*) FROM matches WHERE mid = ?');
$st->execute([$hkStale['mid']]);
ok((int)$st->fetchColumn() === 0, 'and a match no claim could name any more');
$st->closeCursor();
$st = Db::get()->prepare('SELECT COUNT(*) FROM matches WHERE mid = ?');
$st->execute([$hkLive['mid']]);
ok((int)$st->fetchColumn() === 1, 'while a match whose duel still reports in stays claimable');
$st->closeCursor();
ok(($swept['starts'] ?? 0) >= 1 && ($swept['matches'] ?? 0) >= 1,
    'and reports both among the rows it removed');

$rep = [];
$hk = Housekeeping::report();
foreach ($hk['tables'] as $t) {
    $rep[$t['name']] = $t;
}
ok($rep['items']['loose'] >= 1 && $rep['items']['policy'] === 'kept',
    'the report calls the wardrobe of a departed player kept, not stale');
ok($rep['player_nets']['loose'] === 0 && $rep['friends']['loose'] === 0,
    'and finds no orphans, because there is only one removal path');
ok(isset($rep['starts']) && isset($rep['matches']),
    'the card accounts for every table the sweep touches');
ok($hk['db_size'] > 0, 'alongside the size of the file it is all in');

// Counters: the worst-case list the queue gauge shows under its graphs. It
// keeps the worst of a window rather than the last of it, and ignores
// anything too small to diagnose - without that floor an idle server would
// rewrite the whole list on nearly every request.
apcu_delete(new APCUIterator('/^' . preg_quote(FOK_APCU_NS . 'ct:worst:', '/') . '/'));
Counters::worst('t_us', 500, ['s' => 'poll.php']);
ok(Counters::worstList('t_us') === [], 'a wait under a millisecond is not filed');
for ($i = 1; $i <= 12; $i++) {
    Counters::worst('t_us', 1000 * $i, ['s' => "s$i.php"]);
}
$worst = Counters::worstList('t_us');
ok(count($worst) === 10, 'the list is bounded');
ok($worst[0]['v'] === 12000 && $worst[0]['s'] === 's12.php',
    'worst first, carrying what caused it');
ok($worst[9]['v'] === 3000, 'and the two SMALLEST fell out, not the two oldest');
// The whole buffer is database-derived, so it must not be one segment shared
// with the other environment on a pool that serves both docroots.
ok(apcu_exists(FOK_APCU_NS . 'ct:worst:t_us'),
    'the counter buffer is namespaced by environment');
Counters::worst('t_us', 2000, ['s' => 'late.php']);
ok(Counters::worstList('t_us')[9]['v'] === 3000,
    'a reading that beats nothing in a full list is dropped');
// The Clear statistics button empties this too: it is traffic history like
// the rest, and leaving it behind would leave rows pointing at players the
// cleared graphs no longer show (Counters::clearHistory).
Counters::clearHistory();
ok(Counters::worstList('t_us') === [], 'clearing the statistics clears it as well');

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
