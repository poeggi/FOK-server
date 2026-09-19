<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';

/**
 * The word filter (docs/API.md, "Moderation"): what a player writes for
 * others to read - the name on hello, the name on a score - is masked
 * against FOK_WORD_FILTER before it is stored, each hit replaced by
 * asterisks of its length. A mask, never a refusal: a refused heartbeat
 * reads as offline. The list is the operator's; it ships empty.
 */
final class Words
{
    /** @param ?list<string> $list the words, for the tests; the constant otherwise */
    public static function mask(string $text, ?array $list = null): string
    {
        foreach ($list ?? FOK_WORD_FILTER as $word) {
            if ($word === '') {
                continue;
            }
            $text = preg_replace_callback('/' . preg_quote($word, '/') . '/iu',
                static fn(array $m): string => str_repeat('*', mb_strlen($m[0])), $text) ?? $text;
        }
        return $text;
    }
}
