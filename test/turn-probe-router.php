<?php
declare(strict_types=1);

// The router for the TURN probe's php -S: serves test/turn-probe.html at
// /turn-probe from the same origin as the API, so the page's fetches are
// same-origin and the credential never leaves it. Everything else is the
// docroot as usual (public/, the -t argument).
$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/turn-probe') {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    readfile(__DIR__ . '/turn-probe.html');
    return true;
}
return false;
