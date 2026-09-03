<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Alerts.php';
require_once __DIR__ . '/Util.php';
require_once __DIR__ . '/Ledger.php';

/**
 * Server-authoritative item ownership. An item instance is ONE row in the
 * items table, so ownership is that row - not a client boolean and not a
 * restorable config blob. A transfer MOVES the row (compare-and-swap on its
 * seq); it can never mint one, which is what makes the population conserved
 * and kills backup-restore save-scumming.
 *
 * A transfer is reported by a `claim` carrying two attestation tags (see
 * Ledger::mac): the caller's own, which authenticates it as a participant of
 * the match, and optionally the peer's, which is evidence the other side
 * observed the same ownership state at the same tick. The server never
 * simulates - the ownership digest is opaque to it; its only job is to check
 * that both sides attested to the same one.
 *
 * See docs/API.md (api/items.php) for the wire contract and the scope
 * boundary: minting is still client-trusted (the coin economy is client-side),
 * so this makes items conserved and auditable, not unforgeable.
 */
final class Items
{
    // A catalog id ('crown', 'neon_1'): lowercase, bounded. NOT a uid.
    private const ITEM_ID_RE = '/^[a-z0-9_]{1,32}$/';
    // 32 lowercase hex - a server-minted uid or mid, 16 random bytes.
    private const HEX32_RE = '/^[0-9a-f]{32}$/';
    // 16 lowercase hex - a truncated HMAC tag.
    private const TAG_RE = '/^[0-9a-f]{16}$/';
    // Where a mint may come from. A client may only assert box/shop; legacy
    // is the one-time grandfather and admin is operator-minted. Public so the
    // endpoint validates an origin against the one authoritative list.
    public const CLIENT_ORIGINS = ['box', 'shop'];
    // A ws_digest is an opaque client hash; bound so a claim body stays small.
    // Public for the same reason - the endpoint shape-checks against it.
    public const WS_DIGEST_MAX = 256;
    // Ceiling on how many items one legacy grandfather may seed at once.
    private const SEED_MAX = 128;

    public static function isValidUid(mixed $v): bool
    {
        return is_string($v) && preg_match(self::HEX32_RE, $v) === 1;
    }

    public static function isValidTag(mixed $v): bool
    {
        return is_string($v) && preg_match(self::TAG_RE, $v) === 1;
    }

    public static function isValidItemId(mixed $v): bool
    {
        return is_string($v) && preg_match(self::ITEM_ID_RE, $v) === 1;
    }

    private static function newId(): string
    {
        return bin2hex(random_bytes(16));
    }

    // ---- Matches (minted from Starts where play begins) -------------------

    /**
     * Mints a match for the ordered pair and returns its row. Called from
     * inside the start transaction (see Starts::request), so it inherits the
     * epoch idempotence: the first peer mints, the second reads the mid off
     * the start row. Prunes matches past their claim window on the way - the
     * table holds one row per duel game, so this stays tiny.
     *
     * @return array{mid:string, sec_a:string, sec_b:string}
     */
    public static function openMatch(PDO $db, string $a, string $b, int $now): array
    {
        $db->prepare('DELETE FROM matches WHERE opened < ?')
            ->execute([$now - Settings::int('match_open_max_ms')]);
        $mid = self::newId();
        $secA = self::newId();
        $secB = self::newId();
        $db->prepare(
            'INSERT INTO matches (mid, a, b, opened, closed, sec_a, sec_b)
             VALUES (?, ?, ?, ?, 0, ?, ?)'
        )->execute([$mid, $a, $b, $now, $secA, $secB]);
        return ['mid' => $mid, 'sec_a' => $secA, 'sec_b' => $secB];
    }

    // The caller's own match secret, or '' if the match is gone. Never
    // returns the peer's - a response carries one side's secret only.
    public static function matchSecret(PDO $db, string $mid, bool $callerIsA): string
    {
        if ($mid === '') {
            return '';
        }
        $st = $db->prepare('SELECT sec_a, sec_b FROM matches WHERE mid = ?');
        $st->execute([$mid]);
        $row = $st->fetch();
        $st->closeCursor();
        if ($row === false) {
            return '';
        }
        return $callerIsA ? (string)$row['sec_a'] : (string)$row['sec_b'];
    }

    // Best-effort close, for forensics only (see Starts::forget). Never gates
    // claim acceptance - the server does not reliably learn a match ended.
    public static function closeMatch(PDO $db, string $a, string $b, int $now): void
    {
        $db->prepare('UPDATE matches SET closed = ? WHERE a = ? AND b = ? AND closed = 0')
            ->execute([$now, $a, $b]);
    }

