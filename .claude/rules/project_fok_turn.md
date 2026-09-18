# TURN: the key, the cap, and what the server does not know

Shipped in 1.19.0 (API 4.22, schema 50). docs/API.md "TURN credentials"
is the contract; src/Turn.php is the whole implementation. This file is
what bites somebody who changes it without reading either.

## The relay is Cloudflare's, the tap is ours

This host cannot run a TURN server (no daemons). Cloudflare's relay is
used instead, on the SAME DataChannel the P2P duel uses: the client puts
the credential into its RTCPeerConnection's iceServers and ICE picks the
relay only where no direct pair connects. The server never carries a
byte of it. What it holds is the TURN KEY that mints credentials - two
members, key_id and key_token, the TURN app's Token ID and API token -
in fok-server-data/turn.json (staging: fok-server-data-staging/), put
there by hand with tools/put-turn.ps1 from ~/.fok-server-turn.json.
Absent file = no TURN offered. The key is in no answer, no log line, no
admin payload; a credential's USERNAME is not either (it names the
credential for a revoke and is half of it).

`turn.php` answers a client the credential Cloudflare minted (`ice`, the
iceServers list verbatim minus the port-53 urls, plus `ttl`) or 503
`turn_unavailable` - ONE refusal for every reason, because the client's
reaction is the same for all of them (STUN only).

## The cap is a COUNT, and that was a decision

The relay bills bytes past 1000 GB a month. Reading bytes back needs the
account's analytics API - an account id and a second, account-level
token - and the operator chose not to hand those over (2026-09-18). So
the budget is what this server can count without them: credentials
handed out. `turn_max_per_30d` (1000) over a rolling 30 days, `turn_warn_pct`
(50) for the alert. Every mint is a row in `turn_mints` (at, id), the
hourly reaping drops rows past the window, the lifetime total is
`Stats` (`turn_mints`, the 0total bucket). The bubble reads "TURN c |
30d": holders now, and the window's count the cap judges (1.19.1); the
lifetime total is popup-only. What a credential then
relays is bounded by its ttl and the relay's own rate, NOT measured
here: the cap is on hand-outs. If bytes are ever wanted, the GraphQL
dataset is `callsTurnUsageAdaptiveGroups` (sum egressBytes, dimension
customIdentifier = the player id, which every mint already carries).

Nothing is latched. `Turn::refusal` is derived from the settings and the
count at every ask; raising the cap or flipping `turn_enabled` is obeyed
at the next ask. The alerts fire on the CROSSING - the mint that reaches
the warn line or the cap - so a stream of asks past the cap raises
nothing more (each is a log note). A cap lowered below the current
count therefore refuses silently until the count falls under it.

## Two windows on one credential

A credential lives `turn_ttl_secs` (1800; Cloudflare caps at 48 h). An
id asking again while its credential has at least HALF its life left
gets the same one back, from shared memory (`fok:turn:c:<id>`, TTL =
the life), and that counts nothing - it is the only per-id bound on
minting there is. So one player costs at most two mints per ttl,
whatever it does. The switch (`turn_enabled` 0) refuses BEFORE the held
credential is answered: off means off.

## Revocation happens on the switch, not on the cap

The cap stops hand-outs; a credential already out lives its ttl. The
operator's switch revokes: `Turn::tick` (deferred tail, once a minute,
admin scripts excluded) calls `enforce` while the switch is off, and the
popup's revoke-all does it now. A revoke the relay did not confirm (not
204/200/404) keeps the entry, so the next enforcement retries. Without
the key nothing can be revoked and the list is dropped.

## The smoke fakes the relay

test/smoke/turn-fake.php is a php -S router standing in for
rtc.live.cloudflare.com; turn.json's optional `rtc_base` points the
server at it (11_turn.sh). Remote, no fake can run and the key file is
the operator's, so the part asserts only the wire shape there: a 200
with `ice` OR the 503. The unit block replaces the transport
(`Turn::setTransport`) and passes the clock in, so the half-life and the
30-day window are exact. Both suites run with `alert_cooldown` 0 for
the block, because every TURN alert type is raised more than once inside
one real minute there.

## The client half (FOK-snake)

Briefed 2026-09-18: one credential cache per session, asked for when a
duel is set up (both sides, before the pc is built), iceServers = the
credential's `ice` or the STUN-only list; the `nets` discovery pc stays
STUN-only (a relay candidate would report Cloudflare's address as the
player's own network); spectator pcs share the player's credential;
`p2pOnly` keeps refusing only the HTTP relay. Open there: whether a LAN
duel ever nominates a relay pair before the host pair (measure at
dc.onopen and 5 s later), and where in the handshake the ask sits.
