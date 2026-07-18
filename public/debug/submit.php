<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Debug.php';

/**
 * Submit a debug dataset (see docs/API.md).
 *   POST <JSON bundle>  ->  200 {"ok": true, "pin": "0042"}
 *
 * The bundle is a JSON object the client structures - logs, debug info, and
 * up to two image snapshots (as data-URI / base64 strings). Stored VERBATIM,
 * capped at FOK_DEBUG_MAX (8 MB), kept a month, then pruned. The returned PIN
 * is the handle a user reads out to support; retrieval is admin-only (never
 * on this endpoint).
 */
Util::cors();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Util::fail('POST only', 405);
}
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > FOK_DEBUG_MAX) {
    Util::fail('dataset too large', 413);
}
$raw = (string)file_get_contents('php://input', false, null, 0, FOK_DEBUG_MAX + 1);
if (strlen($raw) > FOK_DEBUG_MAX) {
    Util::fail('dataset too large', 413);
}
if ($raw === '' || !is_array(json_decode($raw, true))) {
    Util::fail('dataset must be a non-empty JSON object');
}
try {
    $pin = Debug::submit($raw);
} catch (RuntimeException $e) {
    Util::fail('debug store busy, try again', 503);
}
Util::jsonOut(['ok' => true, 'pin' => $pin]);
