# The API contract and the version numbers

## The contract

docs/API.md is the contract, MAJOR.MINOR. The client gates on MAJOR:
FOK-snake turns online off when the server's MAJOR is newer than the one
it was built for (js/net-api.js NET_API_BUILT). A newer MINOR still works
and only flags an update. So a MAJOR ships on the client first.

A MINOR may be re-released with new optional fields. Clients
feature-detect and never version-gate an optional feature.

Dropping an OPTIONAL field is no MAJOR break where the contract says an
absent field means the client's own default. docs/API.md records such
withdrawals in its Versioning section.

A contract change is something a client can see on the wire: a field, an
action, an error, a value's meaning. A server behaviour the contract text
merely describes is not one, even where the text calls it a guarantee.
Fix the sentence, leave the number.

A number that never changes belongs in the contract, not on the wire.

## Writing it: fewer demands, never more cases

A change to docs/API.md moves towards freedom for the client. State what
the server checks and the facts a client needs (drift, sleep). Leave WHEN
and HOW OFTEN to the client. Never add a list of situations a client MUST
act in.

Before writing a MUST, ask what the server would do if the client did
not. If the answer is "nothing it can see", it is no demand: describe the
check that exists, or say it is the client's business. Procedure (how to
sample) may stay normative where a bad input hurts a third party; cadence
is not.

## FOK_SERVER_VERSION

The scheme is <major>.<feature>.<fix>.

- Every release bumps it. Any change under public/assets/ needs a bump:
  asset URLs carry ?v=<version> and are cached immutably.
- A release that moves FOK_API_VERSION at all, a MINOR included, bumps
  the middle digit and resets the last: 1.4.18 -> 1.5.0, never 1.4.19.
- Anything else bumps the last digit. "Minor version" from the user means
  the last digit. Outside an API change the middle digit moves only on
  explicit instruction; ask if a change looks feature-sized. The first
  digit moves only on explicit instruction.
- A docs-only commit (rules, plans) carries no bump.
- Tags are the bare number (`1.20.2`, no `v`). Some releases are
  untagged: take the next number from the LIVE api/version.txt, never
  from the last tag.
- Versions only move forward; the deploy's live-verify compares the
  reported one. A wrong bump is corrected by a new commit, never a
  history rewrite.

## Gone, do not re-add

hello's `friends` id list and its status maps; start.php's in-run
reasons, resync and start_pair_skew_ms; the `chat` signal type;
stats.php and the pstats table; net.php; version.php (api/version.txt
replaces it) and any demand that a client check the version; time.php
(api/t.txt is the clock); hello pace fields other than `hold`
(pace_hello_ms, pace_gap_ms, a jitter); the HTTP relay (turn.md); any
request without the identity token, a GET form of poll.php or
backup.php, backup.php's `token` alias or mint (identity.md).

## Kept on purpose

- About half the settings are contract numbers read at one site. Admin
  clutter only; constants would churn config export/import for nothing.
- In use, do not remove: q_ms, pace.hold, nets, after_ms, the delta's
  latency, backup.php, debug/submit.php.
- The smoke's up-probe and its origin-allowlist assertions GET hello.php:
  Util::cors runs, then 405. No counter (only a 400 notes an invalid
  request), no row, no database.
