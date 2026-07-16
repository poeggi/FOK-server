<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Presence.php';
require_once __DIR__ . '/../src/Matchmaking.php';

/**
 * Quick match: pair with any waiting player.
 * POST {"id": "8-hex", "action": "seek" | "cancel"}
 *
 * seek (poll at ~1-2 Hz while the user waits):
 *   -> {"ok":true, "waiting":true}                        keep polling
 *   -> {"ok":true, "matched":"<peer>", "role":"offerer"}  create offer + seed
 *   -> {"ok":true, "matched":"<peer>", "role":"answerer"} wait for the offer
 * cancel: leave the queue -> {"ok":true}
 *
 * A seeker that stops polling for 10 s drops out of the queue. After a
 * match, both sides continue with the normal signaling flow.
 */
Util::cors();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Util::fail('POST only', 405);
}

$body = Util::jsonBody();
$id = $body['id'] ?? null;
if (!Util::isValidId($id)) {
    Util::fail('invalid id');
}
$action = $body['action'] ?? '';

Presence::touch($id, Util::clientIp());

if ($action === 'seek') {
    Util::bump('match_seek');
    Util::jsonOut(['ok' => true] + Matchmaking::seek($id));
}
if ($action === 'cancel') {
    Matchmaking::cancel($id);
    Util::jsonOut(['ok' => true]);
}
Util::fail('invalid action');
