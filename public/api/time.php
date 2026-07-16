<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';

/**
 * GET -> {"ok":true, "t": <server time in ms>}
 * The clock-sync endpoint: deliberately touches NO database so the
 * response time is as constant as possible. Clients measure the round
 * trip (record local t0, call, record local t1) and derive their offset
 * to the server clock: offset = t + rtt/2 - t1. Take several samples
 * and keep the one with the lowest rtt. This offset is the base of the
 * shared PTS clock (see docs/API.md, "Time synchronization and PTS").
 */
Util::cors();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    Util::fail('GET only', 405);
}

http_response_code(200);
header('Content-Type: application/json');
echo '{"ok":true,"t":' . Util::nowMs() . '}';
