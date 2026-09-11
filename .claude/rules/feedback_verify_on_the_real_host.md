# Verify on the real host

When a change depends on something only the host can answer, ship a small
probe first and read the answer - never design on the assumption. Probe
first, then build; not build it all and verify at the end.

php -S on a dev box cannot answer .htaccess, PHP-extension or file-IO
questions: it ignores .htaccess entirely, extension availability differs
(APCu), and file-IO timings on a Windows dev box say nothing about the
host's Linux.

Prefer a permanent admin-visible diagnostic over a throwaway script: the
admin Properties card reports opcache / APCu / deferred-flush / DB-open
cost, so such questions stay answered. It is admin-gated - ask an operator
to read it; never handle admin credentials.

To know a deploy finished, poll api/version.txt: it renames last, so live
answering the new number proves the code and the assets landed (the
dossier's Deploy section has the ordering).