    // ---- Registry reads and mints ----------------------------------------

    /** What a player owns. Public - a uid alone grants nothing without a
     *  match and its secrets. @return list<array{uid:string,item_id:string,seq:int}> */
    public static function owned(string $id): array
    {
        $st = Db::get()->prepare('SELECT uid, item_id, seq FROM items WHERE owner = ? ORDER BY minted');
        $st->execute([$id]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[] = ['uid' => $r['uid'], 'item_id' => $r['item_id'], 'seq' => (int)$r['seq']];
        }
        return $out;
    }

    /**
     * Mints one item to a player (box open or purchase). Client-trusted for
     * now (see the scope boundary in docs/API.md), so rate-limited per hour.
     * Returns ['throttled'=>true] when over the ceiling, else ['uid','seq'].
     *
     * @return array{throttled:bool}|array{uid:string,seq:int}
     */
    public static function mint(string $id, string $itemId, string $origin): array
    {
        $db = Db::get();
        $now = Util::nowMs();
        $hour = gmdate('YmdH');
        $metric = 'mint_' . $id;
        $cap = Settings::int('mint_max_per_hour');
        $db->exec('BEGIN IMMEDIATE');
        try {
            $st = $db->prepare('SELECT value FROM counters WHERE bucket = ? AND metric = ?');
            $st->execute([$hour, $metric]);
            $count = (int)$st->fetchColumn();
            $st->closeCursor();
            if ($count >= $cap) {
                $db->exec('ROLLBACK');
                return ['throttled' => true];
            }
            $db->prepare(
                'INSERT INTO counters (bucket, metric, value) VALUES (?, ?, 1)
                 ON CONFLICT (bucket, metric) DO UPDATE SET value = value + 1'
            )->execute([$hour, $metric]);
            $uid = self::newId();
            $db->prepare(
                'INSERT INTO items (uid, item_id, owner, seq, origin, minted, frozen)
                 VALUES (?, ?, ?, 0, ?, ?, 0)'
            )->execute([$uid, $itemId, $id, $origin, $now]);
            Ledger::append($db, 'mint', $uid, '', $id, '', 0, $now);
            $db->exec('COMMIT');
            // Old per-id mint buckets are pure counters once the hour passes.
            Util::defer(static function () use ($hour): void {
                Db::get()->prepare("DELETE FROM counters WHERE metric LIKE 'mint\\_%' ESCAPE '\\' AND bucket < ?")
                    ->execute([$hour]);
            });
            return ['uid' => $uid, 'seq' => 0];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->exec('ROLLBACK');
            }
            throw $e;
        }
    }

    /**
     * One-time legacy grandfathering: mint instances for what a player
     * already claims to own, origin 'legacy', logged to the ledger as a mint
     * so the amnesty is auditable. Guarded by players.items_seeded and done in
     * a transaction, so a retry after a timeout never double-mints - a client
     * that has already seeded just gets its current wardrobe back.
     *
     * Prefer the server-side vault list where the player enrolled (data we
     * already hold); fall back to the client's submitted ids otherwise, which
     * is a deliberate, one-shot amnesty.
     *
     * @param list<string> $clientItems
     * @param ?list<string> $vaultItems
     * @return list<array{uid:string,item_id:string}>
     */
    public static function seed(string $id, array $clientItems, ?array $vaultItems): array
    {
        $db = Db::get();
        $now = Util::nowMs();
        $db->exec('BEGIN IMMEDIATE');
        try {
            $st = $db->prepare('SELECT items_seeded FROM players WHERE id = ?');
            $st->execute([$id]);
            $seeded = (int)$st->fetchColumn();
            $st->closeCursor();
            if ($seeded === 0) {
                $source = $vaultItems !== null ? $vaultItems : $clientItems;
                $ids = [];
                foreach ($source as $item) {
                    if (self::isValidItemId($item) && !in_array($item, $ids, true)) {
                        $ids[] = $item;
                    }
                    if (count($ids) >= self::SEED_MAX) {
                        break;
                    }
                }
                foreach ($ids as $item) {
                    $uid = self::newId();
                    $db->prepare(
                        'INSERT INTO items (uid, item_id, owner, seq, origin, minted, frozen)
                         VALUES (?, ?, ?, 0, ?, ?, 0)'
                    )->execute([$uid, $item, $id, 'legacy', $now]);
                    Ledger::append($db, 'mint', $uid, '', $id, '', 0, $now);
                }
                $db->prepare('UPDATE players SET items_seeded = 1 WHERE id = ?')->execute([$id]);
            }
            $db->exec('COMMIT');
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->exec('ROLLBACK');
            }
            throw $e;
        }
        $out = [];
        foreach (self::owned($id) as $r) {
            $out[] = ['uid' => $r['uid'], 'item_id' => $r['item_id']];
        }
        return $out;
    }

    // ---- The claim: the one path that moves ownership --------------------

    /**
     * Processes a transfer claim through the verification ladder (see
     * docs/API.md and the spec sections 06-07). Returns either
     *   ['ok'=>true,  'seq'=>int, 'state'=>'confirmed'|'settled'|'held']
     * or
     *   ['ok'=>false, 'code'=>int, 'error'=>string]
     * The endpoint renders the first with jsonOut, the second with fail.
     *
     * Shape (step 1) is checked by the caller; this does steps 2-8.
     */
    public static function claim(
        string $id,
        string $mid,
        string $uid,
        string $from,
        string $to,
        int $tick,
        int $seq,
        string $wsDigest,
        string $myTag,
        ?string $peerTag
    ): array {
        $db = Db::get();
        $now = Util::nowMs();

        // 2. Match exists, is fresh, and names both parties of the claim.
        $st = $db->prepare('SELECT a, b, sec_a, sec_b, opened FROM matches WHERE mid = ?');
        $st->execute([$mid]);
        $m = $st->fetch();
        $st->closeCursor();
        $pair = $m === false ? [] : [$m['a'], $m['b']];
        if ($m === false
            || (int)$m['opened'] <= $now - Settings::int('match_open_max_ms')
            || $from === $to
            || !in_array($id, $pair, true)
            || !in_array($from, $pair, true)
            || !in_array($to, $pair, true)
            || ($id !== $from && $id !== $to)
        ) {
            Alerts::raise('item_out_of_match', "Claim with no open match: uid $uid from player $id");
            return ['ok' => false, 'code' => 400, 'error' => 'item_out_of_match'];
        }

        $callerIsA = $id === $m['a'];
        $mySecret = $callerIsA ? (string)$m['sec_a'] : (string)$m['sec_b'];
        $peerSecret = $callerIsA ? (string)$m['sec_b'] : (string)$m['sec_a'];

        // 3. The caller's own tag authenticates it as this match participant.
        if (!Ledger::verifyTag($mySecret, $mid, $tick, $wsDigest, $myTag)) {
            return ['ok' => false, 'code' => 403, 'error' => 'bad self tag'];
        }

        // 4. The peer's tag, if present, is evidence of joint observation. A
        // packet that ARRIVED cannot have been corrupted into a valid-shaped
        // wrong tag, so an invalid one is provable tampering.
        $peerConfirmed = false;
        if ($peerTag !== null && $peerTag !== '') {
            if (Ledger::verifyTag($peerSecret, $mid, $tick, $wsDigest, $peerTag)) {
                $peerConfirmed = true;
            } else {
                self::freeze($db, $uid);
                Alerts::raise('item_tag_invalid', "Invalid attestation tag: uid $uid from player $id");
                self::bumpClaim($id, 'claims_disputed');
                return ['ok' => false, 'code' => 409, 'error' => 'tag invalid'];
            }
        }

        // from == caller means "I lost it" - settle immediately, nobody lies
        // to lose an item. Otherwise a valid peer tag is what settles now.
        $iAmLoser = ($from === $id);
        $settleNow = $peerConfirmed || $iAmLoser;

        // Steps 5-8 are one transaction.
        $db->exec('BEGIN IMMEDIATE');
        try {
            // Idempotent replay: this exact transfer already settled. A client
            // that re-sends after settling must not read as counterfeit.
            $st = $db->prepare(
                "SELECT 1 FROM ledger WHERE mid = ? AND uid = ? AND tick = ?
                 AND from_id = ? AND to_id = ? AND kind = 'transfer' LIMIT 1"
            );
            $st->execute([$mid, $uid, $tick, $from, $to]);
            $alreadyDone = $st->fetchColumn() !== false;
            $st->closeCursor();

            $st = $db->prepare('SELECT owner, seq, frozen FROM items WHERE uid = ?');
            $st->execute([$uid]);
            $it = $st->fetch();
            $st->closeCursor();

            if ($alreadyDone) {
                $db->exec('COMMIT');
                self::bumpClaim($id, $peerConfirmed ? 'claims_ok' : 'claims_untagged');
                return ['ok' => true, 'seq' => $it === false ? $seq : (int)$it['seq'], 'state' => 'confirmed'];
            }
            if ($it === false) {
                $db->exec('COMMIT');
                Alerts::raise('item_counterfeit', "Claim on unknown item: uid $uid from player $id");
                return ['ok' => false, 'code' => 409, 'error' => 'no such item'];
            }
            if ((int)$it['frozen'] === 1) {
                $db->exec('COMMIT');
                return ['ok' => false, 'code' => 409, 'error' => 'item frozen'];
            }

            // 8. Contradiction: an entry for the same (mid, uid, tick) that
            // asserts a DIFFERENT direction. Impossible in an honest game -
            // one simulation moment has one outcome - so it is tampering.
            $st = $db->prepare(
                "SELECT from_id, to_id FROM ledger WHERE mid = ? AND uid = ? AND tick = ?
                 AND kind IN ('transfer', 'dispute')"
            );
            $st->execute([$mid, $uid, $tick]);
            $prior = $st->fetchAll();
            $st->closeCursor();
            foreach ($prior as $p) {
                if ((string)$p['from_id'] !== $from || (string)$p['to_id'] !== $to) {
                    self::freezeInTx($db, $uid);
                    $db->exec('COMMIT');
                    Alerts::raise('item_contradiction', "Contradictory claims: uid $uid from player $id");
                    self::bumpClaim($id, 'claims_disputed');
                    return ['ok' => false, 'code' => 409, 'error' => 'contradiction'];
                }
            }

            // 5. The item must be owned by `from` at the claimed seq.
            if ((string)$it['owner'] !== $from) {
                $db->exec('COMMIT');
                Alerts::raise('item_counterfeit', "Claim names a non-owner: uid $uid from player $id");
                return ['ok' => false, 'code' => 409, 'error' => 'counterfeit'];
            }
            if ((int)$it['seq'] !== $seq) {
                $db->exec('COMMIT');
                return ['ok' => false, 'code' => 409, 'error' => 'stale seq, re-read'];
            }

            // The direction rule: a gain claim with no proof holds unconfirmed
            // and settles only once claim_grace_ms has passed with no
            // contradiction. The first hold's timestamp starts that clock.
            if (!$settleNow) {
                $grace = Settings::int('claim_grace_ms');
                $st = $db->prepare(
                    "SELECT MIN(at) FROM ledger WHERE mid = ? AND uid = ? AND tick = ?
                     AND from_id = ? AND to_id = ? AND kind = 'dispute'"
                );
                $st->execute([$mid, $uid, $tick, $from, $to]);
                $firstHold = $st->fetchColumn();
                $st->closeCursor();
                $aged = $firstHold !== false && $firstHold !== null && (int)$firstHold <= $now - $grace;
                if (!$aged) {
                    if ($firstHold === false || $firstHold === null) {
                        Ledger::append($db, 'dispute', $uid, $from, $to, $mid, $tick, $now);
                    }
                    $db->exec('COMMIT');
                    self::bumpClaim($id, 'claims_untagged');
                    return ['ok' => true, 'seq' => (int)$it['seq'], 'state' => 'held'];
                }
                // aged past grace with no contradiction: fall through to settle.
            }

            // 6. Conservative move: compare-and-swap on seq. A rowcount of 0
            // means another claim won the race; the client must re-read.
            $st = $db->prepare('UPDATE items SET owner = ?, seq = seq + 1 WHERE uid = ? AND seq = ?');
            $st->execute([$to, $uid, $seq]);
            if ($st->rowCount() === 0) {
                $db->exec('COMMIT');
                return ['ok' => false, 'code' => 409, 'error' => 'lost race, re-read'];
            }
            // 7. Chain the transfer into the ledger.
            Ledger::append($db, 'transfer', $uid, $from, $to, $mid, $tick, $now);
            $db->exec('COMMIT');
            self::bumpClaim($id, $peerConfirmed ? 'claims_ok' : 'claims_untagged');
            return ['ok' => true, 'seq' => $seq + 1, 'state' => $peerConfirmed ? 'confirmed' : 'settled'];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->exec('ROLLBACK');
            }
            throw $e;
        }
    }

    // ---- small helpers ---------------------------------------------------

    private static function freeze(PDO $db, string $uid): void
    {
        $db->prepare('UPDATE items SET frozen = 1 WHERE uid = ?')->execute([$uid]);
    }

    // Same, but the caller already holds a transaction.
    private static function freezeInTx(PDO $db, string $uid): void
    {
        $db->prepare('UPDATE items SET frozen = 1 WHERE uid = ?')->execute([$uid]);
    }

    // A per-player claim tally, for statistical review on the admin card. The
    // column name is a fixed literal from a closed set, never client input.
    private static function bumpClaim(string $id, string $column): void
    {
        if (!in_array($column, ['claims_ok', 'claims_untagged', 'claims_disputed'], true)) {
            return;
        }
        Db::get()->prepare("UPDATE players SET $column = $column + 1 WHERE id = ?")->execute([$id]);
    }
}
