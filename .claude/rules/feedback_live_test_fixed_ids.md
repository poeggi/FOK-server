# Live-test harnesses reuse ONE fixed id set

Any harness that hits a LIVE server must use ONE defined, hard-coded set
of test ids reused across every run - never randomBytes/generated ids per
run or per scenario. Random ids leave a growing pile of throwaway rows in
the live player table; a fixed cast keeps the residue a bounded, known
set.

Fixed cast: `11117e57` (LIVETEST-A), `22227e57` (LIVETEST-B), `33337e57`
(LIVETEST-GH, the stale/ghost seeker).

Declare the ids once at the top of the harness with a comment saying not
to generate new ones. Make any setup with server-side side effects
idempotent, because a second run finds the state already there - e.g.
check `friend.php action=list` for an accepted friendship before sending
`request`; a repeat `request` just feeds the per-id anti-enumeration
throttle.
