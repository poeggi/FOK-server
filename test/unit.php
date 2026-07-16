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

// Presence: duel pair is normalized, refresh from either side
Presence::touchDuel('bbbbbbbb', 'aaaaaaaa');
Presence::touchDuel('aaaaaaaa', 'bbbbbbbb');
$c = Presence::counts();
ok($c['playing'] === 2, 'one duel counts two playing');

// Scores: parity with the FOK-snake local top-10 entry shape
$rank = Scores::submit('aaaaaaaa', '  TESTER  ', 100, 3, 2, 5, '{"hat":1}', 42, '[[1,2]]');
ok($rank === 1, 'first score ranks 1');
$rank = Scores::submit('bbbbbbbb', '', 200, 4, 1, 0, '{}', null, null);
ok($rank === 1, 'higher score takes rank 1');
$top = Scores::top();
ok(count($top) === 2, 'two entries');
ok($top[0]['name'] === 'ANONYMOUS', 'empty name becomes ANONYMOUS');
ok($top[1]['name'] === 'TESTER', 'name is trimmed');
foreach (['rank', 'player_id', 'name', 'score', 'level', 'diff', 'color', 'shopItems', 'date', 'created'] as $field) {
    ok(array_key_exists($field, $top[0]), "entry has $field");
}
ok($top[1]['color'] === 5, 'color preserved');
ok(is_object($top[1]['shopItems']) && $top[1]['shopItems']->hat === 1, 'shopItems preserved as object');
ok(preg_match('/^\d{2}\.\d{2}\.\d{2}$/', $top[0]['date']) === 1, 'date is DD.MM.YY');
$long = Scores::submit('aaaaaaaa', str_repeat('X', 40), 1, 1, 1, 0, '{}', null, null);
ok(mb_strlen(Scores::top()[2]['name']) === FOK_MAX_NAME_LEN, 'name capped at max length');

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

// Signals: expired messages are dropped
Db::get()->prepare('INSERT INTO signals (from_id, to_id, type, payload, created) VALUES (?, ?, ?, ?, ?)')
    ->execute(['aaaaaaaa', 'bbbbbbbb', 'invite', 'old', time() - FOK_SIGNAL_TTL - 1]);
ok(Signals::take('bbbbbbbb') === [], 'expired signal not delivered');

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

// Backup: create produces a valid snapshot, restore brings data back
$name = Backup::create();
ok(Backup::isValidName($name), 'backup name has expected format');
ok(is_file(FOK_BACKUP_DIR . '/' . $name), 'backup file exists');
Db::get()->exec('DELETE FROM scores');
ok(Scores::top() === [], 'scores wiped');
Backup::restore(FOK_BACKUP_DIR . '/' . $name);
ok(count(Scores::top()) === 3, 'restore brings scores back');
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
