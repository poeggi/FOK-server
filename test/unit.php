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
require_once __DIR__ . '/../public/src/FriendFeed.php';
require_once __DIR__ . '/../public/src/RelayRate.php';
require_once __DIR__ . '/../public/src/ConnTrack.php';
require_once __DIR__ . '/../public/src/Caps.php';
require_once __DIR__ . '/../public/src/Holds.php';
require_once __DIR__ . '/../public/src/Pace.php';
require_once __DIR__ . '/../public/src/RelayStore.php';
require_once __DIR__ . '/../public/src/Relay.php';
require_once __DIR__ . '/../public/src/Load.php';
require_once __DIR__ . '/../public/src/Vault.php';
require_once __DIR__ . '/../public/src/Debug.php';
require_once __DIR__ . '/../public/src/Ledger.php';
require_once __DIR__ . '/../public/src/Items.php';
require_once __DIR__ . '/../public/src/Bracket.php';
require_once __DIR__ . '/../public/src/Tournament.php';
require_once __DIR__ . '/../public/src/TourneyStore.php';
require_once __DIR__ . '/../public/src/Stats.php';
require_once __DIR__ . '/../public/src/EventView.php';
require_once __DIR__ . '/../public/src/Qr.php';
require_once __DIR__ . '/../public/src/Events.php';
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

// The window as the server checks it: FOK_ONLINE_WINDOW plus the jitter
// second, so a beat that lands one second late still counts and nothing
// reads as gone before 121 s.
Presence::age('aaaaaaaa', FOK_ONLINE_WINDOW + FOK_BEAT_JITTER);
ok(Presence::infoOf(['aaaaaaaa'])['aaaaaaaa']['online'] === true, 'a beat one second late still reads as online');
Presence::age('aaaaaaaa', FOK_ONLINE_WINDOW + FOK_BEAT_JITTER + 1);
ok(Presence::infoOf(['aaaaaaaa'])['aaaaaaaa']['online'] === false, 'one second past the grace it reads as offline');
Presence::touch('aaaaaaaa', '1.2.3.9');

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

