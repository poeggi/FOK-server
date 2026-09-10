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

Deploy ordering: api/version.txt is a static file the deploy writes
(tools/make-version.sh, called by both deploy paths before the tree is
hashed), and it renames in the api/ tier - AFTER src/ and assets/, each
behind a `wait all` barrier. So live answering the new number PROVES the
code and the assets landed, and the reverse cannot happen. Poll it to
know a deploy finished. The old version.php could not do this: it sat in
api/ but read Config.php out of src/, which lands first, so it flipped
before the rest of the uploads did.
