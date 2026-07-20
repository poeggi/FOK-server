<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';

/**
 * Reader for the server error log (the file error_log is pinned to in
 * Config.php). Admin-only, surfaced in the dashboard Logs tab. PHP writes
 * this file in the data dir, which is above the docroot and so neither
 * web-served nor reachable over FTP - the admin UI is the only way to read
 * it. Only the tail is read, so a long-lived unrotated log never loads whole.
 */
final class Logs
{
    // A PHP error-log entry opens with a bracketed timestamp
    // ([20-Jul-2026 14:32:01 UTC] ...); a line without one continues the
    // entry above it - typically a stack-trace frame of one exception.
    private const ENTRY_RE = '/^\[\d{2}-\w{3}-\d{4} \d{2}:\d{2}:\d{2}/';

    /**
     * The tail of the log as entries (newest first), plus the true file
     * size and whether the tail was cut short of the whole file.
     */
    public static function tail(int $maxBytes = FOK_LOG_TAIL_BYTES): array
    {
        if (!is_file(FOK_ERROR_LOG)) {
            return ['entries' => [], 'bytes' => 0, 'truncated' => false];
        }
        $size = (int)filesize(FOK_ERROR_LOG);
        $fh = @fopen(FOK_ERROR_LOG, 'rb');
        if ($fh === false) {
            return ['entries' => [], 'bytes' => $size, 'truncated' => false];
        }
        $truncated = $size > $maxBytes;
        if ($truncated) {
            fseek($fh, -$maxBytes, SEEK_END);
        }
        $data = (string)stream_get_contents($fh);
        fclose($fh);
        // A tail that starts mid-entry: drop the leading partial line so the
        // first shown entry is whole.
        if ($truncated) {
            $nl = strpos($data, "\n");
            $data = $nl === false ? '' : substr($data, $nl + 1);
        }
        return [
            'entries' => self::parse($data),
            'bytes' => $size,
            'truncated' => $truncated,
        ];
    }

    private static function parse(string $data): array
    {
        $entries = [];
        foreach (preg_split('/\r?\n/', $data) as $line) {
            if ($line === '') {
                continue;
            }
            if ($entries === [] || preg_match(self::ENTRY_RE, $line) === 1) {
                $entries[] = ['level' => self::level($line), 'text' => $line];
            } else {
                $entries[count($entries) - 1]['text'] .= "\n" . $line;
            }
        }
        // Newest first: the fault being chased is the most recent one.
        return array_reverse($entries);
    }

    // Severity taken from the entry's first line, for the Logs filter.
    // FOK fault = an uncaught exception or fatal (see Util); FOK deferred =
    // a post-response bookkeeping task that threw - both are errors.
    private static function level(string $line): string
    {
        if (preg_match('/Fatal error|Parse error|Uncaught|FOK fault|FOK deferred/i', $line) === 1) {
            return 'error';
        }
        if (preg_match('/Warning|Deprecated|Notice/i', $line) === 1) {
            return 'warn';
        }
        return 'info';
    }

    public static function clear(): void
    {
        if (is_file(FOK_ERROR_LOG)) {
            @file_put_contents(FOK_ERROR_LOG, '');
        }
    }
}
