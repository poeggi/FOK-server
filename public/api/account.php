<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Ident.php';
require_once __DIR__ . '/../src/Presence.php';
require_once __DIR__ . '/../src/Alerts.php';
require_once __DIR__ . '/../src/Account.php';

/**
 * Deleting the id (docs/API.md, "POST /api/account.php", 4.23).
 *   POST {id, tok, action: "delete"}  -> {ok}   the id and everything about
 *                                              it, gone
 * It takes the STRICT proof - a bound id and its token, as the vault does -
 * because the gate's leniency for an id nothing proves yet must not extend
 * to removing one.
 */
Util::cors();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Util::fail('POST only', 405);
}

$body = Util::jsonBody();
if (($body['action'] ?? null) !== 'delete') {
    Util::fail('invalid action');
}
$id = $body['id'] ?? null;
if (!Util::isValidId($id)) {
    Util::fail('invalid id');
}
Util::noteCaller($id);
Util::noteAction('delete');
$ip = Util::clientIp();
[, $tok] = Ident::read($body);
Ident::require($id, $tok, $ip);
if (!Ident::proves($id, $tok)) {
    Util::fail('bad token', 401);
}
Util::bump('account');

// On record before the row goes, because the name goes with it.
$name = Presence::namesFor([$id])[$id] ?? '';
$name = $name === '' ? '' : ' ' . $name;
Account::remove($id, true);
Alerts::note('account', "id $id$name deleted by its owner from $ip");
Util::jsonOut(['ok' => true]);
