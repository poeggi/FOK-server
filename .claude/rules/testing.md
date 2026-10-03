# Tests and the live-test harnesses

## Lessons that hold

- THE LOCAL SMOKE IS SINGLE-THREADED and cannot reproduce SQLite writer
  contention. A green run is no evidence that a locking fix works; say
  so.
- Never pin a test to a real deadline: a 1 ms walkover is flaky.
- A bare `wait` in smoke.sh waits on the backgrounded php -S forever.
  Wait on explicit curl PIDs.
- The utility .hidden must be !important. h2 header checks must be
  case-insensitive.
- Read the whole output of a rig, never a grep for FAIL: "unbound
  variable" does not start with FAIL.
- test/live-protocol.sh needs a network and a deployment, so CI never
  runs it; it passes against live and staging alike. It asserts the
  deployed tournament cap by opening a real lobby, and learns its own
  address in each family from www4/www6.poggensee.it/ip.

## Live harnesses reuse ONE fixed id set

A harness that hits a LIVE server uses one hard-coded set of ids, reused
across every run. Never randomBytes or generated ids: they leave a
growing pile of rows in the live player table that someone removes by
hand. A fixed cast keeps the residue bounded and known.

- The server harness's cast is `<n><n><n><n>7e57`, n = 1..8 (11117e57 to
  88887e57), declared once at the top of test/live-protocol.sh with a
  comment not to generate new ones.
- The client harness (FOK-snake) runs its own cast on c1e7 (`1111c1e7`
  to `6666c1e7`, named clnt-CI-*), so the two never rename each other's
  players mid-run.
- Setup with server-side side effects is idempotent, because a second
  run finds the state already there. Example: check `friend.php
  action=list` for an accepted friendship before sending `request`; a
  repeat `request` only feeds the per-id anti-enumeration throttle.

## Live test players are named srv-CI-*

Every virtual player a test creates or reuses on a LIVE instance is
named `srv-CI-<something>` (e.g. `srv-CI-1111`), so an operator looking
at real data tells test rows from real ones at a glance. The marker goes
in the NAME; the id stays an 8-hex id from the fixed cast.
