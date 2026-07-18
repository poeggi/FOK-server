<?php
declare(strict_types=1);

// Unit tests for the src/ classes, run against a throwaway data dir.
// No framework: assert() with zend.assertions, exit 1 on any failure.

$tmp = sys_get_temp_dir() . '/fok-test-' . getmypid();
putenv('FOK_DATA_DIR=' . $tmp);
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
foreach (['rank', 'player_id', 'name', 'score', 'level', 'diff', 'color', 'shopItems', 'date', 'created'] as $field) {
    ok(array_key_exists($field, $top[0]), "entry has $field");
}
ok($top[1]['color'] === 5, 'color preserved');
ok(is_object($top[1]['shopItems']) && $top[1]['shopItems']->hat === 1, 'shopItems preserved as object');
ok(preg_match('/^\d{2}\.\d{2}\.\d{2}$/', $top[0]['date']) === 1, 'date is DD.MM.YY');
$long = Scores::submit('aaaaaaaa', str_repeat('X', 40), 1, 1, 1, 0, '{}', null, null);
ok(mb_strlen(Scores::top()[3]['name']) === FOK_MAX_NAME_LEN, 'name capped at max length');

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

// A peer left behind is told so, never handed a start it would misplace.
ok(Starts::request('bbbbbbbb', 'aaaaaaaa', 0, 'first') === null, 'a stale epoch is refused');

$startRow = (function (): array {
    $st = Db::get()->prepare('SELECT epoch, reason FROM starts WHERE a = ? AND b = ?');
    $st->execute(['aaaaaaaa', 'bbbbbbbb']);
    return $st->fetch();
})();
ok((int)$startRow['epoch'] === 1 && $startRow['reason'] === 'respawn', 'the pair records epoch and reason');
ok(in_array('resume', Starts::REASONS, true), 'a resume from pause is a start reason');

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
ok(Starts::request('bbbbbbbb', 'aaaaaaaa', 0, 'first') === null, "a stranger's bye leaves the pair's epoch alone");

// RelayRate: the relay table is drained on delivery, so the send rate is
// tracked as a running total per client. mark_time is pre-set so a full
// slice has already passed and the very next record() checks the rate.
Db::get()->prepare('INSERT INTO relay_rate (id, total, mark_total, mark_time, blocked_until) VALUES (?, ?, ?, ?, 0)')
    ->execute(['dddddddd', 1000, 0, time() - 3]);
RelayRate::record('dddddddd'); // ~334 msg/s over 3 s, far over the 128 default
ok(RelayRate::blocked('dddddddd'), 'a client over the sustained relay rate is blocked');
Db::get()->prepare('INSERT INTO relay_rate (id, total, mark_total, mark_time, blocked_until) VALUES (?, ?, ?, ?, 0)')
    ->execute(['eeeeeeee', 10, 0, time() - 3]);
RelayRate::record('eeeeeeee'); // ~3 msg/s, comfortably under the cap
ok(!RelayRate::blocked('eeeeeeee'), 'a client under the sustained relay rate is not blocked');
ok(!RelayRate::blocked('ffffffff'), 'an unseen client is never blocked');

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
ConnTrack::relaying('aaaaaaaa', 'bbbbbbbb');
ok(duelOf('aaaaaaaa')['mode'] === 'relay', 'relay traffic reports relay without a declaration');
ok(duelOf('aaaaaaaa')['state'] === 'playing', 'relay traffic means the game runs');

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

// ConnTrack: relay admission is counted from the hub traffic a pair
// really caused, not from queued messages (gone the instant the receiver
// drains them) and not from what a client claims.
Db::get()->exec('DELETE FROM conn');
ok(ConnTrack::relayPairs() === 0, 'no relayed pairs on a quiet server');
ok(!ConnTrack::isRelaying('aaaaaaaa', 'bbbbbbbb'), 'idle pair holds no relay slot');
ConnTrack::relaying('aaaaaaaa', 'bbbbbbbb');
ok(ConnTrack::relayPairs() === 1, 'relaying pair counted once');
ok(ConnTrack::isRelaying('aaaaaaaa', 'bbbbbbbb'), 'relaying pair holds its slot');
ok(ConnTrack::isRelaying('bbbbbbbb', 'aaaaaaaa'), 'slot is held from either side');
ConnTrack::relaying('bbbbbbbb', 'aaaaaaaa');
ok(ConnTrack::relayPairs() === 1, 'both directions are still one pair');
Db::get()->prepare('UPDATE conn SET relay_seen = ? WHERE id IN (?, ?)')
    ->execute([time() - FOK_RELAY_WINDOW - 1, 'aaaaaaaa', 'bbbbbbbb']);
ok(ConnTrack::relayPairs() === 0, 'a pair that stopped relaying frees its slot');

// ConnTrack: a DECLARATION must never take a relay slot. accept-relay is
// not friendship-gated, so if a claim counted, a handful of invented
// pairs would deny the relay to everyone.
Db::get()->exec('DELETE FROM conn');
ConnTrack::note('aaaaaaaa', 'bbbbbbbb', 'invite-relay');
ok(duelOf('aaaaaaaa')['mode'] === 'relay', 'declaration is tracked as relay mode');
ok(ConnTrack::relayPairs() === 0, 'a no-p2p declaration takes no relay slot');
ok(!ConnTrack::isRelaying('aaaaaaaa', 'bbbbbbbb'), 'declaring pair holds no slot yet');
ConnTrack::relaying('aaaaaaaa', 'bbbbbbbb');
ok(ConnTrack::relayPairs() === 1, 'real hub traffic takes the slot');

// ConnTrack: bye and decline are not friendship-gated either, so a
// stranger must not be able to end someone else's connection - let alone
// drop the slot of a live relayed duel and get it turned away on resume.
ok(ConnTrack::isRelaying('aaaaaaaa', 'bbbbbbbb'), 'duel is relaying before the stranger');
ConnTrack::note('cccccccc', 'aaaaaaaa', 'bye');
ok(duelOf('aaaaaaaa')['peer'] === 'bbbbbbbb', "a stranger's bye leaves the connection alone");
ok(ConnTrack::isRelaying('aaaaaaaa', 'bbbbbbbb'), "a stranger's bye cannot drop the relay slot");
ConnTrack::playing('aaaaaaaa', 'bbbbbbbb');
ok(ConnTrack::isRelaying('aaaaaaaa', 'bbbbbbbb'), 'the duel heartbeat keeps the relay slot');
ConnTrack::note('bbbbbbbb', 'aaaaaaaa', 'bye');
ok(duelOf('aaaaaaaa')['state'] === 'ended', "the real peer's bye ends it (it lingers)");
ok(!ConnTrack::isRelaying('aaaaaaaa', 'bbbbbbbb'), 'and frees the relay slot at once');
Db::get()->exec('DELETE FROM conn');

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

// Backup: create produces a valid snapshot, restore brings data back
$name = Backup::create();
ok(Backup::isValidName($name), 'backup name has expected format');
ok(is_file(FOK_BACKUP_DIR . '/' . $name), 'backup file exists');
Db::get()->exec('DELETE FROM scores');
ok(Scores::top() === [], 'scores wiped');
Backup::restore(FOK_BACKUP_DIR . '/' . $name);
ok(count(Scores::top()) === 4, 'restore brings scores back');
$bad = $tmp . '/not-a-db';
file_put_contents($bad, 'hello world');
$threw = false;
try {
    Backup::restore($bad);
} catch (RuntimeException $e) {
    $threw = true;
}
ok($threw, 'restore rejects a non-SQLite file');

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
