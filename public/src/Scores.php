<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Util.php';

final class Scores
{
    public static function top(int $limit = FOK_TOP_SCORES): array
    {
        $st = Db::get()->prepare(
            'SELECT id, player_id, name, score, level, diff, validated, created
             FROM scores ORDER BY score DESC, created ASC LIMIT ?'
        );
        $st->execute([$limit]);
        $rows = $st->fetchAll();
        foreach ($rows as $i => &$row) {
            $row['rank'] = $i + 1;
            $row['score'] = (int)$row['score'];
            $row['level'] = (int)$row['level'];
            $row['diff'] = (int)$row['diff'];
            $row['validated'] = (int)$row['validated'];
            $row['created'] = (int)$row['created'];
        }
        return $rows;
    }

    /**
     * Stores a submission. The raw replay material (seed + inputs) is kept
     * verbatim so scores can later be sanity-checked by deterministic
     * re-simulation; until then entries carry validated = 0.
     */
    public static function submit(string $playerId, string $name, int $score, int $level, int $diff, ?int $seed, ?string $inputs): int
    {
        $name = mb_substr(trim($name), 0, FOK_MAX_NAME_LEN);
        if ($name === '') {
            $name = 'ANONYMOUS';
        }
        Db::get()->prepare(
            'INSERT INTO scores (player_id, name, score, level, diff, seed, inputs, validated, created)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)'
        )->execute([$playerId, $name, $score, $level, $diff, $seed, $inputs, time()]);

        $rank = Db::get()->prepare('SELECT COUNT(*) FROM scores WHERE score > ?');
        $rank->execute([$score]);
        return (int)$rank->fetchColumn() + 1;
    }

    public static function delete(int $id): void
    {
        Db::get()->prepare('DELETE FROM scores WHERE id = ?')->execute([$id]);
    }
}
