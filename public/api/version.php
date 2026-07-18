<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';

/**
 * GET -> {"ok":true, "server":"x.y.z", "api":"maj.min", "env":"live"|"staging"}
 * server is the implementation version (every release), api the contract
 * version as MAJOR.MINOR (see docs/API.md Versioning). Clients check api at
 * startup and disable online features when the server's MAJOR is newer than
 * theirs, instead of misbehaving; a newer MINOR stays compatible.
 */
Util::cors();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    Util::fail('GET only', 405);
}

Util::jsonOut([
    'ok' => true,
    'server' => FOK_SERVER_VERSION,
    'api' => FOK_API_VERSION,
    'env' => FOK_ENV,
]);
