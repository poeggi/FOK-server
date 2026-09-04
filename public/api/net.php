<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';

/**
 * GET -> {"ok":true, "ip":"...", "family":4|6|0, "net":"..."}
 *
 * What the server sees this request coming from: the address, its family,
 * and the NETWORK key the tournament announce matches on (see Util::ipNet).
 *
 * A field diagnostic, not part of the client contract. "The lobby on my PC
 * is not announced to my phone" has one question behind it that nobody can
 * answer from the outside - do those two devices reach the server on the
 * same network at all - and this answers it by being opened in a browser on
 * each device: same net, the announce should match; different families or a
 * different net (a phone on cellular, or one behind iCloud Private Relay),
 * and no server-side matching was ever going to put them in one room.
 *
 * It reads nothing and writes nothing: an address is told only to whoever
 * it belongs to, which is the one party that could not otherwise see it.
 */
Util::cors();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    Util::fail('GET only', 405);
}

$ip = Util::clientIp();
$info = Util::ipInfo($ip);
Util::jsonOut([
    'ok' => true,
    'ip' => $info['ip'],
    'family' => $info['family'],
    'net' => Util::ipNet($ip),
]);
