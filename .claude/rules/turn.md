# TURN: the key, the cap, and what the server does not know

docs/API.md "TURN credentials" is the contract; src/Turn.php is the whole
implementation.

## The relay is Cloudflare's, the tap is ours

This host cannot run a TURN server (no daemons), so Cloudflare's relay
carries what no direct path can, on the SAME DataChannel the P2P duel
uses. The client puts the credential into its RTCPeerConnection's
iceServers and ICE picks the relay only where no direct pair connects.
The server never carries a byte of it.

The server holds the TURN KEY that mints credentials: key_id and
key_token (the TURN app's Token ID and API token) in
fok-server-data/turn.json (staging: fok-server-data-staging/), put there
by hand with tools/put-turn.ps1 from ~/.fok-server-turn.json. No file,
no TURN offered. The key is in no answer, no log line, no admin
payload. Neither is a credential's USERNAME: it names the credential for
a revoke and is half of it.

`turn.php` answers the credential Cloudflare minted (`ice`, the
iceServers list verbatim minus the port-53 urls, plus `ttl`) or 503
`turn_unavailable`: ONE refusal for every reason, because the client
reacts the same to all of them (STUN only).

- The mint's call to Cloudflare is capped at 600 ms
  (Turn::MINT_TIMEOUT_MS). A healthy call is 120-350 ms from a
  workstation (361-450 through a TLS proxy), less from the host. The
  client builds its pc without the
  answer after a bounded wait (FOK-snake NET_TURN_WAIT_MS), so a slower
  mint is a 503 inside that window, not a counted credential nobody
  uses. Every mint logs its duration ("credentials minted for <id> in
  <ms> ms"): the host's figure is in the Logs tab, and the cap is judged
  against that.
- A revoke has 2 s (REVOKE_TIMEOUT_MS). A live credential answers 204,
  one already revoked 404 (revokeAll counts it as gone); a mint after a
  revoke is a fresh credential.
- Enforcement walks the live credentials one call after another, so it
  has a BUDGET (Turn::enforce's seconds): 5 s from the deferred tail,
  20 s from the operator's button, at least one call whatever the
  budget; what it leaves, the next enforcement takes. Without it a relay
  API outage with many credentials out would pin a worker for minutes,
  every minute.

## The cap is a COUNT

The relay bills bytes past 1000 GB a month. Reading bytes back needs the
account's analytics API (an account id and an account-level token), and
the operator does not hand those over. So the budget is what this
server can count: credentials handed out. `turn_max_per_30d` (1000) over
a rolling 30 days, `turn_warn_pct` (50) for the alert. Every mint is a
row in `turn_mints` (at, id); the hourly reaping drops rows past the
window; the lifetime total is `Stats` (`turn_mints`, the 0total bucket).
The bubble reads "TURN active | 30d": holders now, and the count the cap
judges; the lifetime total is popup-only. What a credential relays is
bounded by its ttl and the relay's own rate, NOT measured here. If
bytes are ever wanted, the GraphQL dataset is
`callsTurnUsageAdaptiveGroups` (sum egressBytes, dimension
customIdentifier = the player id, which every mint carries).

Nothing is latched. `Turn::refusal` is derived from the settings and
the count at every ask: raising the cap or flipping `turn_enabled` is
obeyed at the next ask. The alerts fire on the CROSSING, judged on the
SPAN of a mint (the count read before its call to the relay, the count
read after its row), so mints landing side by side that jump past a
line still raise; an equality test would miss the jump. Asks past the
cap raise nothing more (each is a log note). A cap lowered below the
current count refuses silently until the count falls under it. N asks in
flight at cap - 1 can overshoot by N - 1: bounded by the worker pool,
harmless against the tier, not worth a lock.

## Two windows on one credential

A credential lives `turn_ttl_secs` (3600; Cloudflare caps at 48 h). An
id asking again while its credential has at least HALF its life left
gets the same one back from shared memory (`turn:c:<id>` under the
namespace, TTL = the life), and that counts nothing. It is the only
per-id bound on minting: one player costs at most two mints per ttl.

The client asks before EVERY peer connection it builds (duel,
spectator, monitor) and takes a fresh one when the held credential has
under 30 min left (FOK-snake NET_TURN_MIN_MS, matched to the server's
half), so every connection starts on at least 30 min. An open
connection is never renewed: the client's mid-game reconnect builds a
NEW pc, never an ICE restart. So a match whose selected pair has a
relay end TOPS UP: its liveness pass asks turn.php when the held life
is under the floor, nothing waited on, and the rebuild finds a fresh
credential. One extra mint per relayed match past 30 min, then one per
half hour; a direct match never asks.

The switch (`turn_enabled` 0) refuses BEFORE the held credential is
answered: off means off.

## Revocation happens on the switch, not on the cap

The cap stops hand-outs; a credential already out lives its ttl. The
switch revokes: `Turn::tick` (deferred tail, once a minute, admin
scripts excluded) calls `enforce` while the switch is off, and the
popup's revoke-all does it now. A revoke the relay did not confirm (not
204/200/404) keeps the entry, so the next enforcement retries. Without
the key nothing can be revoked and the list is dropped.

## The smoke fakes the relay

test/smoke/turn-fake.php is a php -S router standing in for
rtc.live.cloudflare.com; turn.json's optional `rtc_base` points the
server at it (11_turn.sh). Remote, no fake can run and the key file is
the operator's, so the part asserts only the wire shape: a 200 with
`ice` OR the 503. The unit block replaces the transport
(`Turn::setTransport`) and passes the clock in, so the half-life and the
30-day window are exact; the transport can seed rows into turn_mints
while a mint is out, which is how the concurrent jump is tested. It runs
with `alert_cooldown` 0, because every TURN alert type is raised more
than once inside one real minute there.

## Address families: the leg is dual-stack, the relayed address is v4

The relay allocates over IPv6 and IPv4 alike (test/turn-alloc.mjs, a
TURN Allocate over UDP from node with a live credential), and the
RELAYED address, the one the other peer sends to, is IPv4 either way:
Cloudflare ignores REQUESTED-ADDRESS-FAMILY. It costs nothing. A
v6-only player holding a credential sends through its own allocation,
two relaying peers meet inside Cloudflare, and a v4 peer reaches any
relayed address. The one stranded case is a v6-only device with no
credential and no direct v6 path. Do not build a second relay for it.

A BROWSER CANNOT SHOW THE FAMILY: Chrome blanks a relay candidate's
related address to 0.0.0.0. The probe therefore counts relay candidates
per transport only. `--family 6|4` pins the leg by pointing the turn:
urls at the relay's literal (turns: urls dropped, a certificate names
the hostname), which proves the leg over that family end to end. With a
literal the browser gathers no UDP relay candidate in either family,
unexplained; the hostname form gathers UDP.

## The client half (FOK-snake)

One credential cache per session, asked for when a duel is set up (both
sides, before the pc is built); iceServers = the credential's `ice` or
the STUN-only list. The `nets` discovery pc stays STUN-only (a relay
candidate would report Cloudflare's address as the player's own
network). Spectator pcs share the player's credential. No prefetch on
entering multiplayer: the ask stays at the connection build. Open
there: whether a LAN duel ever nominates a relay pair before the host
pair (measure at dc.onopen and 5 s later).

## The HTTP relay is withdrawn

TURN replaces it. deprecated/relay/ holds the code for reference; its
README says what it is, how it was wired in, and why it went.

- deprecated/relay/ sits OUTSIDE public/, so the deploy never copies it.
  Nothing under public/ may require a file from it, and nothing re-adds
  a relay concept to a shared class. The smoke asserts that relay.php
  answers 404 locally and that invite-relay / accept-relay are refused
  as `invalid type` everywhere.
- No test runs the relay code and none should. It is not maintained.
- The withdrawal did not move the API; the contract's Versioning section
  records it.
- A relay that scales needs an event loop (one socket per connection).
  Shared PHP-FPM holds one worker per long poll from a pool of ~20, so
  do not rebuild a relay on this host.
