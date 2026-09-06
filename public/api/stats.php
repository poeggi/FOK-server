<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/PStats.php';

/**
 * Per-player self-reported gameplay stats (contract in docs/API.md).
 *   GET  ?id=              -> {ok, stats:{...}, updated}   zeros if none stored
 *   POST {id, stats:{...}} -> {ok, stats:{...}, updated}
 * Counters are cumulative and stored monotonically (a value never decreases)
 * and hard-capped; the client sends its running totals and reads them back to
 * restore progress on another device. No token: an id-keyed write can only
 * raise a value to its cap, never lower or corrupt it.
 */
Util::cors();
$method = $_SERVER['REQUEST_METHOD'] ?? '';

if ($method === 'GET') {
    $id = $_GET['id'] ?? '';
    if (!Util::isValidId($id)) {
        Util::fail('invalid id');
    }
    Util::noteCaller($id);
    $stats = PStats::get($id);
    $updated = $stats['updated'];
    unset($stats['updated']);
    Util::jsonOut(['ok' => true, 'stats' => $stats, 'updated' => $updated]);
}

if ($method !== 'POST') {
    Util::fail('GET or POST only', 405);
}

$body = Util::jsonBody();
$id = $body['id'] ?? '';
if (!Util::isValidId($id)) {
    Util::fail('invalid id');
}
Util::noteCaller($id);
$in = $body['stats'] ?? null;
if (!is_array($in)) {
    Util::fail('invalid stats');
}
$stats = PStats::submit($id, $in);
$updated = $stats['updated'];
unset($stats['updated']);
Util::bump('stats_submit');
Util::jsonOut(['ok' => true, 'stats' => $stats, 'updated' => $updated]);
