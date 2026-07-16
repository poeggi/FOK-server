<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Presence.php';
require_once __DIR__ . '/../src/Signals.php';

/**
 * Matchmaking / WebRTC signaling relay.
 * POST {"id": sender, "to": recipient, "type": invite|accept|decline|offer|answer|ice|bye,
 *       "payload": string (SDP/ICE JSON, opaque to the server)}
 * Delivery happens through the recipient's hello.php poll.
 */
Util::cors();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Util::fail('POST only', 405);
}

$body = Util::jsonBody();
$id = $body['id'] ?? null;
$to = $body['to'] ?? null;
$type = $body['type'] ?? null;
$payload = $body['payload'] ?? '';

if (!Util::isValidId($id) || !Util::isValidId($to) || $id === $to) {
    Util::fail('invalid id/to');
}
if (!is_string($type) || !in_array($type, Signals::TYPES, true)) {
    Util::fail('invalid type');
}
if (!is_string($payload) || strlen($payload) > FOK_SIGNAL_MAX_PAYLOAD) {
    Util::fail('invalid payload');
}

Presence::touch($id, Util::clientIp());
Signals::send($id, $to, $type, $payload);
Util::bump('signal');

Util::jsonOut(['ok' => true]);
