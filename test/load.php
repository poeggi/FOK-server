<?php
declare(strict_types=1);

/**
 * Capacity probe: what does the server do PER REQUEST as the database
 * grows? Times the database work of the hot endpoints against a throwaway
 * database seeded to the given size. Not part of checks.sh - a measuring
 * tool, and its numbers depend on the machine.
 *
 *   php test/load.php [players] [duels]
 *
 * Anything that grows with the player count is a scaling bug: a heartbeat
 * must cost the same at 100 and at 100000 players.
 *
 * It cannot show the worker ceiling: every long poll holds one PHP-FPM
 * worker for its whole hold, so concurrency is bounded by workers, not by
 * CPU or SQL (see README).
 */

$tmp = sys_get_temp_dir() . '/fok-load-' . getmypid();
putenv('FOK_DATA_DIR=' . $tmp);

require_once __DIR__ . '/../public/src/Util.php';
require_once __DIR__ . '/../public/src/Presence.php';
require_once __DIR__ . '/../public/src/Signals.php';
require_once __DIR__ . '/../public/src/Scores.php';
require_once __DIR__ . '/../public/src/ConnTrack.php';

$players = (int)($argv[1] ?? 5000);
$duels = (int)($argv[2] ?? 50);

$db = Db::get();
$now = time();
echo "seeding $players players, $duels duels ...\n";
$db->exec('BEGIN');
$st = $db->prepare('INSERT OR REPLACE INTO players (id, ip, first_seen, last_seen, hello_count) VALUES (?, ?, ?, ?, 1)');
for ($i = 0; $i < $players; $i++) {
    // A realistic mix: a tenth of the registered base is online now.
    $st->execute([sprintf('%08x', $i), '10.0.0.1', $now - 86400, $now - ($i % 10 === 0 ? 5 : 99999)]);
}
$st = $db->prepare('INSERT OR REPLACE INTO duels (a, b, started, last_seen) VALUES (?, ?, ?, ?)');
for ($i = 0; $i < $duels; $i++) {
    $st->execute([sprintf('%08x', $i * 2), sprintf('%08x', $i * 2 + 1), $now, $now]);
}
$st = $db->prepare('INSERT INTO signals (from_id, to_id, type, payload, created) VALUES (?, ?, ?, ?, ?)');
for ($i = 0; $i < 200; $i++) {
    $st->execute([sprintf('%08x', $i), 'ffffff01', 'ice', 'c', $now]);
}
$st = $db->prepare('INSERT INTO relay (pair, from_id, to_id, payload, created) VALUES (?, ?, ?, ?, ?)');
for ($i = 0; $i < 200; $i++) {
    $st->execute([sprintf('%08x:%08x', $i, $i + 1), sprintf('%08x', $i), 'ffffff01', 'IN:1:up', $now]);
}
for ($i = 0; $i < 200; $i++) {
    Scores::submit(sprintf('%08x', $i), "P$i", $i, 1, 1, 0, '{}', null, null);
}
$db->exec('COMMIT');

/** @param callable $fn */
function bench(string $what, int $reps, callable $fn): void
{
    $t0 = microtime(true);
    for ($i = 0; $i < $reps; $i++) {
        $fn($i);
    }
    $us = (microtime(true) - $t0) * 1e6 / $reps;
    printf("%-34s %8.0f us/op\n", $what, $us);
}

printf("database: %s\n\n", number_format((int)filesize(FOK_DB_FILE) / 1024, 0) . ' KB');

// Every hello does this - it is the single most repeated work on the
// server, so anything here that scans is a scaling bug.
bench('Presence::counts()  [every hello]', 200, static fn() => Presence::counts());
bench('Presence::touch()   [every hello]', 200, static fn(int $i) => Presence::touch(sprintf('%08x', $i), '10.0.0.2'));
bench('Signals::take()     [every hello]', 200, static fn() => Signals::take('eeeeee01'));
bench('Signals::any()      [every poll]', 200, static fn() => Signals::any('eeeeee01'));
bench('ConnTrack::relayPairs() [relay]', 200, static fn() => ConnTrack::relayPairs());
bench('ConnTrack::listOnline() [admin]', 20, static fn() => ConnTrack::listOnline());
bench('Scores::top()       [landing]', 20, static fn() => Scores::top());

Db::close();
foreach (glob($tmp . '/*') ?: [] as $f) {
    if (is_file($f)) {
        unlink($f);
    }
}
@rmdir($tmp);
