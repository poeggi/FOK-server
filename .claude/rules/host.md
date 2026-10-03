# The host, the deploy, and verifying there

## Host facts

Shared hosting: Apache + PHP-FPM (fpm-fcgi via mod_proxy_fcgi).

- opcache, fastcgi_finish_request and APCu are present. APCu is shared
  across worker pids: a real IPC bus. src/Caps.php assesses once per
  FOK_SERVER_VERSION; the admin Performance tab forces a re-check. Do not
  re-probe by hand.
- Capacity is ~20-21 concurrent PHP requests. Probe with concurrent
  poll.php?wait=N holds (the one endpoint that writes nothing) and ramp
  until the wall clock doubles. From one IP that is a lower bound.
- Transport: HTTPS only, TLS 1.3, HTTP/2 (ALPN h2), keep-alive, no
  HTTP/3. h2 is an EDGE fact: TLS ends in front of whatever runs PHP
  (banner "HTTP Server"), so SERVER_PROTOCOL reads HTTP/1.1 for an h2
  request. The admin card can confirm h2, never deny it. Ask ALPN from
  outside, never $_SERVER.
- The FTP login is jailed in the docroot: `..` is the docroot itself.
  Anything above it is reached only by PHP, which runs as the account
  owner and owns every file; the one-time maintenance script in
  CLAUDE.md is the tool. The docroot's parent is the main domain's
  docroot, so a sibling directory is web-reachable there, and the app
  also answers under www.<main domain>/fok-server/. That is why the data
  dirs sit inside the docroot behind their own .htaccess.

## No streaming; long-poll works

Output is BUFFERED at ~64 KB between Apache and FPM. Small flush()ed
writes arrive at script end; only >= 64 KB per write flushes in real
time. FcgidOutputBufferSize in .htaccess is a 500, and php_value is
mod_php-only.

- No SSE, no incremental push: ~64 KB of padding per ~100 B message.
- Long-poll works because poll.php computes, holds, then sends ONE
  response at script end.
- Real push (SSE or WebSocket) needs an event-loop hub on a VPS. A held
  SSE/WS on FPM costs one worker per connection, the same as long-poll;
  only an event loop (one fd per connection) changes that.

## Verify on the real host

When a change depends on something only the host can answer, ship a
small probe first, read the answer, then build. php -S cannot answer
.htaccess, extension or file-IO questions: it ignores .htaccess,
extensions differ (APCu), and Windows file-IO timings say nothing about
the host's Linux.

Prefer a permanent admin-visible diagnostic over a throwaway script: the
Properties card reports opcache / APCu / deferred-flush / DB-open cost.
It is admin-gated: ask an operator to read it, never handle admin
credentials.

## Deploy

Push to main runs: checks -> staging (own data dir and admin hash) ->
remote smoke -> live -> verify version.

- Upload order is src/, then assets/, then pages. Assets before pages,
  or a mid-window fetch caches stale content under the new immutable
  ?v= URL for a year. src/ first, or new endpoints run against the old
  schema.
- api/version.txt is a static file both deploy paths write
  (tools/make-version.sh) before the tree is hashed. It is renamed in
  the api/ tier after src/ and assets/, so live answering the new number
  proves the rest landed: poll it to know a deploy finished. It rides
  every upload, `deploy.ps1 -Only` included.
- THE UPLOAD PLAN IS ONE LEVEL DEEP. tools/deploy.sh's `changed_in`
  emits a changed file only when its path under the top-level directory
  has no further slash, while the manifest is a full `find`. A nested
  file is hashed as landed and never uploaded: live 404s while the
  deploy reports success. Every asset sits flat in assets/, the font
  included. Test the plan with changed_in against a fake CHANGED list
  before nesting anything.
- The deploy never deletes. A removed file is deleted from staging and
  live by hand over FTPS and verified with a 404.
- The remote smoke is real request work through a keep-alive tunnel
  (grep the deploy log for `tunnel` before re-theorising): ~70 s, mostly
  the ~130 ms round trip from the Azure runner. Levers not taken: an EU
  runner, a host-only profile on staging.

Three traps:

1. A failing staging smoke SILENTLY PINS LIVE at the last green commit
   while staging shows the new version. After a push, curl live
   /api/version.txt and check the CI conclusion.
2. The staging smoke runs against a PERSISTENT staging DB. A test that
   depends on accumulated rows can fail there and nowhere else.
3. TWO PUSHES INSIDE alert_cooldown FAIL THE SECOND ONE'S SMOKE. Six
   admin assertions need Alerts::raise to write a row, and its dedup
   gate is `apcu_add(alert:<type>, ttl = alert_cooldown)`, not the
   alerts table: clearing alerts does not reset it and staging keeps it
   across a deploy. Not flaky. Wait out the window (60 s default; an
   install that saved the key from the config card keeps its own) and
   re-run the failed job (POST actions/runs/<id>/rerun-failed-jobs).
