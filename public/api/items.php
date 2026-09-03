<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Util.php';
require_once __DIR__ . '/../src/Presence.php';
require_once __DIR__ . '/../src/Settings.php';
require_once __DIR__ . '/../src/Items.php';
require_once __DIR__ . '/../src/Ledger.php';
require_once __DIR__ . '/../src/Vault.php';

/**
 * The item registry: server-authoritative ownership of item instances (see
 * docs/API.md and the Items / Ledger classes). One POST endpoint, four
 * actions, always {"id": "8-hex", "action": "..."} plus the action's fields.
 *
 *   list  {id}
 *         -> {ok, items:[{uid, item_id, seq}, ...]}
 *   mint  {id, item_id, origin:"box"|"shop"}
 *         -> {ok, uid, seq}            429 {ok:false} when over the hourly cap
 *   seed  {id, items:["item_id", ...]}   one-time legacy grandfather
 *         -> {ok, items:[{uid, item_id}, ...]}
 *   claim {id, mid, uid, from, to, tick, seq, ws_digest, my_tag, peer_tag?}
 *         -> {ok, seq, state:"confirmed"|"settled"|"held"} on success;
 *            a rejection is {ok:false, error} with a 4xx code, and the
 *            provable-tampering cases (bad peer tag, contradiction, unknown
 *            item) also raise an admin alert and may freeze the instance.
 *
 * Ownership lives in the items table and is decided by primary-key lookup;
 * the hash-chained ledger is written alongside for audit only, never read to
 * answer a claim. Minting stays client-trusted (the coin economy is on the
 * client), so this makes items conserved and auditable, not unforgeable - see
 * the scope boundary in docs/API.md.
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
$action = $body['action'] ?? null;
if (!in_array($action, ['list', 'mint', 'seed', 'claim'], true)) {
    Util::fail('invalid action');
}
Util::bump('items');

/**
 * The ledger grows on every mint and transfer; left alone it would grow
 * without bound. Nothing here runs a daemon, so the trim rides a sampled
 * fraction of the requests that caused the growth (ledger_sample) and is
 * DEFERRED so it never sits in the caller's latency: after the response is
 * flushed, if the chain has passed ledger_max_rows it is checkpointed (a row
 * that folds in a digest of the whole items table) and everything older is
 * deleted. The chain still verifies from that checkpoint forward. See
 * Ledger::checkpoint.
 */
function items_maybe_truncate(): void
{
    if (random_int(1, max(1, Settings::int('ledger_sample'))) !== 1) {
        return;
    }
    Util::defer(static function (): void {
        $db = Db::get();
        if (Ledger::rows($db) > Settings::int('ledger_max_rows')) {
            Ledger::checkpoint($db, Util::nowMs());
        }
    });
}

if ($action === 'list') {
    // A pure read: what this player owns. A uid alone grants nothing without
    // a match and its secrets, so this needs no auth beyond a valid id.
    Util::jsonOut(['ok' => true, 'items' => Items::owned($id)]);
}

if ($action === 'mint') {
    $itemId = $body['item_id'] ?? null;
    if (!Items::isValidItemId($itemId)) {
        Util::fail('invalid item_id');
    }
    $origin = $body['origin'] ?? null;
    if (!in_array($origin, Items::CLIENT_ORIGINS, true)) {
        Util::fail('invalid origin');
    }
    Presence::touch($id, Util::clientIp());
    $res = Items::mint($id, $itemId, $origin);
    if (isset($res['throttled'])) {
        Util::fail('mint rate limit: too many this hour', 429);
    }
    items_maybe_truncate();
    Util::jsonOut(['ok' => true, 'uid' => $res['uid'], 'seq' => $res['seq']]);
}

if ($action === 'seed') {
    $items = $body['items'] ?? null;
    if (!is_array($items)) {
        Util::fail('invalid items');
    }
    Presence::touch($id, Util::clientIp());
    // Prefer the ids the server already holds in the player's config vault
    // (written earlier under a secret token, so a stronger source than the
    // list in this request); fall back to the submitted list otherwise. The
    // amnesty is one-time and idempotent regardless (see Items::seed).
    $owned = Items::seed($id, array_values($items), items_vault_ids($id));
    items_maybe_truncate();
    Util::jsonOut(['ok' => true, 'items' => $owned]);
}

// action === 'claim'
$mid = $body['mid'] ?? null;
$uid = $body['uid'] ?? null;
$from = $body['from'] ?? null;
$to = $body['to'] ?? null;
$tick = $body['tick'] ?? null;
$seq = $body['seq'] ?? null;
$wsDigest = $body['ws_digest'] ?? null;
$myTag = $body['my_tag'] ?? null;
$peerTag = $body['peer_tag'] ?? null;

// Step 1 of the ladder: shape. A body that is not even well-formed is a 400
// and never reaches the registry (see Items::claim for steps 2-8).
if (!Items::isValidUid($mid) || !Items::isValidUid($uid)
    || !Util::isValidId($from) || !Util::isValidId($to)
    || !is_int($tick) || $tick < 0 || $tick > 100000000
    || !is_int($seq) || $seq < 0 || $seq > 1000000000
    || !is_string($wsDigest) || $wsDigest === '' || strlen($wsDigest) > Items::WS_DIGEST_MAX
    || !Items::isValidTag($myTag)) {
    Util::fail('invalid claim');
}
// peer_tag is optional: absent, null or empty means "no peer evidence yet".
// Present, it must be a well-formed tag; a shape-valid but wrong one is
// provable tampering and handled inside the ladder, not here.
if ($peerTag !== null && $peerTag !== '' && !Items::isValidTag($peerTag)) {
    Util::fail('invalid peer_tag');
}
$peerTag = ($peerTag === null || $peerTag === '') ? null : $peerTag;

Presence::touch($id, Util::clientIp());
$res = Items::claim($id, $mid, $uid, $from, $to, $tick, $seq, $wsDigest, $myTag, $peerTag);
if ($res['ok']) {
    items_maybe_truncate();
    Util::jsonOut(['ok' => true, 'seq' => $res['seq'], 'state' => $res['state']]);
}
Util::fail($res['error'], $res['code']);

/**
 * Best-effort extraction of a player's owned item ids from its config vault,
 * for legacy seeding. The vault payload is the client's whole config as an
 * opaque JSON blob; we look only for a top-level "items" array of ids or an
 * "owned" object whose truthy keys are ids (the shapes documented for 4.0).
 * Anything else - no enrolled vault, unparseable, neither shape - returns
 * null, and seeding falls back to the client's submitted list.
 *
 * @return ?list<string>
 */
function items_vault_ids(string $id): ?array
{
    $row = Vault::peek($id);
    if ($row === null || !$row['enrolled']) {
        return null;
    }
    $cfg = json_decode($row['payload'], true);
    if (!is_array($cfg)) {
        return null;
    }
    $out = [];
    if (isset($cfg['items']) && is_array($cfg['items'])) {
        foreach ($cfg['items'] as $it) {
            if (is_string($it)) {
                $out[] = $it;
            }
        }
    }
    if (isset($cfg['owned']) && is_array($cfg['owned'])) {
        foreach ($cfg['owned'] as $k => $v) {
            if (is_string($k) && $v) {
                $out[] = $k;
            } elseif (is_string($v)) {
                $out[] = $v;
            }
        }
    }
    return $out === [] ? null : $out;
}
