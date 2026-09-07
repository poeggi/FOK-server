# Verify on the real host

When a change depends on something only the host can answer, ship a small
probe first and read the answer - never design on the assumption. Probe
first, then build; not build it all and verify at the end.

php -S on a dev box cannot answer .htaccess, PHP-extension or file-IO
questions: php -S ignores .htaccess entirely (so mod_headers behavior is
invisible locally), extension availability differs (APCu), and file-IO
timings measured on a Windows dev box are meaningless for the host's
Linux.

Prefer a permanent admin-visible diagnostic over a throwaway script: the
admin Properties card reports opcache / APCu / deferred-flush / DB-open
cost, so such questions stay answered. It is admin-gated - ask an operator
to read it; never handle admin credentials.

Deploy gotcha: version.php flips before the rest of the uploads land
(deploy sends src/ first), so a watcher that polls the version and then
immediately curls a new static file will 404 - wait a beat.
