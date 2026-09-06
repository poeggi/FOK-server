<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Starts.php';
require_once __DIR__ . '/Items.php';

/**
 * What the database keeps that nothing can read any more.
 *
 * Two jobs in one file on purpose: sweep() deletes and report() counts, and
 * the two have to agree on what "stale" means. Apart they would drift, and
 * the admin card would either promise a cleanup that never runs or stay
 * silent about one that does.
 *
 * The test for anything listed here is that a reader must be UNABLE to
 * reach the row, not merely unlikely to. That rules out the three tables it
 * would be easiest to sweep and wrong to: vault, pstats and items outlive
 * their owner's player row BY DESIGN. An id is minted by the client and
 * kept forever, a player row is only presence, and touch() re-registers an
 * unknown id in silence - so a player who comes back after the TTL took
 * their row still restores their backup, keeps their career stats and finds
 * their wardrobe. report() counts those rows so the operator can see them;
 * nothing deletes them. Scores are history and the ledger is audit, and
 * both stay for the same reason.
 */
final class Housekeeping
{
    /**
     * The hourly reaping (see Util::watch).
     *
     * Every age here is measured in DAYS deliberately. The shortest span an
     * operator can enter is still three orders of magnitude above the
     * windows these rows are read in, so no configuration - not even a
     * careless one - can make a sweep race a duel that is still being
     * played.
     *
     * @return array<string,int> table => rows removed, absent where none
     */
    public static function sweep(): array
    {
        // Runs inline in whichever client request drew the hourly straw, so
        // losing the writer lock here would turn that client's request into
        // a 500. Every statement below is a DELETE, which makes the whole
        // sweep safe to re-run: what a first attempt removed is simply not
        // there for the second.
        return Db::retry(static fn(): array => self::run());
    }

    /** @return array<string,int> */
    private static function run(): array
    {
        $db = Db::get();
        $now = time();
        $nowMs = $now * 1000;
        $out = [];
        // One row per PAIR, written once and only stamped afterwards, so
        // the table grows by a row for every pair that ever played and
        // never shrinks. Everything reading it looks back a minute
        // (Presence::counts, playingOf) or two (Items::matchDeadline, whose
        // no-row fallback is the mint stamp - long expired at this age).
        $days = Settings::int('duel_ttl_days');
        if ($days > 0) {
            $st = $db->prepare('DELETE FROM duels WHERE last_seen < ?');
            $st->execute([$now - $days * 86400]);
            $out['duels'] = $st->rowCount();
        }
        // Read alerts only: an unseen one is a message to the operator that
        // nobody has picked up, and age is not a reason to take it away.
        // The cut is far above alert_cooldown, the only window the alert
        // de-duplication probe looks back over.
        $days = Settings::int('alert_ttl_days');
        if ($days > 0) {
            $st = $db->prepare('DELETE FROM alerts WHERE seen = 1 AND created < ?');
            $st->execute([$now - $days * 86400]);
            $out['alerts'] = $st->rowCount();
        }
        // A settings row whose key no release knows - what a retired tunable
        // leaves behind. Settings::int only ever asks for a DEFS key and the
        // config import rejects everything else, so the row is unreachable:
        // a value the admin card can neither show nor clear. Sweeping it
        // here is what spares every future retirement a migration step of
        // its own.
        $known = array_keys(Settings::DEFS);
        $ph = implode(',', array_fill(0, count($known), '?'));
        $st = $db->prepare("DELETE FROM settings WHERE key NOT IN ($ph)");
        $st->execute($known);
        $out['settings'] = $st->rowCount();
        // Neither row is reachable past its window - a start older than its
        // keep span reads as absent and a match past its deadline refuses
        // every claim - so both belong here rather than on the duel paths
        // that write them, where the delete would share the one transaction
        // every duel waits on (see Starts::request).
        $out['starts'] = Starts::prune($db, $nowMs);
        $out['matches'] = Items::pruneMatches($db, $nowMs);
        return array_filter($out, static fn(int $n): bool => $n > 0);
    }

