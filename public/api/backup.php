<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Ident.php';
require_once __DIR__ . '/../src/Vault.php';

/**
 * Client config backup / restore (contract + payload manifest in docs/API.md).
 *   POST {id, tok, payload}        -> {ok, updated} | 401 bad token
 *   POST {id, tok, restore: true}  -> {ok, payload, updated} | 404 no backup |
 *                                     401 bad token   (4.21)
 *   GET  ?id=&tok=                 -> the same restore, in the form from
 *                                     before 4.21. TEMPORARY(ident): a token
 *                                     on the request line lands in the web
 *                                     server's access log; goes with 5.0
 * Payload is OPAQUE (never parsed), capped at FOK_STATS_MAX. The identity
 * token is what binds a backup to its owner (see Ident): the same one every
 * other request carries, minted by hello. Here alone the gate's leniency
 * for an id nothing proves yet does not reach the data - a bound id's
 * backup is read and replaced by its token and nothing else.
 *
 * TEMPORARY(ident), until the cutoff: `token` is read as an alias of `tok`,
 * and a first backup of an unbound id that carries neither still mints -
 * the vault's own mint from before the identity, now through the one
 * binding path - answering the token as `tok` and as `token`.
 */
Util::cors();
$method = $_SERVER['REQUEST_METHOD'] ?? '';

// TEMPORARY(ident): the vault's old name for the member.
$tokOf = static function (array $src): array {
    if (!array_key_exists('tok', $src) && array_key_exists('token', $src)) {
        $src['tok'] = $src['token'];
    }
    return Ident::read($src);
};

if ($method === 'GET') {
    $id = $_GET['id'] ?? '';
    if (!Util::isValidId($id)) {
        Util::fail('invalid id');
    }
    Util::noteCaller($id);
    [, $tok] = $tokOf($_GET);
    Ident::require($id, $tok, Util::clientIp());
    backup_restore($id, $tok);
}

/** The restore, answered to the POST with `restore` and, until 5.0, the GET. */
function backup_restore(string $id, ?string $tok): never
{
    $res = Vault::restore($id);
    if ($res === null) {
        Util::fail('no backup', 404);
    }
    if (!Ident::proves($id, $tok)) {
        Util::fail('bad token', 401);
    }
    Util::jsonOut(['ok' => true, 'payload' => $res['payload'], 'updated' => $res['updated']]);
}

if ($method !== 'POST') {
    Util::fail('GET or POST only', 405);
}

$body = Util::jsonBody();
$id = $body['id'] ?? '';
if (!Util::isValidId($id)) {
    Util::fail('invalid id');
}
Util::noteCaller($id);
[, $tok] = $tokOf($body);
Ident::require($id, $tok, Util::clientIp());
if (array_key_exists('restore', $body)) {
    if ($body['restore'] !== true) {
        Util::fail('invalid restore');
    }
    backup_restore($id, $tok);
}
$payload = $body['payload'] ?? null;
if (!is_string($payload) || $payload === '') {
    Util::fail('invalid payload');
}
if (strlen($payload) > FOK_STATS_MAX) {
    Util::fail('payload too large', 413);
}
$out = ['ok' => true];
if (!Ident::proves($id, $tok)) {
    // TEMPORARY(ident): a client from before the token - one that sends
    // no `tok` member, whatever it sent as `token` - backing up an id
    // nothing binds. Its own first backup, or its next one after the
    // operator's reset, which is the re-enrolment the old vault offered.
    // A client that speaks 4.20 never mints here: it stores only what a
    // hello answers, so its next hello is what binds the id. Nothing
    // minted means the id is bound to a token this caller does not hold.
    $minted = array_key_exists('tok', $body) ? null : Ident::mintLegacy($id, Util::clientIp());
    if ($minted === null) {
        Util::fail('bad token', 401);
    }
    $out['tok'] = $minted;
    $out['token'] = $minted;
}
$out['updated'] = Vault::backup($id, $payload);
Util::jsonOut($out);
