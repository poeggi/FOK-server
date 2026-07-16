<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';

/**
 * GET -> {"ok":true, "server":"x.y.z", "api":n, "env":"live"|"staging"}
 * server is the implementation version (every release), api the contract
 * version (breaking changes only). Clients check api once at startup and
 * disable online features on a mismatch instead of misbehaving.
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
