# The HTTP relay is withdrawn: reference code only, never deployed

The HTTP relay (relay.php, the invite-relay / accept-relay signal types,
the relay_* settings, the admin Relaying bubble) is withdrawn as of
1.20.2 on contract 4.23. A duel no direct path can carry uses TURN
(project_fok_turn.md). deprecated/relay/README.md says what the code is,
how it was wired in and why it went.

- deprecated/relay/ sits OUTSIDE public/, so the deploy never copies it.
  Nothing under public/ may require a file from it, and nothing re-adds
  a relay concept to a shared class. The smoke asserts that relay.php
  answers 404 locally and that both signal types are refused as
  `invalid type` everywhere.
- No test runs the relay code and none should. It is not maintained; a
  reader who wants it running starts from commit 6f92356 (1.20.1), the
  last release that wired it in.
- The withdrawal did not move the API: the contract's Versioning section
  records it beside the 4.7 withdrawals. The relay had refused every
  attempt since 1.19.2, and FOK-snake cut its half the same day
  (1fb8a5b, js/net-relay.js to deprecated/).
- The deploy never deletes, so a host carries a removed file until it is
  deleted by hand over FTPS (api/relay.php and src/Relay*.php, staging
  and live, verified with a 404).
- A relay that scales needs an event loop (one socket per connection).
  Shared PHP-FPM holds one worker per long poll from a pool of ~20, so
  it cannot run one; do not rebuild a relay on this host.
