<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Presence.php';
require_once __DIR__ . '/../src/Scores.php';

/**
 * GET (optional ?limit=1..100) -> {"ok": true, "scores": [top entries]}
 * POST {"id", "name", "score", "level", "diff", "seed"?, "inputs"?}
 * seed + inputs are the deterministic replay material; they are stored
 * verbatim for the future server-side sanity check (anti-spoofing).
 */
Util::cors();
$method = $_SERVER['REQUEST_METHOD'] ?? '';

if ($method === 'GET') {
    $limit = (int)($_GET['limit'] ?? FOK_TOP_SCORES);
    if ($limit < 1 || $limit > FOK_TOP_SCORES) {
        $limit = FOK_TOP_SCORES;
    }
    $scores = Scores::top($limit);
    foreach ($scores as &$row) {
        unset($row['id'], $row['validated']);
    }
    Util::jsonOut(['ok' => true, 'scores' => $scores]);
}

if ($method !== 'POST') {
    Util::fail('GET or POST only', 405);
}

$body = Util::jsonBody();
$id = $body['id'] ?? null;
if (!Util::isValidId($id)) {
    Util::fail('invalid id');
}
$score = $body['score'] ?? null;
$level = $body['level'] ?? null;
$diff = $body['diff'] ?? 1;
if (!is_int($score) || $score < 0 || $score > 1000000000) {
    Util::fail('invalid score');
}
if (!is_int($level) || $level < 1 || $level > 99) {
    Util::fail('invalid level');
}
if (!is_int($diff) || $diff < 0 || $diff > 3) {
    Util::fail('invalid diff');
}
$color = $body['color'] ?? 0;
if (!is_int($color) || $color < 0 || $color > 255) {
    Util::fail('invalid color');
}
$shopItems = '{}';
if (isset($body['shopItems'])) {
    if (!is_array($body['shopItems'])) {
        Util::fail('invalid shopItems');
    }
    $shopItems = json_encode((object)$body['shopItems']);
    if ($shopItems === false || strlen($shopItems) > 2048) {
        Util::fail('invalid shopItems');
    }
}
$seed = $body['seed'] ?? null;
if ($seed !== null && (!is_int($seed) || $seed < 0 || $seed > 0xFFFFFFFF)) {
    Util::fail('invalid seed');
}
$inputs = null;
if (isset($body['inputs'])) {
    $inputs = json_encode($body['inputs']);
    if ($inputs === false || strlen($inputs) > 262144) {
        Util::fail('invalid inputs');
    }
}
$name = is_string($body['name'] ?? null) ? $body['name'] : '';

Presence::touch($id, Util::clientIp());
$rank = Scores::submit($id, $name, $score, $level, $diff, $color, $shopItems, $seed, $inputs);
Util::bump('score_submit');

Util::jsonOut(['ok' => true, 'rank' => $rank, 'top' => $rank <= FOK_TOP_SCORES]);
