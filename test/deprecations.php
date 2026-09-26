<?php
declare(strict_types=1);

// Prepended to the unit run and to the smoke's php -S (test/checks.sh).
// A deprecation is no error to PHP, and Config.php sends every notice to a
// log the suite never reads, so this records each one in the file named by
// FOK_DEPRECATION_LOG, where checks.sh fails on a single line. Returning
// false leaves PHP's own handling as it was.
(static function (): void {
    $log = getenv('FOK_DEPRECATION_LOG');
    if (!is_string($log) || $log === '') {
        return;
    }
    error_reporting(E_ALL);
    set_error_handler(static function (int $no, string $msg, string $file, int $line) use ($log): bool {
        file_put_contents($log, "$file:$line $msg\n", FILE_APPEND | LOCK_EX);
        return false;
    }, E_DEPRECATED | E_USER_DEPRECATED);
})();
