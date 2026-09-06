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

    // One predicate for the sweep and for the card that promises it; they
    // drift apart the moment there are two (see Housekeeping).
    private const MATCH_PRUNE_WHERE =
        'FROM matches WHERE opened < ?
          AND NOT EXISTS (SELECT 1 FROM duels d
                          WHERE ((d.a = matches.a AND d.b = matches.b)
                              OR (d.a = matches.b AND d.b = matches.a))
                            AND d.last_seen > ?)';

    /**
     * Mints a match for the ordered pair and returns its row. Called from
     * inside the start transaction (see Starts::request), so it inherits the
     * epoch idempotence: the first peer mints, the second reads the mid off
     * the start row.
     *
     * @return array{mid:string, sec_a:string, sec_b:string}
     */
    public static function openMatch(PDO $db, string $a, string $b, int $now): array
    {
        $mid = self::newId();
        $secA = self::newId();
        $secB = self::newId();
        $db->prepare(
            'INSERT INTO matches (mid, a, b, opened, closed, sec_a, sec_b)
             VALUES (?, ?, ?, ?, 0, ?, ?)'
        )->execute([$mid, $a, $b, $now, $secA, $secB]);
        return ['mid' => $mid, 'sec_a' => $secA, 'sec_b' => $secB];
    }

    /**
     * Drops matches no claim could reach any more (see matchDeadline): past
     * the mint window AND with no duel still alive to extend it. A running
     * duel is what holds its own match row here.
     *
     * Hourly, and never on the mint path: this is a correlated DELETE across
     * two tables, and the transaction that mints a match is the one every
     * duel in the game waits behind. The table grows by one row per duel
     * game, which the hour keeps just as small (see Housekeeping::sweep).
     */
    public static function pruneMatches(PDO $db, int $nowMs): int
    {
        $cut = $nowMs - self::windowMs();
        $st = $db->prepare('DELETE ' . self::MATCH_PRUNE_WHERE);
        $st->execute([$cut, intdiv($cut, 1000)]);
        return $st->rowCount();
    }

    /** What the next pruneMatches() would take, for the Housekeeping card. */
    public static function pruneableMatches(PDO $db, int $nowMs): int
    {
        $cut = $nowMs - self::windowMs();
        $st = $db->prepare('SELECT COUNT(*) ' . self::MATCH_PRUNE_WHERE);
        $st->execute([$cut, intdiv($cut, 1000)]);
        $n = (int)$st->fetchColumn();
        $st->closeCursor();
        return $n;
    }

    /**
     * How long a match outlives the last sign of life from its duel: the
     * window in which the duel still counts as running (FOK_DUEL_WINDOW, the
     * same one the Duels card and the online counts use) plus the operator's
     * grace on top. Both peers heartbeat this pair every 30 s, so the grace
     * is what a claim gets AFTER the duel is over, not a budget the duel
     * itself has to fit inside.
     */
    private static function windowMs(): int
    {
        return FOK_DUEL_WINDOW * 1000 + Settings::int('match_open_max_ms');
    }

    /**
     * The moment this match stops accepting claims, in ms.
     *
     * Measured from the DUEL, not from the mint: one match spans every level
     * of a duel (see Starts::request), so a window measured from the mint is
     * a cap on how long a duel may last before its items freeze. The duel
     * heartbeat is what says the game is still being played; the mint stands
     * in until the first heartbeat carrying this pair arrives.
     *
     * The duel row is keyed on the ordered pair (see Presence::touchDuel);
     * a match keeps the offerer/answerer order it was minted with.
     */
    private static function matchDeadline(PDO $db, string $a, string $b, int $opened): int
    {
        [$da, $db2] = $a < $b ? [$a, $b] : [$b, $a];
        $st = $db->prepare('SELECT last_seen FROM duels WHERE a = ? AND b = ?');
        $st->execute([$da, $db2]);
        $seen = $st->fetchColumn();
        $st->closeCursor();
        $alive = $seen === false ? $opened : max($opened, (int)$seen * 1000);
        return $alive + self::windowMs();
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
        // The guard is read before the lock. Every launch of an enrolled
        // client asks again, and all but the very first have nothing to do -
        // so only a real first seed queues for the writer, and the rest are a
        // read on a table nobody is blocked by.
        if (self::seeded($db, $id)) {
            return self::wardrobe($id);
        }
        $now = Util::nowMs();
        $db->exec('BEGIN IMMEDIATE');
        try {
            // Read again under the lock. The unlocked look only established
            // that there was work to do; two launches arriving together would
            // otherwise both mint the same amnesty.
            if (!self::seeded($db, $id)) {
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
        return self::wardrobe($id);
    }

    // Has this player's one-time amnesty already been granted?
    private static function seeded(PDO $db, string $id): bool
    {
        $st = $db->prepare('SELECT items_seeded FROM players WHERE id = ?');
        $st->execute([$id]);
        $seeded = (int)$st->fetchColumn();
        $st->closeCursor();
        return $seeded !== 0;
    }

    /**
     * What seed() answers with either way: the player's wardrobe as the seed
     * reply shapes it (see docs/API.md).
     *
     * @return list<array{uid:string,item_id:string}>
     */
    private static function wardrobe(string $id): array
    {
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

        // 2. Match exists, names both parties of the claim, and its duel has
        // not been over long enough for the window to close.
        $st = $db->prepare('SELECT a, b, sec_a, sec_b, opened FROM matches WHERE mid = ?');
        $st->execute([$mid]);
        $m = $st->fetch();
        $st->closeCursor();
        $pair = $m === false ? [] : [$m['a'], $m['b']];
        if ($m === false
            || $from === $to
            || !in_array($id, $pair, true)
            || !in_array($from, $pair, true)
            || !in_array($to, $pair, true)
            || ($id !== $from && $id !== $to)
        ) {
            Alerts::raise('item_out_of_match', "Claim with no open match: uid $uid from player $id");
            return ['ok' => false, 'code' => 400, 'error' => 'item_out_of_match'];
        }
        // A closed window is the ordinary end of a match, not an anomaly, so
        // it answers the same way but raises nothing: the operator is told
        // about claims that name no match of theirs, not about late ones.
        if (self::matchDeadline($db, (string)$m['a'], (string)$m['b'], (int)$m['opened']) <= $now) {
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
                self::freezeDisputed($db, $uid, $id);
                Alerts::raise('item_tag_invalid', "Invalid attestation tag: uid $uid from player $id");
                return ['ok' => false, 'code' => 409, 'error' => 'tag invalid'];
            }
        }

        // from == caller means "I lost it" - settle immediately, nobody lies
        // to lose an item. Otherwise a valid peer tag is what settles now.
        $iAmLoser = ($from === $id);
        $settleNow = $peerConfirmed || $iAmLoser;

        // Steps 5-8 decide from reads, and in WAL a reader never blocks and is
        // never blocked - so they run HERE, outside the write lock. A replay,
        // an unknown item, a frozen one, a counterfeit or a stale seq is
        // answered without ever asking for a lock that spans the whole
        // database and parks every other writer on the box behind it. Only a
        // claim that has decided to WRITE opens the transaction below, and
        // what it re-reads in there is the one check whose answer can change
        // while the lock is being taken.
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

        // Idempotent replay: this exact transfer already settled. A client
        // that re-sends after settling must not read as counterfeit. The
        // tally is deliberately NOT touched: the transaction that settled the
        // transfer counted this claim, and a retrying client must not be able
        // to inflate its own claim statistics by asking twice. It also costs
        // no writer lock, which is what a replay should cost.
        if ($alreadyDone) {
            return ['ok' => true, 'seq' => $it === false ? $seq : (int)$it['seq'], 'state' => 'confirmed'];
        }
        if ($it === false) {
            Alerts::raise('item_counterfeit', "Claim on unknown item: uid $uid from player $id");
            return ['ok' => false, 'code' => 409, 'error' => 'no such item'];
        }
        if ((int)$it['frozen'] === 1) {
            return ['ok' => false, 'code' => 409, 'error' => 'item frozen'];
        }

        // 8. Contradiction: an entry for the same (mid, uid, tick) that
        // asserts a DIFFERENT direction. Impossible in an honest game -
        // one simulation moment has one outcome - so it is tampering.
        if (self::contradicted($db, $mid, $uid, $tick, $from, $to)) {
            self::freezeDisputed($db, $uid, $id);
            Alerts::raise('item_contradiction', "Contradictory claims: uid $uid from player $id");
            return ['ok' => false, 'code' => 409, 'error' => 'contradiction'];
        }

        // 5. The item must be owned by `from` at the claimed seq.
        if ((string)$it['owner'] !== $from) {
            Alerts::raise('item_counterfeit', "Claim names a non-owner: uid $uid from player $id");
            return ['ok' => false, 'code' => 409, 'error' => 'counterfeit'];
        }
        if ((int)$it['seq'] !== $seq) {
            return ['ok' => false, 'code' => 409, 'error' => 'stale seq, re-read'];
        }

        // The direction rule: a gain claim with no proof holds unconfirmed
        // and settles only once claim_grace_ms has passed with no
        // contradiction. The first hold's timestamp starts that clock.
        if (!$settleNow) {
            $firstHold = self::firstHold($db, $mid, $uid, $tick, $from, $to);
            $aged = $firstHold !== null && $firstHold <= $now - Settings::int('claim_grace_ms');
            if (!$aged) {
                if ($firstHold === null) {
                    // Opening the hold is the only write on this path, so it
                    // is the only thing that takes the lock. It looks again
                    // inside: two unconfirmed claims arriving together would
                    // otherwise each open a hold of their own.
                    $db->exec('BEGIN IMMEDIATE');
                    try {
                        if (self::firstHold($db, $mid, $uid, $tick, $from, $to) === null) {
                            Ledger::append($db, 'dispute', $uid, $from, $to, $mid, $tick, $now);
                        }
                        self::bumpClaim($id, 'claims_untagged');
                        $db->exec('COMMIT');
                    } catch (Throwable $e) {
                        if ($db->inTransaction()) {
                            $db->exec('ROLLBACK');
                        }
                        throw $e;
                    }
                } else {
                    // The hold is already open, so this is a repeat poll on
                    // it and the tally is the only write. Alone it has no
                    // transaction to ride, and a lost race for the writer
                    // must not fail a claim that was otherwise accepted.
                    Db::retry(static function () use ($id): void {
                        self::bumpClaim($id, 'claims_untagged');
                    });
                }
                return ['ok' => true, 'seq' => (int)$it['seq'], 'state' => 'held'];
            }
            // aged past grace with no contradiction: fall through to settle.
        }

        // The write, and the only place a settling claim takes the lock.
        $db->exec('BEGIN IMMEDIATE');
        try {
            // The contradiction check runs again here, and this is the run
            // that decides: the look outside cannot see a contradicting claim
            // that is committing at that very moment, and a serialised look
            // is the whole point of the check. One indexed lookup, on a path
            // that is about to write anyway.
            if (self::contradicted($db, $mid, $uid, $tick, $from, $to)) {
                self::freezeInTx($db, $uid);
                // The tally rides the same transaction as the freeze it
                // records: a verdict and its accounting are one fact, and
                // taking the writer a second time for one column is a lock the
                // whole database would queue behind.
                self::bumpClaim($id, 'claims_disputed');
                $db->exec('COMMIT');
                Alerts::raise('item_contradiction', "Contradictory claims: uid $uid from player $id");
                return ['ok' => false, 'code' => 409, 'error' => 'contradiction'];
            }

            // 6. Conservative move: compare-and-swap on seq, and on the owner
            // and the freeze flag with it. Those three were read outside the
            // lock, so this swap - not that read - is what arbitrates: a
            // snapshot that went stale in between matches no row, and the
            // client is told to re-read, which is the answer the loser of a
            // race has always been given.
            $st = $db->prepare(
                'UPDATE items SET owner = ?, seq = seq + 1
                 WHERE uid = ? AND seq = ? AND owner = ? AND frozen = 0'
            );
            $st->execute([$to, $uid, $seq, $from]);
            if ($st->rowCount() === 0) {
                $db->exec('COMMIT');
                return ['ok' => false, 'code' => 409, 'error' => 'lost race, re-read'];
            }
            // 7. Chain the transfer into the ledger.
            Ledger::append($db, 'transfer', $uid, $from, $to, $mid, $tick, $now);
            // The claim tally rides the same transaction: adding one to a
            // statistics column is not worth its own turn at a lock the whole
            // database queues behind.
            self::bumpClaim($id, $peerConfirmed ? 'claims_ok' : 'claims_untagged');
            $db->exec('COMMIT');
            return ['ok' => true, 'seq' => $seq + 1, 'state' => $peerConfirmed ? 'confirmed' : 'settled'];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->exec('ROLLBACK');
            }
            throw $e;
        }
    }

    // ---- small helpers ---------------------------------------------------

    // Freezes an instance. Every caller holds a transaction: a freeze is a
    // verdict on tampering, and the tally that records it has to land with it
    // or not at all.
    private static function freezeInTx(PDO $db, string $uid): void
    {
        $db->prepare('UPDATE items SET frozen = 1 WHERE uid = ?')->execute([$uid]);
    }

    /**
     * The tampering verdict reached OUTSIDE the settling transaction: freeze
     * the instance and count the dispute against the claimant, in one
     * transaction. Two writes that describe one finding, so they take the
     * writer once between them - and a freeze with no tally behind it would
     * be a finding the admin card cannot show.
     *
     * Re-runnable as a whole: a BUSY rolls the transaction back before the
     * increment, so a retry cannot count the same dispute twice. The alert
     * belongs AFTER the commit - it is a report of what happened, not part
     * of it.
     */
    private static function freezeDisputed(PDO $db, string $uid, string $id): void
    {
        Db::retry(static function () use ($db, $uid, $id): void {
            $db->exec('BEGIN IMMEDIATE');
            try {
                self::freezeInTx($db, $uid);
                self::bumpClaim($id, 'claims_disputed');
                $db->exec('COMMIT');
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->exec('ROLLBACK');
                }
                throw $e;
            }
        });
    }

    // Is there a prior entry for this (mid, uid, tick) that asserts a
    // DIFFERENT direction than the claim being judged? Index-backed on
    // ledger_match. Called twice per settling claim on purpose: once outside
    // the write lock, where it answers the ordinary case for free, and once
    // inside, where it is the only look that can see a claim committing
    // alongside this one.
    private static function contradicted(
        PDO $db,
        string $mid,
        string $uid,
        int $tick,
        string $from,
        string $to
    ): bool {
        $st = $db->prepare(
            "SELECT from_id, to_id FROM ledger WHERE mid = ? AND uid = ? AND tick = ?
             AND kind IN ('transfer', 'dispute')"
        );
        $st->execute([$mid, $uid, $tick]);
        foreach ($st->fetchAll() as $p) {
            if ((string)$p['from_id'] !== $from || (string)$p['to_id'] !== $to) {
                return true;
            }
        }
        return false;
    }

    // When the unconfirmed hold on this exact transfer was opened, or null if
    // no hold has been. The earliest one is what starts the grace clock.
    private static function firstHold(
        PDO $db,
        string $mid,
        string $uid,
        int $tick,
        string $from,
        string $to
    ): ?int {
        $st = $db->prepare(
            "SELECT MIN(at) FROM ledger WHERE mid = ? AND uid = ? AND tick = ?
             AND from_id = ? AND to_id = ? AND kind = 'dispute'"
        );
        $st->execute([$mid, $uid, $tick, $from, $to]);
        $at = $st->fetchColumn();
        $st->closeCursor();
        return $at === false || $at === null ? null : (int)$at;
    }

    // A per-player claim tally, for statistical review on the admin card. The
    // column name is a fixed literal from a closed set, never client input.
    // No transaction and no retry of its own: a tally belongs to the write it
    // describes, so every caller either already holds that transaction or
    // wraps this one call in Db::retry.
    private static function bumpClaim(string $id, string $column): void
    {
        if (!in_array($column, ['claims_ok', 'claims_untagged', 'claims_disputed'], true)) {
            return;
        }
        Db::get()->prepare("UPDATE players SET $column = $column + 1 WHERE id = ?")->execute([$id]);
    }
}
