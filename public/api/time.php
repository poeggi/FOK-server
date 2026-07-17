<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';

/**
 * GET -> {"ok":true, "t": <server time in ms>}
 *
 * The FALLBACK clock source, and a free `now` re-check. The primary one
 * is api/t.txt, where Apache stamps the receive time into a header
 * without involving PHP at all; clients prefer that and only come here
 * if they cannot read the header (see docs/API.md, "Time synchronization
 * and PTS", for the sampling procedure).
 *
 * Still worth keeping cheap: it touches NO database, so its response time
 * stays as constant as PHP allows. It cannot match t.txt though - this
 * request queues for a PHP-FPM worker first, and the stamp below is taken
 * after that wait rather than before it.
 */
Util::cors();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    Util::fail('GET only', 405);
}

http_response_code(200);
header('Content-Type: application/json');
echo '{"ok":true,"t":' . Util::nowMs() . '}';
