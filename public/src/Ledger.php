<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';

/**
 * The item-transfer ledger: append-only, hash-chained, tamper-evident, and
 * truncatable without losing that evidence.
 *
 * It is deliberately NEVER consulted to decide who owns what - that is the
 * items table, a primary-key lookup on the hot path. The ledger is written
 * on a mint or a transfer and read only for audit (the admin card, the
 * chain-verify button). Keeping the two apart is the whole design: a slow
 * append here never sits in a waiting player's latency.
 *
 * Each row's hash chains to the previous row's, back to a genesis of 64
 * zeros. Truncation would break that chain, so it goes through a CHECKPOINT
 * (see checkpoint()): a row whose hash folds in a digest of the entire items
 * table, after which everything older can be deleted. The chain then verifies
 * unbroken from the newest checkpoint forward, and all history before it is
 * attested by that one row's state digest.
 */
final class Ledger
{
    // The chain's anchor: the prev_hash of the very first row.
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    // Field separator inside the hashed pre-image. A single reserved byte the
    // hex/id fields can never contain, so the concatenation is unambiguous.
    private const SEP = '|';

    /**
     * The canonical hash of one ledger row. Includes n (the row's own
     * position), so reordering rows breaks the chain as surely as editing a
     * field. A checkpoint carries its state digest in to_id, so this same
     * function covers checkpoints too - nothing hashes specially.
     */
    public static function rowHash(
        string $prev,
        int $n,
        string $kind,
        string $uid,
        string $from,
        string $to,
        string $mid,
        int $tick,
        int $at
    ): string {
        return hash('sha256', implode(self::SEP, [
            $prev, $n, $kind, $uid, $from, $to, $mid, $tick, $at,
        ]));
    }

    /**
     * Truncated HMAC-SHA256 attestation tag. Each client MACs its lockstep
     * ownership digest with its own per-match secret; the peer's tag becomes
     * evidence of joint observation, the caller's own tag its authentication
     * (see Items::claim, docs/API.md). 64-bit truncation is ample: the tag is
     * bound to (mid, tick, ws_digest), so it cannot be replayed onto another
     * tick or outcome, and standard HMAC withstands the few hundred known
     * pairs a whole match leaks. hex2bin - the secret is 32 hex = 16 raw bytes.
     */
    public static function mac(string $secretHex, string $mid, int $tick, string $wsDigest): string
    {
        $key = @hex2bin($secretHex);
        if ($key === false) {
            // A malformed stored secret can never validate a client tag.
            return '';
        }
        $msg = $mid . self::SEP . $tick . self::SEP . $wsDigest;
        return substr(hash_hmac('sha256', $msg, $key), 0, 16);
    }

    // Constant-time tag check. An empty expected tag (bad secret) never
    // matches a well-formed 16-hex client tag.
    public static function verifyTag(
        string $secretHex,
        string $mid,
        int $tick,
        string $wsDigest,
        string $tag
    ): bool {
        $want = self::mac($secretHex, $mid, $tick, $wsDigest);
        return $want !== '' && hash_equals($want, $tag);
    }

    /**
     * Appends one row, chaining it to the current head. Returns
     * ['n' => int, 'hash' => string, 'prev' => string].
     *
     * n is AUTOINCREMENT, so it is not known until the insert lands; the hash
     * folds n in, so the row is inserted with an empty hash and updated the
     * moment its n is assigned. Both statements run in one transaction: the
     * caller's, when it already holds a BEGIN IMMEDIATE (the claim ladder
     * runs steps 5-7 inside one), or a private one this opens and commits.
     */
    public static function append(
        PDO $db,
        string $kind,
        string $uid,
        string $from,
        string $to,
        string $mid,
        int $tick,
        int $at
    ): array {
        $owns = !$db->inTransaction();
        if ($owns) {
            $db->exec('BEGIN IMMEDIATE');
        }
        try {
            $st = $db->query('SELECT hash FROM ledger ORDER BY n DESC LIMIT 1');
            $head = $st->fetchColumn();
            $st->closeCursor();
            $prev = $head === false ? self::GENESIS : (string)$head;

            $db->prepare(
                "INSERT INTO ledger (kind, uid, from_id, to_id, mid, tick, at, prev_hash, hash)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, '')"
            )->execute([$kind, $uid, $from, $to, $mid, $tick, $at, $prev]);
            $n = (int)$db->lastInsertId();

            $hash = self::rowHash($prev, $n, $kind, $uid, $from, $to, $mid, $tick, $at);
            $db->prepare('UPDATE ledger SET hash = ? WHERE n = ?')->execute([$hash, $n]);

            if ($owns) {
                $db->exec('COMMIT');
            }
            return ['n' => $n, 'hash' => $hash, 'prev' => $prev];
        } catch (Throwable $e) {
            if ($owns && $db->inTransaction()) {
                $db->exec('ROLLBACK');
            }
            throw $e;
        }
    }