    /**
     * The Housekeeping card (admin, settings view). Read-only, and asked
     * for by hand rather than polled: it is a scan per table, which is
     * nothing once and would be a standing cost on every dashboard tick.
     *
     * policy says what becomes of the loose rows, and is the whole point of
     * the card: 'reaped' goes on the next sweep, 'kept' is retained on
     * purpose (see the class comment), and 'orphan' should read zero -
     * anything else means a removal path skipped Presence::forget.
     *
     * @return array{db_size:int, tables:list<array{name:string, rows:int, loose:int, policy:string}>}
     */
    public static function report(): array
    {
        $db = Db::get();
        $now = time();
        $duelDays = Settings::int('duel_ttl_days');
        $alertDays = Settings::int('alert_ttl_days');
        $known = array_keys(Settings::DEFS);
        $ph = implode(',', array_fill(0, count($known), '?'));
        $orphan = static fn(string $t, string $col): string =>
            "SELECT COUNT(*) FROM $t WHERE NOT EXISTS "
            . "(SELECT 1 FROM players p WHERE p.id = $t.$col)";
        return [
            'db_size' => is_file(FOK_DB_FILE) ? filesize(FOK_DB_FILE) : 0,
            'tables' => [
                self::line($db, 'duels', 'reaped',
                    $duelDays > 0 ? 'SELECT COUNT(*) FROM duels WHERE last_seen < ?' : null,
                    [$now - $duelDays * 86400]),
                self::line($db, 'alerts', 'reaped',
                    $alertDays > 0 ? 'SELECT COUNT(*) FROM alerts WHERE seen = 1 AND created < ?' : null,
                    [$now - $alertDays * 86400]),
                self::line($db, 'settings', 'reaped',
                    "SELECT COUNT(*) FROM settings WHERE key NOT IN ($ph)", $known),
                // Counted by the classes that own the rule, so the card and
                // the sweep cannot describe different rows.
                self::counted($db, 'starts', 'reaped', Starts::pruneable($db, $now * 1000)),
                self::counted($db, 'matches', 'reaped', Items::pruneableMatches($db, $now * 1000)),
                self::line($db, 'player_nets', 'orphan', $orphan('player_nets', 'id')),
                self::line($db, 'friends', 'orphan',
                    'SELECT COUNT(*) FROM friends WHERE a NOT IN (SELECT id FROM players)
                        OR b NOT IN (SELECT id FROM players)'),
                self::line($db, 'items', 'kept', $orphan('items', 'owner')),
                self::line($db, 'vault', 'kept', $orphan('vault', 'id')),
                self::line($db, 'pstats', 'kept', $orphan('pstats', 'id')),
            ],
        ];
    }

    /**
     * One line of the report: the whole table, and the part of it the
     * policy is about. A null $loose means the policy is switched off (a
     * TTL of 0), which is not the same as nothing to reap.
     * @param list<mixed> $args
     */
    private static function line(PDO $db, string $table, string $policy, ?string $loose, array $args = []): array
    {
        $rows = (int)$db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
        if ($loose === null) {
            return ['name' => $table, 'rows' => $rows, 'loose' => 0, 'policy' => 'off'];
        }
        $st = $db->prepare($loose);
        $st->execute($args);
        $n = (int)$st->fetchColumn();
        $st->closeCursor();
        return ['name' => $table, 'rows' => $rows, 'loose' => $n, 'policy' => $policy];
    }

    /**
     * The same line, where the loose count comes from the class that owns
     * the rule rather than from a WHERE clause repeated here.
     */
    private static function counted(PDO $db, string $table, string $policy, int $loose): array
    {
        $rows = (int)$db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
        return ['name' => $table, 'rows' => $rows, 'loose' => $loose, 'policy' => $policy];
    }
}