// Sessions: the row is written when a beat finds no live entry and when the
// fold finds the entry stale - never per beat. hello_count counts sessions.
$rowOf = static function (string $id): array {
    $st = Db::get()->prepare('SELECT hello_count, last_seen, name, latency FROM players WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    $st->closeCursor();
    return $r === false ? [] : $r;
};
Presence::touch('5e550001', '1.2.3.4', 30, 'SESSA');
Presence::touch('5e550001', '1.2.3.4', 35, 'SESSB');
Presence::touch('5e550001', '1.2.3.4');
ok((int)$rowOf('5e550001')['hello_count'] === 1 && $rowOf('5e550001')['name'] === 'SESSB',
    'repeat beats write no row, and a rename is written through');
ok(Presence::entryOf('5e550001')['lat'] === 35 && Presence::entryOf('5e550001')['name'] === 'SESSB',
    'while the entry carries the latest latency and name');
Presence::age('5e550001', FOK_ONLINE_WINDOW + FOK_BEAT_JITTER + 1);
Presence::touch('5e550001', '1.2.3.5');
ok((int)$rowOf('5e550001')['hello_count'] === 2, 'a beat past the window opens a new session, which is the row write');
ok($rowOf('5e550001')['name'] === 'SESSB' && Presence::entryOf('5e550001')['name'] === 'SESSB',
    'and starts from the name the row kept');
$far = max(FOK_ONLINE_WINDOW, Settings::int('tournament_announce_window')) + FOK_BEAT_JITTER + 1;
Presence::touch('5e550001', '1.2.3.5', 44);
Presence::age('5e550001', $far);
Db::get()->prepare('UPDATE players SET last_seen = ? WHERE id = ?')->execute([time() - $far - 5, '5e550001']);
Presence::foldNow();
ok(Presence::entryOf('5e550001') === null, 'the fold drops an entry older than every window that reads it');
$r = $rowOf('5e550001');
ok(abs((int)$r['last_seen'] - (time() - $far)) <= 1 && (int)$r['latency'] === 44,
    'and writes its last beat and latency to the row');
Presence::touch('5e550002', '1.2.3.6');
Presence::age('5e550002', $far);
Presence::fold();
ok(Presence::entryOf('5e550002') !== null, 'the fold runs at most once per interval');
Presence::foldNow();
ok(Presence::entryOf('5e550002') === null, 'and folds once the interval has passed');
Presence::touch('5e550003', '1.2.3.7');
Presence::foldNow();
ok(Presence::entryOf('5e550003') !== null, 'a live entry is never folded');
Presence::forget('5e550001');
Presence::forget('5e550002');
Presence::forget('5e550003');

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
// depends on when either of them asks. The lead is one flat figure: a
// reported latency, however wild, does not move it.
Db::get()->prepare('UPDATE players SET latency = 9000 WHERE id = ?')->execute(['aaaaaaaa']);
$t0 = Util::nowMs();
$s1 = Starts::request('aaaaaaaa', 'bbbbbbbb', 0, 'first');
$s2 = Starts::request('bbbbbbbb', 'aaaaaaaa', 0, 'first');
ok($s1 === $s2, 'both peers receive the identical start pts');
ok($s1 >= $t0 + 1000 && $s1 <= Util::nowMs() + 1000, 'the lead is a flat 1000 ms');
Db::get()->prepare('UPDATE players SET latency = 40 WHERE id = ?')->execute(['aaaaaaaa']);

// The race a pair-only key lost: a peer whose request lands after the
// moment it is asking about must still be told THAT moment. Handing it a
// fresh one instead put the two players on different origins silently.
$passed = Util::nowMs() - 1000;
Db::get()->prepare('UPDATE starts SET start_pts = ? WHERE a = ? AND b = ?')
    ->execute([$passed, 'aaaaaaaa', 'bbbbbbbb']);
$late = Starts::request('bbbbbbbb', 'aaaaaaaa', 0, 'first');
ok($late === $passed, 'a late peer gets the same start, already in the past');

// Only play BEGINNING is asked about now; the halts within a run are settled
// peer-to-peer and the server does not know the words for them.
ok(Starts::REASONS === ['first', 'rematch'], 'a start begins play, or it is not a start');

// A rematch names epoch 0 exactly as the first start did, so the REASON is
// the only thing on the wire saying "a new game, not the one you issued us".
// A relay rematch reuses the hub with no new offer, so nothing clears the
// line for it (see signal.php) - without this it would read back the moment
// the pair already played to.
$again = Starts::request('aaaaaaaa', 'bbbbbbbb', 0, 'rematch');
ok(is_int($again) && $again !== $passed, 'a rematch at the same epoch is a moment of its own');
ok(Starts::request('bbbbbbbb', 'aaaaaaaa', 0, 'rematch') === $again, 'and the peer joins that one');

$startRow = (function (): array {
    $st = Db::get()->prepare('SELECT epoch, reason FROM starts WHERE a = ? AND b = ?');
    $st->execute(['aaaaaaaa', 'bbbbbbbb']);
    return $st->fetch();
})();
ok((int)$startRow['epoch'] === 0 && $startRow['reason'] === 'rematch',
    'the pair records the epoch and reason its start was named with');

// The window is the third guard, for the case the other two cannot see: a
// rematch after a rematch, identical in both fields. Age the row past it and
// the pair gets a new moment rather than the one that has already passed.
Db::get()->prepare('UPDATE starts SET start_pts = ? WHERE a = ? AND b = ?')
    ->execute([Util::nowMs() - 60000, 'aaaaaaaa', 'bbbbbbbb']);
$fresh = Starts::request('aaaaaaaa', 'bbbbbbbb', 0, 'rematch');
ok($fresh > Util::nowMs(), 'a start older than the pairing window is not this start');

// The epoch counts halts within ONE connection, so the pair's next duel
// opens at epoch 0 again instead of being refused forever. The reset hangs
// off the handshake, NOT off bye: a P2P bye goes over the DataChannel and
// the server never sees it (see signal.php), so a rematch would otherwise
// hit the finished line and 409 until the row aged out.
Starts::forget('aaaaaaaa', 'bbbbbbbb');
$again = Starts::request('aaaaaaaa', 'bbbbbbbb', 0, 'first');
ok($again > Util::nowMs(), 'a rematch on a fresh epoch line gets a start');
// Pair-scoped: bye is not friendship-gated, so a stranger saying bye must
// not reach a duel it has nothing to do with. The pair's peer still joining
// the SAME moment is what says the row survived.
Starts::forget('aaaaaaaa', 'cccccccc');
ok(Starts::request('bbbbbbbb', 'aaaaaaaa', 0, 'first') === $again,
    "a stranger's bye leaves the pair's start alone");

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
    $st = Db::get()->prepare('SELECT debug FROM players WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    $st->closeCursor();
    // The wish is the row's; the client's own report lives in the entry.
    $row['debug_active'] = (int)(Presence::entryOf($id)['dbg'] ?? false);
    return $row;
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
Signals::sendAged('aaaaaaaa', 'bbbbbbbb', 'invite', 'old', FOK_SIGNAL_TTL + FOK_BEAT_JITTER);
ok(count(Signals::take('bbbbbbbb')) === 1, 'a signal as old as the window and its grace is still delivered');
Signals::sendAged('aaaaaaaa', 'bbbbbbbb', 'invite', 'old', FOK_SIGNAL_TTL + FOK_BEAT_JITTER + 1);
ok(Signals::take('bbbbbbbb') === [], 'expired signal not delivered');
Signals::sweepNow();
$receipt = Signals::take('aaaaaaaa');
ok(count($receipt) === 1, 'sender gets a receipt for the expired invite');
ok($receipt[0]['type'] === 'undelivered', 'receipt is an undelivered signal');
ok($receipt[0]['from'] === 'bbbbbbbb', 'receipt names the peer that never picked it up');
ok(str_contains($receipt[0]['payload'], '"type":"invite"'), 'receipt names the lost message type');

// Signals: the receipt must survive a FULL mailbox - a flood must not be
// able to swallow the one message that says the connection failed.
Signals::sendAged('aaaaaaaa', 'bbbbbbbb', 'invite', 'old', FOK_SIGNAL_TTL + FOK_BEAT_JITTER + 1);
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
Signals::sendAged('aaaaaaaa', 'bbbbbbbb', 'ice', 'old', FOK_SIGNAL_TTL + FOK_BEAT_JITTER + 1);
ok(Signals::take('bbbbbbbb') === [], 'expired ice not delivered');
Signals::sweepNow();
ok(Signals::take('aaaaaaaa') === [], 'no receipt for an expired ice candidate');

// Signals: an expired message must not wake a long poll (any() and take()
// have to agree on the TTL, or poll.php answers 200 with an empty list)
Signals::sendAged('aaaaaaaa', 'bbbbbbbb', 'ice', 'old', FOK_SIGNAL_TTL + FOK_BEAT_JITTER + 1);
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
connPoke('aaaaaaaa', ['updated' => time() - FOK_CONN_TTL - FOK_BEAT_JITTER]);
ok(duelOf('aaaaaaaa')['state'] !== 'ended', 'a heartbeat one second late is not a quiet duel');
connPoke('aaaaaaaa', ['updated' => time() - FOK_CONN_TTL - FOK_BEAT_JITTER - 1]);
ok(duelOf('aaaaaaaa')['state'] === 'ended', 'a quiet duel reads as ended');
connPoke('aaaaaaaa', ['updated' => time() - FOK_CONN_TTL - FOK_BEAT_JITTER - FOK_DUEL_LINGER - 1]);
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
Settings::set('ices_max', 99);
ok(Settings::int('ices_max') === 99, 'setting override readable');
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
Settings::int('ices_max');
ok(is_array(apcu_fetch(FOK_APCU_NS . 'cfg')), 'a settings read caches the overrides');
Settings::set('ices_max', 77);
ok(apcu_fetch(FOK_APCU_NS . 'cfg') === false, 'and a save drops that cache');
ok(Settings::int('ices_max') === 77, 'so the next read answers with the saved value');

// A row IS the override, so saving the default removes it: an install that
// wrote every key once (a config export/import roundtrip does) would
// otherwise answer today's default forever, whatever the code later says.
Settings::set('ices_max', FOK_ICES_MAX);
$st = Db::get()->prepare('SELECT COUNT(*) FROM settings WHERE key = ?');
$st->execute(['ices_max']);
$rows = (int)$st->fetchColumn();
$st->closeCursor();
ok($rows === 0, 'saving the default keeps no row');
ok(Settings::int('ices_max') === FOK_ICES_MAX, 'and the default is what reads back');

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
// And that is a stale wardrobe - a restored config backup, or an item lost in
// a duel this client has not synced since - so it is logged rather than
// raised. Nothing moved, and an unseen alert count has to keep meaning that
// somebody has to look.
$st = Db::get()->query("SELECT COUNT(*) FROM alerts WHERE type = 'item_counterfeit'");
$raised = (int)$st->fetchColumn();
$st->closeCursor();
ok($raised === 0, 'and a claim the registry has moved past alerts nobody');

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

// "Open" is DERIVED from the duel heartbeat, never from a stored flag. The
// server does not reliably learn that a match ended - a bye goes peer to
// peer - so a flag would only ever mark the endings that happen to pass
// through signaling, and read high for every one that does not.
$openBefore = Items::openMatches($idb);
$oa = 'ab00ab00';
$ob = 'cd00cd00';
Items::openMatch($idb, $oa, $ob, Util::nowMs());
ok(Items::openMatches($idb) === $openBefore,
    'a match whose pair is not beating is not open, however it ended');
Presence::touchDuel($oa, $ob);
ok(Items::openMatches($idb) === $openBefore + 1,
    'the duel heartbeat is what makes it open');
Db::get()->prepare('UPDATE duels SET last_seen = ? WHERE a = ? AND b = ?')
    ->execute([time() - FOK_DUEL_WINDOW - 60, min($oa, $ob), max($oa, $ob)]);
ok(Items::openMatches($idb) === $openBefore,
    'and a duel that stopped beating closes it with no write at all');
$st = $idb->prepare('SELECT COUNT(*) FROM matches WHERE mid = ?');
$st->execute([$m3['mid']]);
ok((int)$st->fetchColumn() === 1, 'and the prune spares a match whose duel is alive');
$st->closeCursor();

// A freeze is a verdict, and the registry has to be able to say which one and
// when: an operator reviewing an instance that is out of play reads the
// finding, not the flag.
$m4 = Items::openMatch($idb, 'aa11aa11', 'bb22bb22', Util::nowMs());
$u4 = Items::mint('aa11aa11', 'halo', 'box')['uid'];
$res = Items::claim('bb22bb22', $m4['mid'], $u4, 'aa11aa11', 'bb22bb22', 20, 0, 'ws4',
    Ledger::mac($m4['sec_b'], $m4['mid'], 20, 'ws4'), '0123456789abcdef');
ok(!$res['ok'] && $res['error'] === 'tag invalid', 'a forged peer attestation is tampering');
$st = $idb->prepare('SELECT frozen, frozen_at, frozen_why FROM items WHERE uid = ?');
$st->execute([$u4]);
$froze = $st->fetch();
$st->closeCursor();
ok((int)$froze['frozen'] === 1 && $froze['frozen_why'] === 'tag_invalid'
    && (int)$froze['frozen_at'] > 0,
    'and the freeze records the verdict that reached it and the moment it did');
$was = (int)$froze['frozen_at'] - 3600;
$idb->prepare('UPDATE items SET frozen_at = ? WHERE uid = ?')->execute([$was, $u4]);
Items::claim('bb22bb22', $m4['mid'], $u4, 'aa11aa11', 'bb22bb22', 21, 0, 'ws5',
    Ledger::mac($m4['sec_b'], $m4['mid'], 21, 'ws5'), 'fedcba9876543210');
$st->execute([$u4]);
$again = $st->fetch();
$st->closeCursor();
ok((int)$again['frozen_at'] === $was,
    'a later claim on an instance already out of play cannot rewrite that finding');

// Deciding is what ends a freeze, and it is the one thing that moves an
// instance without a claim. It is also once: what comes out is an ordinary
// instance again, which the same call will not touch a second time.
$st = $idb->prepare('SELECT owner, seq FROM items WHERE uid = ?');
$st->execute([$u4]);
$before = $st->fetch();
$st->closeCursor();
ok(Items::resolve($u4, 'ee55ee55'), 'an operator hands a frozen instance to a player');
$st->execute([$u4]);
$after = $st->fetch();
$st->closeCursor();
ok($after['owner'] === 'ee55ee55', 'which is who holds it afterwards');
ok((int)$after['seq'] === (int)$before['seq'] + 1,
    'and the seq moves with it, so a claim built on the frozen one is stale');
$st = $idb->prepare('SELECT frozen, frozen_at, frozen_why FROM items WHERE uid = ?');
$st->execute([$u4]);
$thawed = $st->fetch();
$st->closeCursor();
ok((int)$thawed['frozen'] === 0 && (int)$thawed['frozen_at'] === 0 && $thawed['frozen_why'] === '',
    'the verdict is cleared along with the freeze it explained');
ok(!Items::resolve($u4, 'aa11aa11'), 'an instance back in play cannot be resolved again');

// A verdict is an EVENT, and the finding log is what survives the instance it
// was found on: the resolve above cleared frozen_why, and dropping an instance
// removes the row outright, so neither can be the record of what happened.
$found = Items::disputesOf('bb22bb22');
ok(count($found) === 2, 'each verdict is recorded against the player it was reached on');
ok($found[0]['why'] === 'tag_invalid' && $found[0]['uid'] === $u4
    && $found[0]['mid'] === $m4['mid'] && $found[0]['tick'] === 21,
    'naming the verdict, the instance and the claim it was reached on');
ok($found[0]['state'] === 'released',
    'and it outlives the resolve, which is what the instance can no longer say');
ok(Items::disputesOf('aa11aa11') === [],
    'the player who did not make the claim has none');

// Reviewing is an operator saying "I have read this", never a verdict undone:
// it takes the player off the queue and moves no item and no tally.
$queued = static fn(string $id): bool => in_array(
    $id, array_column(AdminData::items()['disputed'], 'id'), true
);
ok($queued('bb22bb22'), 'an unreviewed finding puts the player on the review queue');
$tallyBefore = $tally('bb22bb22');
ok(Items::reviewDisputes('bb22bb22'), 'the operator marks them reviewed');
ok(!$queued('bb22bb22'), 'which takes the player off the queue');
ok($tally('bb22bb22') == $tallyBefore,
    'while the tally itself never moves - it is the forensic record');
ok(Items::disputesOf('bb22bb22')[0]['seen'] === true, 'and every finding is marked read');
ok(!Items::reviewDisputes('bb22bb22'), 'a second review has nothing left to do');

$d = AdminData::disputes('bb22bb22');
ok($d !== null && $d['disputed'] === 2 && $d['reviewed'] === 2 && $d['logged'] === 2,
    'the popup reads the tallies and the findings that account for them');
ok(AdminData::disputes('ff00ff00') === null, 'and an unknown player has no popup at all');

// A finding raised before the log existed leaves only its count, and the gap
// is reported rather than shown as an empty list that reads like a bug.
Db::get()->prepare('UPDATE players SET claims_disputed = claims_disputed + 1 WHERE id = ?')
    ->execute(['bb22bb22']);
$d = AdminData::disputes('bb22bb22');
ok($d['disputed'] === 3 && $d['logged'] === 2,
    'a dispute with no finding behind it is visible as the difference');
ok($queued('bb22bb22'), 'and it puts the player back on the queue');
ok(Items::reviewDisputes('bb22bb22') && !$queued('bb22bb22'),
    'which the review clears too, having nothing else to go on');
$st = $idb->prepare("SELECT from_id, to_id FROM ledger WHERE uid = ? AND kind = 'resolve'");
$st->execute([$u4]);
$verdicts = $st->fetchAll();
$st->closeCursor();
ok(count($verdicts) === 1 && $verdicts[0]['from_id'] === $before['owner']
    && $verdicts[0]['to_id'] === 'ee55ee55',
    'and the ledger records who it was taken from and who it went to');

$m5 = Items::openMatch($idb, 'aa11aa11', 'bb22bb22', Util::nowMs());
$u5 = Items::mint('aa11aa11', 'visor', 'box')['uid'];
Items::claim('bb22bb22', $m5['mid'], $u5, 'aa11aa11', 'bb22bb22', 30, 0, 'ws6',
    Ledger::mac($m5['sec_b'], $m5['mid'], 30, 'ws6'), '0123456789abcdef');
ok(Items::resolve($u5, ''), 'the other answer drops the instance from the registry');
$st = $idb->prepare('SELECT COUNT(*) FROM items WHERE uid = ?');
$st->execute([$u5]);
$left = (int)$st->fetchColumn();
$st->closeCursor();
ok($left === 0, 'so nobody holds it any more');
ok(Ledger::verify($idb)['ok'], 'and the chain still verifies over both verdicts');

// Every admin table is keyed on ids and read about people, so the payloads
// answer the names those ids resolve to. A missing name is a real answer.
$idb->prepare('UPDATE players SET name = ? WHERE id = ?')->execute(['srv-CI-alice', 'aa11aa11']);
$named = AdminData::namesFor(['aa11aa11', 'nothexid', 'ff99ff99']);
ok(($named['aa11aa11'] ?? null) === 'srv-CI-alice', 'a set of ids answers the names behind them');
ok(!array_key_exists('nothexid', $named), 'anything that is not an id is never asked about');
ok(!array_key_exists('ff99ff99', $named), 'and an id with no player row simply has no name');
$view = (array)AdminData::item($u4)['names'];
ok(($view['aa11aa11'] ?? null) === 'srv-CI-alice',
    'so an instance names everyone its ledger still mentions');

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

// The undo point: what a restore replaces is snapshotted first, and the
// snapshot cannot land on the file being restored from even in the same
// second.
$before = count(Backup::list());
$snapshot = Backup::restore(FOK_BACKUP_DIR . '/' . $name);
ok(Backup::isValidName($snapshot), 'the snapshot has a valid backup name');
ok(is_file(FOK_BACKUP_DIR . '/' . $snapshot), 'restore snapshots the database it replaces');
ok($snapshot !== $name, 'the snapshot never overwrites the file being restored');
ok(count(Backup::list()) === $before + 1, 'the snapshot is listed with the backups');

// Refused before anything moves: the ladder only goes forward, and a
// stranger's database is not this server's.
$newer = $tmp . '/newer.db';
$sq = new SQLite3($newer);
$sq->exec('CREATE TABLE players (id TEXT PRIMARY KEY)');
$sq->exec('PRAGMA user_version = ' . (Db::schemaVersion() + 1));
$sq->close();
$threw = false;
try {
    Backup::restore($newer);
} catch (RuntimeException $e) {
    $threw = true;
}
ok($threw, 'restore refuses a database from a newer schema');
$foreign = $tmp . '/foreign.db';
$sq = new SQLite3($foreign);
$sq->exec('CREATE TABLE other (x INTEGER)');
$sq->close();
$threw = false;
try {
    Backup::restore($foreign);
} catch (RuntimeException $e) {
    $threw = true;
}
ok($threw, 'restore refuses a SQLite file that is not a FOK database');
ok(count(Backup::list()) === $before + 1, 'a refused restore takes no snapshot');
ok(count(Scores::top()) === 5, 'a refused restore leaves the live data standing');

// Shared memory and the request's own tail both describe the replaced
// database, so neither may reach the restored one.
Presence::touch('deadbeef', '198.51.100.7', null, 'srv-CI-restore');
$tail = false;
Util::defer(static function () use (&$tail): void {
    $tail = true;
});
Backup::restore(FOK_BACKUP_DIR . '/' . $name);
ok(Presence::entryOf('deadbeef') === null, 'a restore empties the presence store');
Util::runDeferred();
ok($tail === false, 'the tail queued before a restore never runs');

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

// The round ladder: a round is played one level deeper than the one before
// it, so the size of the lobby is what decides how deep the final gets - and
// it stops at the last level the game actually has.
ok(Bracket::level(1, 10) === 1 && Bracket::level(2, 10) === 2 && Bracket::level(4, 10) === 4,
    'each round is played one level deeper than the one before it');
ok(Bracket::level(0, 10) === 1, 'and nothing is ever played below level 1');
ok(Bracket::level(12, 10) === 10, 'the ladder stops at the last level the game has');
// The start level the host picked is where that ladder begins.
ok(Bracket::level(1, 10, 5) === 5 && Bracket::level(3, 10, 5) === 7,
    'the ladder climbs from the level the host chose');
ok(Bracket::level(2, 10, 0) === 2, 'a start below level 1 is read as 1');
ok(Bracket::level(4, 10, 9) === 10, 'and the cap holds however high it starts');
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
ok(apcu_key_info(FOK_APCU_NS . 't:' . $tid)['ttl'] === Settings::int('tournament_done_ttl'),
    'and a finished bracket is kept longer than an abandoned one, to be read back');
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
// A frozen node is CLOSED, so the cursor stays on it instead of going null.
// The operator view has to read that as frozen and not as a match in flight,
// which would show a walkover deadline that can never fire.
$dz = Tournament::detail($tid2);
ok($dz['cursor'] === 'final' && $dz['wait'] === 'frozen' && $dz['wait_left_ms'] === null,
    'and the operator view calls it frozen, with no deadline behind it');

// ---- The clock moves on a participant's mailbox drain -----------------
// Nothing runs on a timer: a deadline fires on the next request that touches
// the tournament, and hello/poll.php touch it through pulse() for ANY
// participant. Three players, so one of them is a spectator of the match in
// flight - the carrier is any seat, not the two who played. Nothing below
// reads `state` to move the clock; the tournament is watched through the
// store directly.
$w = ['7b000001', '7b000002', '7b000003'];
foreach ($w as $p) {
    Presence::touch($p, '127.0.0.1');
}
$tid6 = Tournament::create($w[0], false)['tid'];
Tournament::join($w[1], $tid6);
Tournament::join($w[2], $tid6);
ok(TourneyStore::runningFor($w[1]) === null, 'a lobby seats nobody in a running tournament');
Tournament::start($w[0], $tid6);
ok(TourneyStore::runningFor($w[0]) === $tid6 && TourneyStore::runningFor($w[2]) === $tid6,
    'a start indexes every seat under the tournament');
$v6 = Tournament::view($w[0], $tid6);
[$x, $y] = $v6['roles']['players'];
$bystander = array_values(array_diff($w, [$x, $y]))[0];
foreach ($w as $p) {
    Signals::take($p);
}
ok(Tournament::report($x, $tid6, 'r1.1', 'win', [8, 2], null)['state'] === 'held', 'a lone win is held');
$dh = Tournament::detail($tid6);
ok($dh['wait'] === 'result' && $dh['wait_left_ms'] > 0,
    'which the operator view reads as a result waiting on the other side');
Tournament::pulse('79999999');
Tournament::pulse($bystander);
$raw = TourneyStore::get($tid6);
ok($raw['data']['results']['r1.1']['state'] === 'held',
    'a drain inside the grace changes nothing, whoever makes it');
$raw['data']['results']['r1.1']['reports'][$x]['at'] -= Settings::int('tournament_result_ms') + 1000;
TourneyStore::put($raw);
Tournament::pulse('79999999');
ok(TourneyStore::get($tid6)['data']['results']['r1.1']['state'] === 'held',
    'past the grace, a drain by a stranger still changes nothing');
Tournament::pulse($bystander);
$raw = TourneyStore::get($tid6);
ok($raw['data']['results']['r1.1']['state'] === 'settled',
    'a drain by the spectator of the match settles the held result');
ok($raw['data']['cursor'] === 'r1.2', 'and deals the next match');
$mail = [];
foreach ($w as $p) {
    $mail[$p] = Signals::take($p);
}
$got = $mail[$y];
$ev = static fn (array $s): array => json_decode($s['payload'], true);
$events = array_map(static fn (array $s): string => $ev($s)['event'], $got);
ok($got !== [] && $got[0]['type'] === 'tourney' && $events[0] === 'result',
    'the loser is told through the mailbox the drain was about to read');
// The result carries the standings it moved, ranked exactly as `state`
// ranks them and without the cut, which is not made until round 1 is over.
$v6 = Tournament::view($w[0], $tid6);
$res = $ev($got[0]);
ok(array_keys($res['rows'][0]) === ['seat', 'id', 'pts', 'diff', 'rank'],
    'a result carries the standings rows in the standings event\'s shape, without adv');
ok(json_encode($res['rows']) === json_encode($v6['standings']) && $res['rows'][0]['id'] === $x,
    'ranked as state ranks them, the winner on top');
ok(array_keys($res) === ['event', 'nid', 'winner', 'draw', 'score', 'why', 'rows', 'tid', 'after_ms'],
    'and is otherwise the event it always was');
// One push, several events per recipient: the stagger is a fixed step per
// RECIPIENT, so all of one seat's events carry the same delay and the
// callbacks land a step apart.
ok($ev($mail[$w[0]][0])['after_ms'] === 0 && $ev($mail[$w[1]][0])['after_ms'] === 100
    && $ev($mail[$w[2]][0])['after_ms'] === 200, 'the follow-up calls are staggered 100 ms per seat');
ok(count($mail[$w[2]]) >= 2 && $ev($mail[$w[2]][1])['after_ms'] === 200,
    'every event of the same push to the same seat carries the same delay');
[$x, $y] = $v6['roles']['players'];
ok(Tournament::report($y, $tid6, 'r1.2', 'draw', [3, 3], null)['state'] === 'held', 'a lone draw is held too');
$raw = TourneyStore::get($tid6);
$raw['data']['results']['r1.2']['reports'][$y]['at'] -= Settings::int('tournament_result_ms') + 1000;
TourneyStore::put($raw);
Settings::set('tourney_after_step_ms', 600);
Tournament::pulse($x);
Settings::set('tourney_after_step_ms', FOK_TOURNEY_AFTER_STEP_MS);
ok(TourneyStore::get($tid6)['data']['results']['r1.2']['state'] === 'settled',
    'and the opponent\'s own drain settles it just the same');
$drawn = null;
foreach (Tournament::detail($tid6)['nodes'] as $n) {
    if ($n['nid'] === 'r1.2') {
        $drawn = $n;
    }
}
ok($drawn['draw'] === true && $drawn['winner'] === null,
    'a drawn node reads as a draw, not as one whose winner went missing');
ok($ev(Signals::take($w[2])[0])['after_ms'] === 1000,
    'a wrong step cannot park a seat past the client\'s own 1000 ms cap');
// A dangling index - the tournament it names is gone - is dropped by the
// drain that finds it, not answered with an error.
apcu_store(FOK_APCU_NS . 'tin:7b000009', str_repeat('f', 32), 60);
Tournament::pulse('7b000009');
ok(TourneyStore::runningFor('7b000009') === null, 'an index that names nothing is dropped on the drain');
// A guest's leave is a forfeit; the host's is an abandon (seats are dealt
// by the seed, so the host is named, never taken from a role).
Tournament::leave($w[1], $tid6);
ok(TourneyStore::runningFor($w[1]) === null && TourneyStore::runningFor($w[0]) === $tid6
    && TourneyStore::runningFor($w[2]) === $tid6, 'a forfeit drops that seat from the index and nobody else');
Tournament::leave($w[0], $tid6);
ok(TourneyStore::runningFor($w[0]) === null && TourneyStore::runningFor($w[2]) === null,
    'the host ending it drops every seat');
Tournament::pulse($w[2]);
ok(TourneyStore::get($tid6)['state'] === 'abandoned', 'and a drain afterwards is a no-op');

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
// Proof that both networks are kept in the presence entry, one per family
// - which is also the whole bound on what one player can occupy.
$netsOf = static function (string $id): array {
    $nets = Presence::entryOf($id)['nets'] ?? [];
    ksort($nets);
    return array_map(static fn(array $n): string => (string)$n['net'], $nets);
};
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
    Presence::age($id, $secs);
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
Presence::age($claimer, 120, 4);
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
$ageNet = static function (string $id, int $family, int $secs): void {
    Presence::age($id, $secs, $family);
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
Presence::age($v[0], FOK_ONLINE_WINDOW + FOK_BEAT_JITTER + 1);
Presence::age($v[1], FOK_ONLINE_WINDOW + FOK_BEAT_JITTER + 1);
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
$d7 = Tournament::detail($tid7);
ok($d7['wait'] === 'break' && $d7['wait_left_ms'] > 0 && $d7['cursor'] === null,
    'and the operator view reads the break as the break, with its deadline');
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


// ---- What an operator sees, and the way out ---------------------------
// The admin popup reads a tournament without touching it, and ending one is
// the release valve for a tournament nobody is asking about any more: its
// deadlines are run by a seated player's own request, so one everybody
// walked away from stands where it stopped.
$a = ['78000001', '78000002'];
foreach ($a as $i => $pid) {
    Presence::touch($pid, '127.0.0.1', null, 'A' . $i);
}
Settings::set('tournament_create_cooldown', 0);
$tid8 = Tournament::create($a[0], true)['tid'];
Tournament::join($a[1], $tid8);
$d8 = Tournament::detail($tid8);
ok($d8['state'] === 'open' && $d8['stakes'] === true && count($d8['players']) === 2,
    'a lobby reads back with its seats');
ok($d8['players'][0]['host'] === true && $d8['players'][0]['seat'] === null,
    'the host is named as such, and a lobby has seated nobody yet');
ok($d8['players'][0]['name'] === 'A0' && $d8['players'][0]['online'] === true
    && $d8['players'][0]['last_seen'] !== null, 'with the name and the beat behind the id');
ok($d8['wait'] === null && $d8['nodes'] === [], 'and a lobby waits on nothing and has no matches');
Tournament::start($a[0], $tid8);
$d8 = Tournament::detail($tid8);
ok($d8['state'] === 'running' && $d8['cursor'] === 'r1.1', 'once started it names the match in flight');
ok(count(array_filter($d8['players'], static fn(array $p): bool => $p['playing'])) === 2,
    'and says which of the seats are playing it');
ok($d8['nodes'][0]['nid'] === 'r1.1' && $d8['nodes'][0]['current'] === true
    && $d8['nodes'][0]['a'] !== null, 'the match list names the node and its two players');
ok($d8['wait'] === 'match' && $d8['wait_left_ms'] > 0,
    'a dealt match waits on the walkover window');
// The reading an operator opens this for: the deadline ran out and the
// tournament is still in that state, so nothing has come to collect it.
$raw = TourneyStore::get($tid8);
$raw['data']['results']['r1.1']['dealt'] -= Settings::int('tournament_walkover_ms') + 5000;
TourneyStore::put($raw);
$d8 = Tournament::detail($tid8);
ok($d8['wait'] === 'match' && $d8['wait_left_ms'] < 0, 'a lapsed deadline reads as overdue');
ok(TourneyStore::get($tid8)['data']['results']['r1.1']['dealt']
    === $raw['data']['results']['r1.1']['dealt'], 'and reading it settled nothing');
foreach ($a as $pid) {
    Signals::take($pid);
}
ok(Tournament::abort($tid8)['ok'] === true, 'an operator ends it');
$d8 = Tournament::detail($tid8);
ok($d8['state'] === 'abandoned' && $d8['cursor'] === null && $d8['wait'] === null,
    'which stops it where it stood');
$told = Signals::take($a[1]);
ok($told !== [] && json_decode($told[0]['payload'], true)['reason'] === 'ended by the operator',
    'and every seat is told why');
ok(Tournament::abort($tid8)['ok'] === true, 'ending it again is a no-op, not an error');
ok(Tournament::abort(str_repeat('a', 32)) === null, 'and a tid that names nothing is no tournament');
ok(Tournament::detail(str_repeat('a', 32)) === null, 'as it is for the read');
ok(TourneyStore::hostedBy($a[0]) === null, 'the host is free again');
Settings::set('tournament_create_cooldown', 10);

// ---- A tournament nobody is at any more -------------------------------
// The one deadline any request may settle, because it is the only one whose
// subject is that no seated player is asking. The test is PRESENCE: a long
// match transitions rarely and must never be swept away from two people
// sitting in front of it.
$k = ['7a000001', '7a000002'];
foreach ($k as $pid) {
    Presence::touch($pid, '127.0.0.1');
}
Settings::set('tournament_create_cooldown', 0);
$tid9 = Tournament::create($k[0], false)['tid'];
Tournament::join($k[1], $tid9);
Tournament::sweep();
ok(TourneyStore::get($tid9)['state'] === 'open', 'a lobby both players are at survives a sweep');
Tournament::start($k[0], $tid9);
$idle = Settings::int('tournament_idle_ttl');
ok($idle > FOK_ONLINE_WINDOW,
    'the idle window is wider than the online one, so a player between beats is never gone');
Presence::age($k[0], $idle + 60);
Tournament::sweep();
ok(TourneyStore::get($tid9)['state'] === 'running',
    'and one seat still beating keeps the whole tournament alive');
Presence::age($k[1], $idle + 60);
foreach ($k as $pid) {
    Signals::take($pid);
}
Tournament::sweep();
$t9 = TourneyStore::get($tid9);
ok($t9['state'] === 'abandoned' && $t9['data']['cursor'] === null,
    'with every seat gone it is ended where it stood');
$why = Signals::take($k[1]);
ok($why !== [] && json_decode($why[0]['payload'], true)['reason'] === 'everyone left',
    'and whoever comes back is told why');
ok(TourneyStore::hostedBy($k[0]) === null,
    'which frees the host claim a dead tournament used to hold for an hour');
$cards = array_column(TourneyStore::liveCards(), 'tid');
ok(!in_array($tid9, $cards, true), 'and takes it off the index the sweep reads');
// Idempotent: the second sweep finds nothing to do, and an abandoned entry
// is not a live card any more.
Tournament::sweep();
ok(TourneyStore::get($tid9)['state'] === 'abandoned', 'a second sweep changes nothing');
// A LOBBY nobody is at goes the same way: it is as abandoned as a bracket
// nobody is playing, and it holds the same one-per-host claim.
$kl = ['7a000003', '7a000004'];
foreach ($kl as $pid) {
    Presence::touch($pid, '127.0.0.1');
}
$tidL = Tournament::create($kl[0], false)['tid'];
Tournament::join($kl[1], $tidL);
foreach ($kl as $pid) {
    Presence::age($pid, $idle + 60);
}
Tournament::sweep();
ok(TourneyStore::get($tidL)['state'] === 'abandoned', 'an open lobby nobody is at goes too');
ok(TourneyStore::byCode(Tournament::detail($tidL)['code']) === null,
    'which puts its join code back into circulation');
Settings::set('tournament_create_cooldown', 10);

// ---- What outlives a tournament --------------------------------------
// Tournament state is disposable and lives in shared memory, so these
// counters are the only record that any of it ever happened.
$totals = Stats::all();
ok(($totals['tourney_created'] ?? 0) > 0, 'every created tournament is counted');
ok(($totals['tourney_finished'] ?? 0) > 0, 'and so is every one that played out');
ok(($totals['tourney_matches'] ?? 0) > 0, 'with the matches it actually played');
ok(($totals['duel_started'] ?? 0) > 0, 'a 1vs1 is counted where play begins');
// A tournament lock is released by the worker that took it and by nobody
// else: the lease is short so a dead worker frees the tournament, which
// means it can also expire under a live one - and the next holder must not
// have the lock deleted out from under them.
ok(TourneyStore::lock('hklocktest'), 'a tournament lock is taken');
apcu_store(FOK_APCU_NS . 'tlock:hklocktest', 'another worker', 5);
TourneyStore::unlock('hklocktest');
ok(apcu_fetch(FOK_APCU_NS . 'tlock:hklocktest') === 'another worker',
    'and an unlock that no longer holds it leaves it alone');
apcu_delete(FOK_APCU_NS . 'tlock:hklocktest');
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
// replace (4.8): the answer to that 409. One call, because a client that
// had to leave and then create can lose the second half.
$held = TourneyStore::hostedBy('77000001');
Signals::take('77000001');
$c1b = Tournament::create('77000001', false, true);
ok($c1b['ok'] === true && $c1b['tid'] !== $held, 'replace opens a new one over the one held');
ok(TourneyStore::get($held)['state'] === 'abandoned', 'ending the one it replaced');
ok(TourneyStore::hostedBy('77000001') === $c1b['tid'], 'and the host claim names the new one');
$why = Signals::take('77000001');
ok($why !== [] && json_decode($why[0]['payload'], true)['reason'] === 'host opened a new one',
    'and its players are told what ended it, not that an operator did');
ok(Tournament::create('77000009', false, true)['ok'] === true,
    'replace with nothing to replace is an ordinary create');
Tournament::leave('77000001', $c1b['tid']);
Tournament::leave('77000009', TourneyStore::hostedBy('77000009') ?? '');
// And over a RUNNING one, which is the case that ends a tournament for
// everybody rather than closing an untouched lobby.
$rp = ['77000003', '77000004'];
foreach ($rp as $pid) {
    Presence::touch($pid, '127.0.0.1');
}
$tidR = Tournament::create($rp[0], false)['tid'];
Tournament::join($rp[1], $tidR);
Tournament::start($rp[0], $tidR);
ok(TourneyStore::get($tidR)['state'] === 'running', 'a running tournament stands in the way');
$c1c = Tournament::create($rp[0], false, true);
ok($c1c['ok'] === true && TourneyStore::get($tidR)['state'] === 'abandoned',
    'replace ends a running one too, not only an untouched lobby');
ok(TourneyStore::runningFor($rp[1]) === null, 'and unseats everyone who was in it');
Tournament::leave($rp[0], $c1c['tid']);
// The TTL is the gap a tournament may go untouched, not a lifetime: every
// transition re-stores it. The three states do not share one clock, because a
// lobby, a bracket in play and a podium are worth keeping for different
// lengths of time.
$c2 = Tournament::create('77000002', false);
$k2 = FOK_APCU_NS . 't:' . $c2['tid'];
ok(apcu_key_info($k2)['ttl'] === Settings::int('tournament_join_ttl'),
    'an open lobby is held for the join TTL');
Tournament::leave('77000002', $c2['tid']);
ok(apcu_key_info($k2)['ttl'] === Settings::int('tournament_abandoned_ttl'),
    'and an abandoned one only long enough to tell the players who were in it');

// The start level (4.9): the host picks what round 1 is played at and the
// ladder climbs from there, so the field still decides how much deeper the
// final gets.
$sl = ['77000005', '77000006'];
foreach ($sl as $pid) {
    Presence::touch($pid, '127.0.0.1');
}
$cs = Tournament::create($sl[0], false, false, 5);
ok($cs['lvl'] === 5, 'a create names the level its first round is played at');
Tournament::join($sl[1], $cs['tid']);
Tournament::start($sl[0], $cs['tid']);
$vs = Tournament::view($sl[0], $cs['tid']);
ok($vs['roles']['lvl'] === 5, 'and the first match is dealt at it');
ok($vs['schedule'][0]['lvl'] === 5, 'as is the schedule the whole lobby reads');
Tournament::leave($sl[0], $cs['tid']);
ok(Tournament::create($sl[0], false, false, 99)['lvl'] === Settings::int('tournament_max_level'),
    'a level past the last one the game has is clamped, not refused');
Tournament::leave($sl[0], TourneyStore::hostedBy($sl[0]) ?? '');
ok(Tournament::create($sl[0], false)['lvl'] === 1, 'and a create that says nothing starts at 1');
Tournament::leave($sl[0], TourneyStore::hostedBy($sl[0]) ?? '');

// A speed tournament (4.10): the host says every round is played as a speed
// round, and the server only carries the flag. It reaches the lobby, because a
// player decides on it before joining, and every roles sheet, because that is
// what the two players preset the match from.
$sp = ['77000007', '77000008'];
foreach ($sp as $pid) {
    Presence::touch($pid, '127.0.0.1');
}
$cp = Tournament::create($sp[0], false, false, 1, true);
ok($cp['speed'] === true, 'a create can declare every round a speed round');
ok(Tournament::join($sp[1], $cp['tid'])['speed'] === true,
    'and a joiner reads that off the lobby, before agreeing to play it');
Tournament::start($sp[0], $cp['tid']);
$vp = Tournament::view($sp[0], $cp['tid']);
ok($vp['roles']['speed'] === true, 'every match it deals is one');
ok(Tournament::detail($cp['tid'])['speed'] === true, 'and an operator can tell which it is');
Tournament::leave($sp[0], $cp['tid']);
ok(Tournament::create($sp[0], false)['speed'] === false,
    'a create that says nothing plays the ordinary mix');
Tournament::leave($sp[0], TourneyStore::hostedBy($sp[0]) ?? '');
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
// happens at the wall, this is what the server says before it. The block
// carries that one decision and nothing else - the beat is the contract's.
Settings::set('hold_max_workers', 4);
apcu_delete(new APCUIterator('/^fok:hold:/'));
$p = Pace::forTier(Pace::TIER_LOBBY);
ok($p === ['hold' => true],
    'an idle pool lets even a lobby hold a long poll, and says only that');
// Half the budget spent: the client that is only browsing gives way first.
apcu_add('fok:hold:0', 1, 20);
apcu_add('fok:hold:1', 1, 20);
ok(Pace::forTier(Pace::TIER_LOBBY)['hold'] === false,
    'a half-spent budget withdraws the lobby hold');
ok(Pace::forTier(Pace::TIER_TOURNEY)['hold'] === true,
    'a tournament screen keeps its hold that long');
ok(Pace::forTier(Pace::TIER_DUEL)['hold'] === true, 'and so does a duel');
// Three quarters: only the duel is still worth a held worker.
apcu_add('fok:hold:2', 1, 20);
ok(Pace::forTier(Pace::TIER_TOURNEY)['hold'] === false,
    'three quarters spent takes the tournament hold too');
ok(Pace::forTier(Pace::TIER_DUEL)['hold'] === true,
    'the duel handshake is the last thing to give way');
apcu_delete(new APCUIterator('/^fok:hold:/'));
Settings::set('hold_max_workers', FOK_HOLD_MAX_WORKERS);

// Housekeeping: one removal path, and only rows no reader can reach go.
Presence::touch('hk110001', '9.9.9.11');
Presence::touch('hk220002', '9.9.9.12');
Friends::request('hk110001', 'hk220002');
Friends::accept('hk220002', 'hk110001');
Vault::backup('hk110001', '{"cfg":1}', null);
Items::mint('hk110001', 'crown', 'box');
Presence::forget('hk110001');
ok(Presence::infoOf(['hk110001']) === [], 'forget removes the player row');
ok(!Friends::isFriend('hk110001', 'hk220002'), 'and the friendships with it');
ok(Presence::entryOf('hk110001') === null, 'and the presence entry, networks included');
ok(Vault::peek('hk110001') !== null, 'but the config backup outlives the player row');
ok(count(Items::owned('hk110001')) === 1, 'and the wardrobe: an id comes back with its client');

Presence::touchDuel('hk220002', 'hk330003');
Db::get()->exec("INSERT INTO duels (a, b, started, last_seen) VALUES ('hk440004', 'hk550005', 0, 0)");
Db::get()->exec("INSERT INTO alerts (type, message, created, seen) VALUES ('hk', 'read', 0, 1)");
Db::get()->exec("INSERT INTO alerts (type, message, created, seen) VALUES ('hk', 'unread', 0, 0)");
Db::get()->exec("INSERT INTO settings (key, value) VALUES ('retired_last_release', 7)");
// A known key needs a ROW to prove the sweep spares it, and a row exists only
// for an override (see Settings::set).
Settings::set('player_ttl_days', 366);
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
ok(!isset($rep['player_nets']) && $rep['friends']['loose'] === 0,
    'and finds no orphans, because there is only one removal path');
ok(isset($rep['starts']) && isset($rep['matches']),
    'the card accounts for every table the sweep touches');
ok($hk['db_size'] > 0, 'alongside the size of the file it is all in');

// A COMMIT names its transaction and what it wrote, because every contended
// path ends in one and the bare word says nothing about which was slow. The
// caller comes off the stack, so this probe has to BE a class - a frame with
// no class is the script's top level and is skipped.
final class LoadTxProbe
{
    /** Brackets a real write the way a transaction does, at a nameable cost. */
    public static function run(): void
    {
        Load::noteTime('BEGIN IMMEDIATE', 2000);
        Db::retry(static function (): void {
            Db::get()->prepare(
                "INSERT INTO counters (bucket, metric, value) VALUES ('meta', 'txprobe', 1)
                 ON CONFLICT (bucket, metric) DO UPDATE SET value = counters.value + 1"
            )->execute();
        });
        Load::noteTime('COMMIT', 3000);
    }
}
apcu_delete(new APCUIterator('/^' . preg_quote(FOK_APCU_NS . 'ct:worst:', '/') . '/'));
Load::flush();
LoadTxProbe::run();
Load::flush();
$txq = Counters::worstList('db_us')[0]['q'] ?? '';
ok(str_starts_with($txq, 'COMMIT LoadTxProbe::run'),
    'a COMMIT is named by the transaction that opened it, not by the plumbing');
ok(preg_match('/ (\d+)p$/', $txq, $m) === 1 && (int)$m[1] >= 1,
    'and carries the pages it appended to the write-ahead log');

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

// ---- Friend presence deltas (API 4.6) --------------------------------
// The entries are shared memory, so a test that needs a friend who last beat
// three minutes ago writes one directly rather than waiting for a window.
function ffEntry(string $id, array $over = []): void
{
    apcu_store(FOK_APCU_NS . 'p:' . $id, $over + [
        'seen' => time(), 'start' => time(), 'ip' => '9.9.9.9', 'lat' => 20,
        'name' => 'srv-CI-' . $id, 'accept' => 0, 'dbg' => false,
        'wish' => false, 'nets' => [], 'chg' => 1, 'duel' => 0,
        'dpeer' => null, 'dpriv' => false,
    ], 86400);
}

$me = 'ff110001';
$f1 = 'ff220002';
$f2 = 'ff330003';
Presence::touch($me, '9.9.9.1', null, 'srv-CI-me');
Presence::touch($f1, '9.9.9.2', null, 'srv-CI-one');
Presence::touch($f2, '9.9.9.3', null, 'srv-CI-two');
Friends::request($me, $f1);
Friends::accept($f1, $me);
Friends::request($me, $f2);
Friends::accept($f2, $me);

// A cursor of 0 is "I know nothing", not "nothing changed since the epoch".
$d = FriendFeed::delta($me, 0);
ok(count($d['rows']) === 2, 'a cursor of 0 answers with every accepted friend');
ok(($d['rows'][$f1]['online'] ?? null) === true, 'a friend who just beat reads online');
ok(array_keys($d['rows'][$f1]) === ['online', 'playing', 'latency', 'name'],
    'a row is the whole current state, not a description of the change');
ok($d['more'] === false, 'two rows do not reach the cap');
ok(FriendFeed::delta($me, $d['at'])['rows'] === [], 'and the next read finds nothing changed');

// Authorization: the delta answers for ACCEPTED friendships and no other.
Friends::request($me, 'ff660006');
ok(!isset(FriendFeed::delta($me, 0)['rows']['ff660006']),
    'a pending friendship is not in the delta');
Friends::accept('ff660006', $me);
ok(isset(FriendFeed::delta($me, 0)['rows']['ff660006']),
    'accepting shows up at once - the write dropped the cached list');
Friends::remove($me, 'ff660006');
ok(!isset(FriendFeed::delta($me, 0)['rows']['ff660006']),
    'and removing the friendship takes the row away again');
ok(FriendFeed::delta('ff990009', 0)['rows'] === [], 'a caller with no friends gets nothing');

// Transition: coming online. The entry is gone, so the beat opens a session.
// The other friend is pinned to an old stamp first: everything above ran
// inside one millisecond, and a tie with the cursor is not what is under test.
ffEntry($f2);
apcu_delete(FOK_APCU_NS . 'p:' . $f1);
$cur = Util::nowMs() - 1;
Presence::touch($f1, '9.9.9.2', null, 'srv-CI-one');
$d = FriendFeed::delta($me, $cur);
ok(($d['rows'][$f1]['online'] ?? null) === true, 'coming online is reported as a transition');
ok(!isset($d['rows'][$f2]), 'and a friend who did not move stays out of the answer');

// Transition: a rename, on a beat that is not a new session.
ffEntry($f1, ['name' => 'srv-CI-old']);
$cur = Util::nowMs() - 1;
Presence::touch($f1, '9.9.9.2', null, 'srv-CI-new');
ok((FriendFeed::delta($me, $cur)['rows'][$f1]['name'] ?? '') === 'srv-CI-new',
    'a rename is a transition of its own');

// Transition: entering a duel. Per player, so the peer who did not insert
// the duels row announces it too.
ffEntry($f2);
$cur = Util::nowMs() - 1;
Presence::touchDuel($f2, 'ff440004');
$d = FriendFeed::delta($me, $cur);
ok(($d['rows'][$f2]['playing'] ?? null) === true, 'entering a duel is a transition');
Presence::touchDuel($f2, 'ff440004');
ok(!isset(FriendFeed::delta($me, $d['at'])['rows'][$f2]),
    'the beats that keep the duel alive are not');

// The end of a duel is ANNOUNCED, not waited out: the client says so when
// the session tears down and the friend's row moves with it.
ffEntry($f2, ['duel' => time(), 'dpeer' => 'ff440004']);
$cur = Util::nowMs() - 1;
Presence::endDuel($f2, 'ff440004');
$d = FriendFeed::delta($me, $cur);
ok(($d['rows'][$f2]['playing'] ?? null) === false, 'an announced end is a transition too');
ok(($d['rows'][$f2]['online'] ?? null) === true, 'and leaves the player online');

// A late end, overtaken by the next pairing, names a peer the player is no
// longer playing - and must not cancel the duel that replaced it.
ffEntry($f2, ['duel' => time(), 'dpeer' => 'ff770007']);
Presence::endDuel($f2, 'ff440004');
ok((FriendFeed::delta($me, 0)['rows'][$f2]['playing'] ?? null) === true,
    'an end naming a peer the player already left is ignored');
Presence::endDuel($f2, 'ff770007');
ok((FriendFeed::delta($me, 0)['rows'][$f2]['playing'] ?? null) === false,
    'while the end naming the current peer lands');

// A PRIVATE duel is counted and never attributed: the player is playing,
// and no friend is offered a spectate link for it.
ffEntry($f1);
ffEntry($f2);
$cur = Util::nowMs() - 1;
Presence::touchDuel($f2, 'ff440004', true);
ok((FriendFeed::delta($me, 0)['rows'][$f2]['playing'] ?? null) === false,
    'a private duel never reads as playing to a friend');
ok(FriendFeed::delta($me, $cur)['rows'] === [],
    'and entering one announces nothing, so no held poll wakes for it');
ok((int)(apcu_fetch(FOK_APCU_NS . 'p:' . $f2)['duel'] ?? 0) > 0,
    'the duel itself is recorded exactly as a public one is');

// Turning privacy on mid-match is an ordinary transition, in both
// directions: the question asked is what a FRIEND can see, not what changed.
ffEntry($f2, ['duel' => time(), 'dpeer' => 'ff440004']);
$cur = Util::nowMs() - 1;
Presence::touchDuel($f2, 'ff440004', true);
ok((FriendFeed::delta($me, $cur)['rows'][$f2]['playing'] ?? null) === false,
    'a public duel going private is announced as leaving');
$cur = Util::nowMs() - 1;
Presence::touchDuel($f2, 'ff440004', false);
ok((FriendFeed::delta($me, $cur)['rows'][$f2]['playing'] ?? null) === true,
    'and going public again is announced as entering');

// The spectate offer expires on its OWN window, shorter than the duel's:
// a client that crashed stops being offered well before it reads offline.
ok(FOK_DUEL_SEEN_WINDOW < FOK_ONLINE_WINDOW,
    'the spectate window is inside the online window, or a crash is invisible');
ffEntry($f2, ['duel' => time() - FOK_DUEL_SEEN_WINDOW - 30]);
$d = FriendFeed::delta($me, 0);
ok(($d['rows'][$f2]['playing'] ?? null) === false,
    'a duel nobody refreshed stops being offered');
ok(($d['rows'][$f2]['online'] ?? null) === true,
    'while the player is still inside the online window');

// Derived, not pushed: going offline and leaving a duel are the absence of a
// beat, so they are read off the windows against an older cursor.
$old = Util::nowMs() - 60000;
ffEntry($f1, ['seen' => time() - FOK_ONLINE_WINDOW - 30]);
ffEntry($f2, ['duel' => time() - FOK_DUEL_SEEN_WINDOW - 30]);
$d = FriendFeed::delta($me, $old);
ok(($d['rows'][$f1]['online'] ?? null) === false, 'a friend who stopped beating reads offline');
ok($d['rows'][$f1]['latency'] === null, 'and reports no latency, being nowhere');
ok(($d['rows'][$f2]['playing'] ?? null) === false, 'a duel that stopped reporting ends by the window');
ok(($d['rows'][$f2]['online'] ?? null) === true, 'while the player it belongs to is still here');
ok(FriendFeed::delta($me, $d['at'])['rows'] === [], 'a lapse is reported once, not on every read');

// The cap spreads over consecutive answers and never splits a stamp tie.
Settings::set('friends_delta_max', 1);
ffEntry($f1, ['chg' => 100000]);
ffEntry($f2, ['chg' => 200000]);
$d = FriendFeed::delta($me, 1);
ok(count($d['rows']) === 1 && $d['more'] === true, 'the cap cuts the answer short and says so');
ok($d['at'] === 100000, 'the cursor to continue from is the last row included');
$d = FriendFeed::delta($me, $d['at']);
ok(count($d['rows']) === 1 && isset($d['rows'][$f2]) && $d['more'] === false,
    'and the next read carries the rest');
ffEntry($f2, ['chg' => 100000]);
$d = FriendFeed::delta($me, 1);
ok(count($d['rows']) === 2 && $d['more'] === false,
    'a page runs past the cap rather than split a stamp tie');
Settings::set('friends_delta_max', 64);

// The wake: what a held poll checks beside the mailbox.
ffEntry($f1);
ffEntry($f2);
FriendFeed::delta($me, Util::nowMs());
$cur = Util::nowMs();
usleep(2000);
ok(FriendFeed::pending($me, $cur) === false, 'a quiet roster leaves the hold alone');
Presence::touchDuel($f1, 'ff550005');
ok(FriendFeed::pending($me, $cur) === true, 'a friend transition wakes the held poll');

// ---- Housekeeping asks for the writer, it never waits for it ---------
// The hourly pass runs in a deferred tail on some client's worker, so a task
// that cannot have the single writer is skipped and done next time instead
// of sitting out busy_timeout (1 s) with a worker in its hand.
ok(Db::tryWrite(static function (): void {
    Db::get()->prepare(
        "INSERT INTO counters (bucket, metric, value) VALUES ('meta', 'trylock', 1)
         ON CONFLICT (bucket, metric) DO UPDATE SET value = excluded.value"
    )->execute();
}) === true, 'a free writer is taken and the work commits');
$st = Db::get()->query("SELECT value FROM counters WHERE bucket = 'meta' AND metric = 'trylock'");
$wrote = (int)$st->fetchColumn();
$st->closeCursor();
ok($wrote === 1, 'what tryWrite committed is there to read');

// A second connection holds the writer for the length of this block.
$other = new PDO('sqlite:' . FOK_DB_FILE, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$other->exec('PRAGMA busy_timeout = 0');
$other->exec('BEGIN IMMEDIATE');
$t0 = microtime(true);
$skipped = Db::tryWrite(static function (): void {
    Db::get()->prepare("DELETE FROM counters WHERE bucket = 'meta' AND metric = 'trylock'")->execute();
});
$waitedMs = (microtime(true) - $t0) * 1000;
$other->exec('ROLLBACK');
$other = null;
ok($skipped === false, 'a writer somebody else holds is skipped, not waited for');
ok($waitedMs < 500, 'and the skip does not sit out busy_timeout');
$st = Db::get()->query("SELECT value FROM counters WHERE bucket = 'meta' AND metric = 'trylock'");
$survived = (int)$st->fetchColumn();
$st->closeCursor();
ok($survived === 1, 'a skipped task left the database exactly as it was');

// ---- What every database access costs -------------------------------
// Two readings, taken apart at the only place they CAN be taken apart: a
// BEGIN IMMEDIATE does nothing but acquire, so its whole duration is a lock
// wait, and anything else is the statement's own work (see Load::noteTime).
// The skip above is still pending in this request, so it folds with them.
$dbcost = inOneMinute(static function (): array {
    Db::get()->exec('DELETE FROM counters');
    Db::get()->exec('BEGIN IMMEDIATE');
    Db::get()->exec('COMMIT');
    $st = Db::get()->query('SELECT COUNT(*) FROM players');
    $st->fetchColumn();
    $st->closeCursor();
    Load::flush();
    Counters::flushDue(gmdate('YmdHi', time() + 60));
    $out = [];
    foreach (Db::get()->query('SELECT metric, value FROM counters')->fetchAll() as $r) {
        $out[(string)$r['metric']] = (int)$r['value'];
    }
    return $out;
});
ok(($dbcost['n:dbw_n'] ?? 0) >= 1, 'taking the writer is booked as a lock wait');
ok(($dbcost['n:dbt_n'] ?? 0) >= 1, 'an ordinary statement is booked as access time');
// The worst single case of the minute, beside the mean. Only the statement
// side is asserted: an UNCONTENDED acquisition can round to zero
// microseconds, and a maximum of zero is not recorded (Counters::max).
ok(isset($dbcost['x:dbt_us']) && $dbcost['x:dbt_us'] > 0,
    'the slowest statement of the minute is kept beside the mean');
ok(($dbcost['n:db_skip'] ?? 0) >= 1, 'a writer the housekeeping stepped aside for is counted');

// ---- Folding the write-ahead log back --------------------------------
// The log is folded into the database file by the hourly tail, not by
// whichever write happens to cross SQLite's own threshold - which is
// routinely a player's (see Db::drainWal). The drain reports the pages it
// folded, and the log restarts at its beginning on the next write after
// one, so the write following a drain leaves almost nothing behind it.
for ($i = 0; $i < 50; $i++) {
    Db::get()->prepare(
        "INSERT INTO counters (bucket, metric, value) VALUES ('meta', ?, 1)
         ON CONFLICT (bucket, metric) DO UPDATE SET value = value + 1"
    )->execute(['wal' . $i]);
}
$walFolded = Db::drainWal();
ok($walFolded > 0, 'the drain folds the pending log back into the database');
Db::get()->prepare(
    "INSERT INTO counters (bucket, metric, value) VALUES ('meta', 'wal-after', 1)
     ON CONFLICT (bucket, metric) DO UPDATE SET value = value + 1"
)->execute();
ok(Db::drainWal() < $walFolded, 'and the write after one starts the log over rather than adding to it');


// ---------------------------------------------------------------- events
//
// An event is a room an operator opens, entered by scanning a code. Its
// STATE is a pure function of (mode, starts, ends, now) - there is no cron
// here, so nothing fires at a scheduled moment - and its pass is derived
// from the clock, so no slot is ever stored. Both are asserted over their
// whole grid rather than at one point, because both are read on every
// request that touches an event.

$evCard = static function (array $over = []): array {
    return $over + [
        'eid' => 'AAAA', 'name' => 'srv-CI-event', 'descr' => '',
        'organizer' => null, 'ekey' => str_repeat('A', 16),
        'secret' => str_repeat('ab', 32), 'closed' => false,
        'starts' => null, 'ends' => null, 'mode' => 'upcoming',
        'ach_name' => null, 'ach_desc' => null, 'ach_icon' => null,
        'created' => 1000, 'ended_at' => null,
    ];
};

// Unscheduled: the organizer drives it, so the stored mode IS the state.
ok(Events::stateOf($evCard(['mode' => 'upcoming']), 5000) === 'upcoming',
    'an unscheduled event waits in the mode it was opened in');
ok(Events::stateOf($evCard(['mode' => 'active']), 5000) === 'active',
    'and runs once its organizer has run it');
ok(Events::stateOf($evCard(['mode' => 'paused']), 5000) === 'paused',
    'a pause is a state of its own');
ok(Events::stateOf($evCard(['mode' => 'ended']), 5000) === 'ended',
    'and an ended event is ended');

// Scheduled: the clock drives it, and nothing has to run for a moment to
// arrive - which is the whole reason state is derived rather than swept.
$sched = $evCard(['mode' => 'upcoming', 'starts' => 100, 'ends' => 200]);
ok(Events::stateOf($sched, 99) === 'upcoming', 'a scheduled event is upcoming before its start');
ok(Events::stateOf($sched, 100) === 'active', 'active from the second it starts');
ok(Events::stateOf($sched, 199) === 'active', 'still active a second before its end');
ok(Events::stateOf($sched, 200) === 'ended', 'and ended from the second it ends');
ok(Events::stateOf($sched, 5000) === 'ended', 'and stays ended, with nobody having run');

// A schedule does not undo a pause somebody pressed, and an end outranks
// everything: the two are the only orderings that can disagree.
ok(Events::stateOf($evCard(['mode' => 'paused', 'starts' => 100]), 150) === 'paused',
    'a pause outranks a schedule that says the event is under way');
ok(Events::stateOf($evCard(['mode' => 'ended', 'starts' => 100]), 150) === 'ended',
    'and an end outranks the schedule entirely');
ok(Events::stateOf($evCard(['mode' => 'active', 'ends' => 200]), 300) === 'ended',
    'a run that outlives its own end reads as ended');
ok(Events::stateOf($evCard(['mode' => 'upcoming', 'ends' => 200]), 100) === 'upcoming',
    'an end alone does not start an event early');
ok(!Events::isScheduled($evCard()), 'an event with neither stamp is unscheduled');
ok(Events::isScheduled($evCard(['ends' => 200])), 'one stamp is enough to make it scheduled');

// The pass: an HMAC over the event secret and the slot, mapped onto the
// code alphabet. Same slot, same code; different event, different code.
$evAlpha = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
$p1 = Events::passFor(str_repeat('ab', 32), 'AAAA', 100);
ok(strlen($p1) === 6, 'a pass is six characters');
ok(strspn($p1, $evAlpha) === 6, 'from the code alphabet, so it can be read off a screen');
ok(Events::passFor(str_repeat('ab', 32), 'AAAA', 100) === $p1, 'the same slot mints the same pass');
ok(Events::passFor(str_repeat('ab', 32), 'AAAA', 101) !== $p1, 'the next slot mints a different one');
ok(Events::passFor(str_repeat('ab', 32), 'BBBB', 100) !== $p1, 'and so does another event at the same moment');
ok(Events::passFor(str_repeat('cd', 32), 'AAAA', 100) !== $p1, 'the secret is what makes it unguessable');

// The validity window, at its edges. A code minted for slot S is accepted
// while now is inside [S*step, S*step + valid) - two slots by default, so
// what is on somebody else's screen still works while that screen has
// moved on.
$evP = $evCard();
$slot10 = Events::passFor($evP['secret'], 'AAAA', 10);   // shown from 100 s
ok(Events::verifyPass($evP, $slot10, 100), 'a pass verifies the second it is minted');
ok(Events::verifyPass($evP, $slot10, 109), 'and at the end of its own slot');
ok(Events::verifyPass($evP, $slot10, 110), 'and through the slot after it');
ok(Events::verifyPass($evP, $slot10, 119), 'right up to the last second of the overlap');
ok(!Events::verifyPass($evP, $slot10, 120), 'and is refused once the window has passed');
ok(!Events::verifyPass($evP, $slot10, 99), 'a pass is not valid before its own slot');
ok(!Events::verifyPass($evP, 'ZZZZZZ', 100), 'a code nobody minted never verifies');
ok(!Events::verifyPass($evP, '', 100), 'nor does an empty one');

$evSlots = Events::mintPasses($evP, 6, 105);
ok(count($evSlots) === 6, 'a pass request hands out six slots, a minute of QR');
ok($evSlots[0]['at'] === 100000, 'the first is the slot now is inside, in server ms');
ok($evSlots[1]['at'] === 110000, 'and they step by the rotation interval');
ok($evSlots[0]['code'] === $slot10, 'the current slot mints the code that is valid now');
ok(Events::verifyPass($evP, $evSlots[5]['code'], 155),
    'and the last one still verifies when its own moment comes');

// The rows. A scan is the only way to get one, and it is idempotent: a
// client that lost the answer simply asks again.
$evOpen = Events::create(['name' => 'srv-CI-open', 'organizer' => '11117e57']);
ok(strlen($evOpen['eid']) === 4, 'an event gets a four-character public id');
ok(strlen($evOpen['ekey']) === 11,
    'and an eleven-character printed key, which is the whole version 3 budget');
ok(Events::byKey($evOpen['ekey'])['eid'] === $evOpen['eid'],
    'that names its own event, because a poster carries nothing beside it');
ok(Events::byKey(str_repeat('Z', 11)) === null, 'and a key nobody minted names none');
ok(strlen($evOpen['secret']) === 64, 'and 32 bytes of secret nobody outside the server sees');
ok($evOpen['mode'] === 'upcoming', 'a new event waits to be run');
ok(Events::isMember($evOpen['eid'], '11117e57'),
    'and its organizer is seated by being named, having scanned nothing');
ok(Events::counts($evOpen['eid'])['members'] === 1,
    'so a brand new event already holds one participant');

$evClosed = Events::create(['name' => 'srv-CI-closed', 'organizer' => '11117e57',
    'closed' => true, 'mode' => 'active']);
ok($evClosed['closed'] === true, 'an event can be opened with its door closed');
ok($evClosed['eid'] !== $evOpen['eid'], 'and every event gets an id of its own');

ok(Events::admit($evOpen['eid'], '22227e57', false, 'key') === 'member',
    'an open door makes a scanner a member at once');
ok(Events::admit($evOpen['eid'], '22227e57', false, 'key') === 'member',
    'and scanning again answers the same, so a lost response costs nothing');
ok(Events::counts($evOpen['eid'])['members'] === 2,
    'the scanner is counted once beside the organizer, not twice');
ok(Events::isMember($evOpen['eid'], '22227e57'), 'and reads as a member');
ok(!Events::isMember($evOpen['eid'], '33337e57'), 'while a stranger does not');

ok(Events::admit($evClosed['eid'], '22227e57', true, 'pass') === 'pending',
    'a closed door makes a scanner pending');
ok(!Events::isMember($evClosed['eid'], '22227e57'), 'a pending row is not a member');
ok(Events::counts($evClosed['eid'])['pending'] === 1, 'and is counted as waiting');
ok(Events::counts($evClosed['eid'])['members'] === 1,
    'and not as somebody who is in - only the organizer is');

// The public face is what a pending row may read, and it carries none of
// the members-only half.
$evFace = Events::publicFace($evClosed, 5000);
ok(isset($evFace['name'], $evFace['state'], $evFace['closed']),
    'the public face names the event, its door and its state');
ok(!isset($evFace['members']), 'and never how many are already in');
ok(!array_key_exists('ekey', $evFace), 'the printed key is in no projection');
ok(!array_key_exists('secret', $evFace), 'and neither is the secret');
ok($evFace['starts'] === null, 'an unscheduled event answers no start');

// setMember is the ONE path behind the organizer's roster verb and the
// operator's admin action, so the two can never drift apart.
ok(Events::setMember($evClosed['eid'], '22227e57', 'member'), 'approving a pending row changes it');
ok(Events::isMember($evClosed['eid'], '22227e57'), 'and the person is in');
ok(!Events::setMember($evClosed['eid'], '22227e57', 'member'), 'approving again changes nothing');
ok(Events::counts($evClosed['eid'])['pending'] === 0, 'and nobody is left waiting');

ok(Events::setMember($evClosed['eid'], '33337e57', 'member', 'admin'),
    'an operator can seat somebody who never scanned');
$evRows = Events::members($evClosed['eid'], true);
$evVia = [];
foreach ($evRows as $r) { $evVia[$r['id']] = $r['via']; }
ok(($evVia['33337e57'] ?? '') === 'admin', 'and the row records that it came from the dashboard');
ok(($evVia['22227e57'] ?? '') === 'pass', 'while a scan records the code it was let in with');

ok(Events::setMember($evClosed['eid'], '33337e57', 'banned'), 'a member can be banned');
$evBan = Events::rowOf($evClosed['eid'], '33337e57');
ok($evBan !== null && $evBan['state'] === 'banned', 'and the row stays, saying so');
ok(Events::counts($evClosed['eid'])['banned'] === 1, 'a ban is counted apart from the members');
ok(Events::setMember($evClosed['eid'], '33337e57', 'none'), 'lifting a ban drops the row');
ok(Events::rowOf($evClosed['eid'], '33337e57') === null, 'so the person may scan again');

// A plain member never learns that pending or banned rows exist.
Events::admit($evClosed['eid'], '33337e57', true, 'key');
ok(count(Events::members($evClosed['eid'], false)) === 2,
    'a member sees only the people who are in');
ok(count(Events::members($evClosed['eid'], true)) === 3,
    'the organizer sees who is waiting too');
ok(!in_array('33337e57', Events::audience($evClosed['eid']), true),
    'and a pending row is told nothing: it is standing at a door');

// Closing and reopening the door decides new scans, never the queue that
// is already standing at it.
Events::setClosed($evClosed['eid'], false);
$evAfter = Events::card($evClosed['eid']);
ok($evAfter !== null && $evAfter['closed'] === false, 'the door can be opened again');
$evStill = Events::rowOf($evClosed['eid'], '33337e57');
ok($evStill !== null && $evStill['state'] === 'pending',
    'and what was already pending is still the organizer to decide');

// The caller's own list is what hello and poll answer, and a banned row is
// not an event as far as its owner is concerned.
$evMine = Events::listFor('22227e57', 5000);
ok(count($evMine) === 2, 'a player reads back every event it has a row in');
$evByEid = [];
foreach ($evMine as $e) { $evByEid[$e['eid']] = $e; }
ok(isset($evByEid[$evOpen['eid']], $evByEid[$evClosed['eid']]), 'both of them, by id');
ok($evByEid[$evOpen['eid']]['you']['state'] === 'member', 'each saying where the caller stands');
ok(isset($evByEid[$evOpen['eid']]['members']), 'a member row carries the count');
Events::setMember($evOpen['eid'], '22227e57', 'banned');
ok(count(Events::listFor('22227e57', 5000)) === 1, 'a banned row is not an event the caller has');
Events::setMember($evOpen['eid'], '22227e57', 'none');

// A pending row is told the door it is standing at, and nothing behind it.
$evPend = Events::listFor('33337e57', 5000);
ok(count($evPend) === 1 && $evPend[0]['you']['state'] === 'pending',
    'a pending row is listed, marked as waiting');
ok(!isset($evPend[0]['members']), 'and is never told the size of the room');

// The achievement rides every member answer, so a reinstalled client
// re-grants it; the server records nothing about having given it out.
ok(Events::ach($evOpen) === null, 'an event with no achievement offers none');
Events::edit($evOpen['eid'], ['ach_name' => 'NIGHT OWL', 'ach_desc' => 'Joined srv-CI-open']);
$evAch = Events::ach(Events::card($evOpen['eid']) ?? []);
ok($evAch !== null && $evAch['id'] === 'ev_' . $evOpen['eid'], 'the achievement is named after its event');
ok($evAch['name'] === 'NIGHT OWL', 'and carries what the operator wrote');
ok(!isset($evAch['icon']), 'an icon is optional, and absent means the client default');

// run / pause / end, and the one-way door at the end of it.
Events::setMode($evOpen['eid'], 'active');
ok(Events::stateOf(Events::card($evOpen['eid']) ?? [], 5000) === 'active', 'an organizer can run an event');
Events::setMode($evOpen['eid'], 'paused');
ok(Events::stateOf(Events::card($evOpen['eid']) ?? [], 5000) === 'paused', 'and pause it');
Events::setMode($evOpen['eid'], 'ended');
ok(Events::stateOf(Events::card($evOpen['eid']) ?? [], 5000) === 'ended', 'and end it');
Events::setMode($evOpen['eid'], 'active');
ok(Events::stateOf(Events::card($evOpen['eid']) ?? [], 5000) === 'ended',
    'and an ended event can never be run again');

// The archive outlives everything: it is written whatever state the event
// is in, because the match began while the event was live.
Events::archive($evOpen['eid'], ['tid' => str_repeat('a', 32), 'host' => '11117e57',
    'started' => 900, 'finished' => 1000, 'seats' => 4, 'played' => 3,
    'podium' => ['11117e57', '22227e57'], 'standings' => [['id' => '11117e57', 'pts' => 6]]]);
$evArch = Events::archiveOf($evOpen['eid']);
ok(count($evArch) === 1, 'a finished tournament is archived on its event');
ok($evArch[0]['seats'] === 4 && $evArch[0]['played'] === 3, 'with the shape of the evening');
ok($evArch[0]['podium'] === ['11117e57', '22227e57'], 'and who was on the podium');
ok($evArch[0]['standings'][0]['pts'] === 6, 'the standings surviving the round trip through JSON');

// The wrong-code throttle is a fixed window per player: it is there so the
// attempt is on record, not because the codes could be guessed.
if (Caps::apcu()) {
    ok(!Events::failsOver('44447e57'), 'a player with no failures is not throttled');
    for ($i = 0; $i < Settings::int('event_join_fails_per_min'); $i++) {
        Events::noteFail('44447e57');
    }
    ok(Events::failsOver('44447e57'), 'and is once it has walked into the cap');
    ok(!Events::failsOver('55557e57'), 'while the player beside it is untouched');
}

// A player that expires takes its roster rows with it, and leaves the
// archive alone - a name that no longer resolves is a real answer.
Events::admit($evClosed['eid'], '44447e57', false, 'key');
ok(count(Events::listFor('44447e57', 5000)) === 1, 'the player is in an event');
Events::forgetPlayer('44447e57');
ok(count(Events::listFor('44447e57', 5000)) === 0, 'expiry takes the roster row');
ok(count(Events::archiveOf($evOpen['eid'])) === 1, 'and leaves the record of the evening standing');

/**
 * Reads a QR matrix back the way a scanner does: unmask, follow the same
 * zigzag, de-interleave the blocks and parse the byte-mode header. It shares
 * the GEOMETRY with the encoder (that map is pinned against the client's
 * independent encoder) but nothing else - which is the point, because the
 * block interleaving of a multi-block version is the one thing the client's
 * fixed single-block encoder cannot check.
 */
function qrCodewordsOf(array $q): array
{
    $bits = [];
    foreach (Qr::dataOrder($q['version']) as [$x, $y]) {
        $v = $q['m'][$y][$x];
        if (Qr::masked($q['mask'], $x, $y)) {
            $v = !$v;
        }
        $bits[] = $v ? 1 : 0;
    }
    [$ecc, $blocks, $perBlock] = Qr::layout($q['version'], $q['level']);
    $total = ($blocks * $perBlock + $blocks * $ecc) * 8;
    $bytes = [];
    for ($i = 0; $i + 8 <= $total; $i += 8) {
        $b = 0;
        for ($j = 0; $j < 8; $j++) {
            $b = ($b << 1) | $bits[$i + $j];
        }
        $bytes[] = $b;
    }
    // De-interleave: the standard writes one codeword from each block in
    // turn, data first and then ECC.
    $data = array_fill(0, $blocks, []);
    $par = array_fill(0, $blocks, []);
    $k = 0;
    for ($i = 0; $i < $perBlock; $i++) {
        for ($b = 0; $b < $blocks; $b++) {
            $data[$b][$i] = $bytes[$k++];
        }
    }
    for ($i = 0; $i < $ecc; $i++) {
        for ($b = 0; $b < $blocks; $b++) {
            $par[$b][$i] = $bytes[$k++];
        }
    }
    return ['data' => $data, 'ecc' => $par];
}

/** The payload a matrix carries, or null when the header is not byte mode. */
function qrReadBack(array $q): ?string
{
    $cw = qrCodewordsOf($q);
    $flat = [];
    foreach ($cw['data'] as $blk) {
        foreach ($blk as $b) {
            $flat[] = $b;
        }
    }
    // Blocks are equal-sized here, so the payload runs straight through them
    // in block order - which is how the encoder laid it down.
    $mode = $flat[0] >> 4;
    if ($mode !== 4) {
        return null;
    }
    $len = (($flat[0] & 0x0F) << 4) | ($flat[1] >> 4);
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= chr(((($flat[1 + $i] & 0x0F) << 4) | ($flat[2 + $i] >> 4)) & 0xFF);
    }
    return $out;
}

/**
 * Every Reed-Solomon syndrome of every block, evaluated INDEPENDENTLY of the
 * routine that produced the parity: a log/antilog table built from the same
 * field, then Horner over the whole block at a^0 .. a^(ecc-1). All zero means
 * the codeword is a valid one, which is what a printed code lives on.
 */
function qrSyndromesZero(array $q): bool
{
    $exp = [];
    $x = 1;
    for ($i = 0; $i < 256; $i++) {
        $exp[$i] = $x;
        $x <<= 1;
        if ($x & 0x100) {
            $x ^= 0x11D;
        }
    }
    $mul = static function (int $a, int $b) use ($exp): int {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        $la = array_search($a, array_slice($exp, 0, 255), true);
        $lb = array_search($b, array_slice($exp, 0, 255), true);
        return $exp[((int)$la + (int)$lb) % 255];
    };
    $cw = qrCodewordsOf($q);
    [$ecc] = Qr::layout($q['version'], $q['level']);
    foreach ($cw['data'] as $b => $block) {
        $full = array_merge($block, $cw['ecc'][$b]);
        for ($s = 0; $s < $ecc; $s++) {
            $acc = 0;
            foreach ($full as $byte) {
                $acc = $mul($acc, $exp[$s]) ^ $byte;
            }
            if ($acc !== 0) {
                return false;
            }
        }
    }
    return true;
}


// The MONITOR slot: one screen per event, held two different ways, and the
// difference is the whole feature - a reservation survives the screen being
// switched off, a lease does not.
$evMon = Events::create(['name' => 'srv-CI-monitor', 'organizer' => '11117e57',
    'mode' => 'active']);
ok($evMon['monitor_allowed'] === true, 'an event offers a monitor unless it is told not to');
ok($evMon['monitor'] === null, 'and reserves it for nobody by default');
$evNoMon = Events::create(['name' => 'srv-CI-nomonitor', 'monitor_allowed' => false]);
ok($evNoMon['monitor_allowed'] === false, 'an operator can decline to offer one');

if (Caps::apcu()) {
    ok(Events::monitorHolder($evMon) === null, 'a free slot is held by nobody');
    ok(Events::claimMonitor($evMon, '22227e57'), 'and the first screen to ask takes it');
    ok(Events::monitorHolder($evMon) === '22227e57', 'which then holds it');
    ok(!Events::claimMonitor($evMon, '33337e57'), 'so the second screen is turned away');
    ok(Events::claimMonitor($evMon, '22227e57'), 'while the holder renews by simply asking again');
    Events::releaseMonitor($evMon['eid'], '33337e57');
    ok(Events::monitorHolder($evMon) === '22227e57',
        'and somebody who never held it cannot give it up on the holder`s behalf');
    Events::releaseMonitor($evMon['eid'], '22227e57');
    ok(Events::monitorHolder($evMon) === null, 'the holder gives it up and the slot is free');
}

// A RESERVATION outranks the lease: the screen holds its place while it is
// switched off, which is the point of naming one.
Events::setMonitorId($evMon['eid'], '33337e57');
$evMonCard = Events::card($evMon['eid']) ?? [];
ok($evMonCard['monitor'] === '33337e57', 'an operator reserves the slot for one screen');
$evPre = Events::rowOf($evMon['eid'], '33337e57');
ok($evPre !== null && $evPre['state'] === 'monitor',
    'and naming it is granting it access: it has its row before it scans');
ok(Events::monitorHolder($evMonCard) === '33337e57',
    'which holds it with nothing running at all');
ok(!Events::claimMonitor($evMonCard, '22227e57'), 'and nobody else can take it');
ok(Events::claimMonitor($evMonCard, '33337e57'), 'while the reserved screen always can');

// The reserved screen is a ROW, and that row is not a participant.
ok(Events::admit($evMon['eid'], '33337e57', true, 'key', true) === 'monitor',
    'a reserved screen is admitted as the monitor, closed door or not');
ok(Events::counts($evMon['eid'])['members'] === 1,
    'and is counted as nobody: only the organizer is a participant');
ok(Events::counts($evMon['eid'])['monitor'] === 1, 'it is counted as what it is');
$evMonIds = static fn(bool $all, bool $with = false): array
    => array_column(Events::members($evMon['eid'], $all, $with), 'id');
ok(!in_array('33337e57', $evMonIds(false), true), 'it is in no participant list');
ok(!in_array('33337e57', $evMonIds(true), true), 'not even the organizer sees it there');
ok(in_array('33337e57', $evMonIds(true, true), true),
    'only the operator asks for it by name');

// The AUDIENCE is who a transition is told about, and it is deliberately not
// the roster: a screen absent from every list is still shown what changed.
$evAud = Events::audience($evMon['eid']);
ok(in_array('11117e57', $evAud, true), 'a transition reaches the members');
ok(in_array('33337e57', $evAud, true),
    'and the monitor, which shows the room rather than sitting in it');
ok(count($evAud) === 2, 'and nobody else at all');
$evMonMine = null;
foreach (Events::listFor('33337e57', 5000) as $evRow) {
    if ($evRow['eid'] === $evMon['eid']) {
        $evMonMine = $evRow;
    }
}
ok($evMonMine !== null && $evMonMine['you']['state'] === 'monitor',
    'the screen still reads the event as its own');
ok(isset($evMonMine['members']), 'and is told the figures it exists to show');

// Naming a different screen moves the row with the column, both ways.
Events::admit($evMon['eid'], '22227e57', false, 'key');
Events::setMonitorId($evMon['eid'], '22227e57');
$evWas = Events::rowOf($evMon['eid'], '33337e57');
ok($evWas !== null && $evWas['state'] === 'member',
    'the screen it replaces becomes an ordinary member rather than losing its place');
$evNow = Events::rowOf($evMon['eid'], '22227e57');
ok($evNow !== null && $evNow['state'] === 'monitor',
    'and a member named as the monitor stops being a participant');
ok(Events::counts($evMon['eid'])['members'] === 2,
    'so the count follows the swap: the organizer and the demoted screen');
Events::setMonitorId($evMon['eid'], null);
$evFreed = Events::rowOf($evMon['eid'], '22227e57');
ok($evFreed !== null && $evFreed['state'] === 'member',
    'and clearing the reservation puts that row back too');

// ASKING IS NOT TAKING: whether an event offers a screen rides every
// answer, because the call that would otherwise reveal it takes the lease.
$evAsk = Events::card($evMon['eid']) ?? [];
ok(Events::publicFace($evAsk, 5000)['monitor_allowed'] === true,
    'the public face says whether a monitor is offered');
ok(Events::publicFace(Events::card($evNoMon['eid']) ?? [], 5000)['monitor_allowed'] === false,
    'and says so when one is not');
$evAskList = Events::listFor('22227e57', 5000);
ok(isset($evAskList[0]['monitor_allowed']),
    'and the events list carries it too, which is what shows the menu entry');
if (Caps::apcu()) {
    $evHeldBefore = Events::monitorHolder($evAsk);
    Events::publicFace($evAsk, 5000);
    Events::listFor('22227e57', 5000);
    ok(Events::monitorHolder($evAsk) === $evHeldBefore,
        'and asking left the slot exactly as it found it');
}

// `you` describes the caller's ROW, never the role it is playing. A free
// monitor is a member holding a lease, and every answer has to agree.
$evFreeMon = EventView::monitor($evAsk, '22227e57', 'member', 5000);
ok($evFreeMon['you']['state'] === 'member',
    'a member running the screen still reads as a member');
ok($evFreeMon['reserved'] === false, 'and the slot says it is not reserved');
$evOrgMon = EventView::monitor($evAsk, '11117e57', 'member', 5000);
ok($evOrgMon['you']['organizer'] === true,
    'and an organizer running it does not stop being the organizer');
$evResMon = EventView::monitor($evAsk, '33337e57', 'monitor', 5000);
ok($evResMon['you']['state'] === 'monitor',
    'while a reserved screen reads monitor, because that is what its row says');

// The monitor reads the event, and earns nothing by watching it.
Events::edit($evMon['eid'], ['ach_name' => 'ON AIR']);
$evMonC = Events::card($evMon['eid']) ?? [];
$evSeen = EventView::forCaller($evMonC, '22227e57', 'monitor', 5000);
ok(isset($evSeen['members']), 'a monitor reads the event as a member does');
ok(!isset($evSeen['ach']), 'but is granted no achievement: it was posted, not joined');
ok(isset(EventView::forCaller($evMonC, '22227e57', 'member', 5000)['ach']),
    'while somebody who actually joined is');
// ------------------------------------------------------------------- qr
//
// THE COMPATIBILITY VECTOR. The client draws the live event pass with its own
// fixed encoder (FOK-snake js/qr.js: version 3, level L, mask 0) and the
// server draws the printed key with this one. They are the same mathematics
// written twice, so they are pinned to each other: these rows were produced
// by the CLIENT's encoder for the client's own friend URL, and Qr.php must
// reproduce them module for module. A drift in either one fails here.
$qrText = 'https://poeggi.github.io/FOK-snake/#friend=c0ffee42';
$qrWant = [
    '11111110000110110111001111111',
    '10000010000100111101001000001',
    '10111010101000111001001011101',
    '10111010010110110010001011101',
    '10111010011110011111101011101',
    '10000010001110011011001000001',
    '11111110101010101010101111111',
    '00000000100111001110000000000',
    '11101111100011011110111000100',
    '11011101110010000000111001001',
    '10011010001000100100011100111',
    '00001101111111111111101000010',
    '00111010101011001000011001011',
    '01001001101011001100111001001',
    '10000010101010000100100111011',
    '01001101001011100101000001010',
    '01101011111011011001101001011',
    '00000101001010001000111001101',
    '10000010001011000100110100011',
    '01110001101011010111111011010',
    '10100110100011001000111110000',
    '00000000110011001000100010111',
    '11111110110010001111101011011',
    '10000010101101001110100011001',
    '10111010111001111110111110001',
    '10111010000000101000000110101',
    '10111010101000000000000111001',
    '10000010101001100111100100010',
    '11111110101011000101110011011',
];
$qrGot = Qr::matrix($qrText, 'L', 3, 0);
ok($qrGot['size'] === 29, 'a version 3 code is 29 modules square');
$qrRows = [];
foreach ($qrGot['m'] as $row) {
    $qrRows[] = implode('', array_map(static fn($v) => $v ? '1' : '0', $row));
}
ok($qrRows === $qrWant, 'and the server draws the client encoder module for module');

// Capacity, and the reason the identifiers are the length they are: the pass
// URL is 53 bytes and the client's fixed version 3 at L holds exactly that.
ok(Qr::capacity(3, 'L') === 53, 'version 3 at L holds 53 text bytes, which is the pass budget');
ok(Qr::size(1) === 21 && Qr::size(6) === 41, 'a version is 17 + 4v modules square');
ok(Qr::fit(str_repeat('x', 53), 'L') === 3, 'a 53-byte payload fits version 3 at L');
ok(Qr::fit(str_repeat('x', 54), 'L') === 4, 'and one byte more takes the next version');
ok(Qr::fit(str_repeat('x', 53), 'L') === 3, 'the 53-byte printed URL fits version 3 at L');
ok(Qr::fit(str_repeat('x', 5000), 'M') === 0, 'and a payload past every version fits none');

// The printed code: version 5 at M is TWO Reed-Solomon blocks, which the
// client's single-block encoder cannot exercise at all. Read the matrix back
// the way a scanner does - unmask, follow the same zigzag, de-interleave -
// and the payload must come out again.
$qrUrl = FOK_GAME_URL . '#event=' . str_repeat('K', Events::KEY_LEN);
ok(strlen($qrUrl) === 53,
    'the printed URL is 53 bytes: 42 of prefix and an 11-character key');
ok(strlen($qrUrl) <= Qr::capacity(3, 'L'),
    'which is the whole of what a version 3 code at level L holds');
$qrPrint = Qr::matrix($qrUrl, 'L', 3, 0);
ok($qrPrint['version'] === 3 && $qrPrint['level'] === 'L' && $qrPrint['mask'] === 0,
    'so the poster is the one shape the game\'s own decoder can read');
ok($qrPrint['size'] === 29, 'a 29 by 29 grid, as that decoder samples');
ok(qrReadBack($qrPrint) === $qrUrl, 'and reading the modules back yields the payload again');
$qrPass = FOK_GAME_URL . '#event=K7QM.H3KM9P';
ok(strlen($qrPass) === 53, 'and a pass URL is the same 53 bytes');
ok(Qr::matrix($qrPass, 'L', 3, 0)['version'] === 3,
    'so both codes an event ever shows are version 3');

// The multi-block path is not used by the poster any more, but the encoder
// still generalises over versions and the arithmetic has to stay right.
$qrBig = str_repeat('x', 70);
ok(Qr::fit($qrBig, 'M') === 5, 'a 70-byte payload needs version 5 at M');
ok(qrReadBack(Qr::matrix($qrBig, 'M', 5, 0)) === $qrBig,
    'and its two Reed-Solomon blocks read back interleaved');
ok(qrSyndromesZero(Qr::matrix($qrBig, 'M', 5, 0)),
    'with every syndrome zero');

// The same round trip on the single-block shape, so the reader itself is not
// what is being tested above.
ok(qrReadBack(Qr::matrix($qrText, 'L', 3, 0)) === $qrText,
    'a single-block code reads back too');
ok(qrReadBack(Qr::matrix('FOK', 'M', 1, 3)) === 'FOK', 'and so does the smallest one');

// Every mask must produce a readable code; choosing one is only ever about
// how easy it is to scan.
$qrAllMasks = true;
for ($qrM = 0; $qrM < 8; $qrM++) {
    if (qrReadBack(Qr::matrix($qrUrl, 'L', 3, $qrM)) !== $qrUrl) {
        $qrAllMasks = false;
    }
}
ok($qrAllMasks, 'all eight mask patterns encode the same payload');

// The ECC is what a printed code is for, so it is checked against an
// INDEPENDENT syndrome evaluation rather than the routine that produced it.
ok(qrSyndromesZero(Qr::matrix($qrUrl, 'L', 3, 0)),
    'and every Reed-Solomon syndrome of the printed code is zero');

$qrSvg = Qr::svg($qrUrl, 'L', 3, 0);
ok(str_starts_with($qrSvg, '<svg '), 'the print page gets inline SVG');
ok(str_contains($qrSvg, 'viewBox="0 0 37 37"'), 'sized to the code plus the quiet zone');
ok(str_contains($qrSvg, '<rect'), 'drawn as rects, with no image library anywhere');
ok(substr_count($qrSvg, '<rect') < 29 * 29,
    'one rect per dark run rather than per module');

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