    /**
     * A digest of the whole items table as (uid, owner, seq) ordered by uid.
     * Streamed, not concatenated, so a large table never builds a giant
     * string. This is what a checkpoint folds in to attest the state that
     * existed the moment it was written.
     */
    public static function stateDigest(PDO $db): string
    {
        $ctx = hash_init('sha256');
        foreach ($db->query('SELECT uid, owner, seq FROM items ORDER BY uid') as $r) {
            hash_update($ctx, $r['uid'] . self::SEP . $r['owner'] . self::SEP . $r['seq'] . "\n");
        }
        return hash_final($ctx);
    }

    /**
     * Checkpoints the chain and truncates everything older. The checkpoint's
     * hash covers the current head (via prev_hash) AND the state digest, which
     * it carries in to_id so rowHash covers it with no special case. After it
     * lands, DELETE every row before it: the chain still verifies from the
     * checkpoint forward, and all prior history is attested by its digest.
     *
     * One short transaction. The caller gates HOW OFTEN this runs (sampled,
     * deferred - see api/items.php); this only does the work when asked.
     * Returns ['n' => checkpoint n, 'deleted' => rows removed].
     */
    public static function checkpoint(PDO $db, int $at): array
    {
        $owns = !$db->inTransaction();
        if ($owns) {
            $db->exec('BEGIN IMMEDIATE');
        }
        try {
            $digest = self::stateDigest($db);
            $res = self::append($db, 'checkpoint', '', '', $digest, '', 0, $at);
            $n = $res['n'];
            $st = $db->prepare('DELETE FROM ledger WHERE n < ?');
            $st->execute([$n]);
            $deleted = $st->rowCount();
            if ($owns) {
                $db->exec('COMMIT');
            }
            return ['n' => $n, 'deleted' => $deleted];
        } catch (Throwable $e) {
            if ($owns && $db->inTransaction()) {
                $db->exec('ROLLBACK');
            }
            throw $e;
        }
    }

    // The current row count, for the truncation trigger.
    public static function rows(PDO $db): int
    {
        return (int)$db->query('SELECT COUNT(*) FROM ledger')->fetchColumn();
    }

    /**
     * Walks the chain from the newest checkpoint forward (or from genesis if
     * there is none) and confirms every row's hash and its linkage to the row
     * before it. The checkpoint is the trust anchor: its own hash is verified,
     * but its prev_hash is not chased into the truncated past.
     *
     * @return array{ok:bool, from:int, checked:int, break:?int}
     */
    public static function verify(PDO $db): array
    {
        $st = $db->query("SELECT n FROM ledger WHERE kind = 'checkpoint' ORDER BY n DESC LIMIT 1");
        $cp = $st->fetchColumn();
        $st->closeCursor();
        $fromN = $cp === false ? 0 : (int)$cp;

        $rows = $db->prepare(
            'SELECT n, kind, uid, from_id, to_id, mid, tick, at, prev_hash, hash
             FROM ledger WHERE n >= ? ORDER BY n ASC'
        );
        $rows->execute([$fromN]);

        $expectedPrev = null;
        $checked = 0;
        $break = null;
        foreach ($rows as $r) {
            $calc = self::rowHash(
                (string)$r['prev_hash'],
                (int)$r['n'],
                (string)$r['kind'],
                (string)$r['uid'],
                (string)$r['from_id'],
                (string)$r['to_id'],
                (string)$r['mid'],
                (int)$r['tick'],
                (int)$r['at']
            );
            if (!hash_equals((string)$r['hash'], $calc)) {
                $break = (int)$r['n'];
                break;
            }
            // The anchor's predecessor is gone; every later row must link back.
            if ($expectedPrev !== null && !hash_equals($expectedPrev, (string)$r['prev_hash'])) {
                $break = (int)$r['n'];
                break;
            }
            $expectedPrev = (string)$r['hash'];
            $checked++;
        }
        return ['ok' => $break === null, 'from' => $fromN, 'checked' => $checked, 'break' => $break];
    }
}
