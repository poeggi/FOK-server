<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Ident.php';
require_once __DIR__ . '/../src/Presence.php';
require_once __DIR__ . '/../src/Account.php';

/**
 * The id itself (docs/API.md, "POST /api/account.php", 4.23).
 *   POST {id, tok, action: "delete"}    -> {ok}          the id and everything
 *                                                        about it, gone
 *   POST {id, tok, action: "transfer"}  -> {ok, code, valid}   the old device
 *   POST {action: "claim", code}        -> {ok, id, tok}       the new device
 * delete and transfer take the STRICT proof - a bound id and its token,
 * as the vault does - because the gate's leniency for an id nothing proves
 * yet must not extend to removing or moving one. claim carries no id and no
 * token: the code is the proof, and the answer is what the new device
 * stores. A wrong code is 404 whatever went wrong with it, and counted
 * against the address (429 with retry_after past claim_fails_per_min).
 */
Util::cors();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Util::fail('POST only', 405);
}

$body = Util::jsonBody();
$action = $body['action'] ?? null;
if (!in_array($action, ['delete', 'transfer', 'claim'], true)) {
    Util::fail('invalid action');
}
Util::noteAction($action);
Util::bump('account');
$ip = Util::clientIp();

if ($action === 'claim') {
    if (Account::failsOver($ip)) {
        Util::jsonOut(['ok' => false, 'error' => 'too many attempts', 'retry_after' => 60], 429);
    }
    $code = $body['code'] ?? null;
    if (!is_string($code) || !Account::isCode(strtoupper($code))) {
        Util::fail('invalid code');
    }
    if (!Caps::apcu()) {
        Util::fail('transfer unavailable', 503);
    }
    $r = Account::claim(strtoupper($code), $ip);
    if ($r === null) {
        Util::fail('unknown code', 404);
    }
    Util::jsonOut(['ok' => true, 'id' => $r['id'], 'tok' => $r['tok']]);
}

$id = $body['id'] ?? null;
if (!Util::isValidId($id)) {
    Util::fail('invalid id');
}
Util::noteCaller($id);
[, $tok] = Ident::read($body);
Ident::require($id, $tok, $ip);
if (!Ident::proves($id, $tok)) {
    Util::fail('bad token', 401);
}

if ($action === 'transfer') {
    Presence::touch($id, $ip);
    $r = Account::transfer($id);
    if ($r === null) {
        Util::fail('transfer unavailable', 503);
    }
    Util::jsonOut(['ok' => true, 'code' => $r['code'], 'valid' => $r['valid']]);
}

// delete: on record before the row goes, because the name goes with it.
$name = Presence::namesFor([$id])[$id] ?? '';
$name = $name === '' ? '' : ' ' . $name;
Account::remove($id, true);
Alerts::note('account', "id $id$name deleted by its owner from $ip");
Util::jsonOut(['ok' => true]);
