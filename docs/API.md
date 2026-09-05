# FOK-server API

Definition of the HTTP API that game clients (currently FOK-snake) use.
This is the contract: anything not documented here is not part of the API
and may change without notice.

- Base URL: `https://fok-server.poggensee.it`
- Staging instance (same API, own database): `.../staging`
- Server source of truth: this repo, `public/api/`

## Versioning

Two versions exist and both are exposed by `GET /api/version.php`:

    {"ok":true, "server":"<x.y.z>", "api":"4.4", "env":"live"}

- `server` (FOK_SERVER_VERSION) is the implementation version; it bumps with
  every release and is informational.
- `api` (FOK_API_VERSION) is THE CONTRACT version of this document, as a
  `MAJOR.MINOR` string.
  - MAJOR bumps only on breaking changes (fields removed, semantics
    changed). This is the compatibility gate.
  - MINOR bumps on additive, backward-compatible changes (a new optional
    signal type or field). It advertises a capability; it never breaks a
    client on the same major.

Clients MUST check `api` at startup (version.php, or the `api` field that
every hello response carries) and compare the MAJOR (the integer before
the dot): disable online features with a friendly notice when the
server's MAJOR is newer than what they were built against, rather than
misbehave against an incompatible server. A newer MINOR on the same MAJOR
is safe to talk to; a client may read the MINOR to tell whether an
optional feature (e.g. the peer-net hint, added in 3.1, tournament mode,
added in 4.1, self-reported networks, added in 4.2, the tournament round
ladder and its round breaks, added in 4.3, or batched ICE candidates, the
queue-wait figure and server-set pacing, added in 4.4) is available.

## Conventions

- All endpoints speak JSON. POST bodies are JSON documents
  (`Content-Type: application/json`), responses are JSON objects.
- Every response contains `"ok": true` or `"ok": false`. On failure the
  object is `{"ok": false, "error": "<short reason>"}` with an HTTP status
  of 400 (bad input), 403 (not friends, see signal.php), 404 (unknown),
  405 (wrong method), 409 (caller is behind, see start.php), 413 (request
  body over ~272 KB, only a score submission ever comes close), 429 (rate
  cap, see below), 503 (relay busy) or 500 (server fault). Clients must
  treat any non-`ok` answer as a soft failure: log it, back off, never
  crash gameplay.
- Abuse caps returning 429 (defaults, admin-configurable): a recipient's
  signal mailbox holds at most 64 pending messages, and a player may
  submit at most 10 scores per 5 minutes. Normal play never reaches
  either; on 429, stop and retry later instead of hammering.
- Player identity is the FOK-snake player ID: a 32-bit value encoded as
  exactly 8 lowercase hex chars, e.g. `"c0ffee42"` (regex
  `^[0-9a-f]{8}$`). It is a PUBLIC identity, not a secret. A per-session
  secret token is planned but not part of this version.
- CORS: browsers may call the API from `https://poeggi.github.io` and
  `http://localhost:8000` / `http://127.0.0.1:8000`. Other origins are
  not sent CORS headers. The one exception is `t.txt`, which is served by
  Apache without PHP and so cannot consult that allowlist: it answers any
  origin, and discloses nothing the standard HTTP `Date` header does not.
- Transport: HTTPS only, TLS 1.3, HTTP/2 (ALPN `h2`, with HTTP/1.1
  fallback); connections are persistent (keep-alive). Clients should REUSE
  one connection across requests - browsers do this automatically. It
  matters for the long-poll pattern: over HTTP/2 a held poll GET and any
  outbound POSTs share one multiplexed connection, with no per-request TLS
  handshake and no HTTP-level head-of-line blocking between them. This is
  transport only: it keeps connections up, it does NOT let the server push
  without a held request (each held request still occupies one worker).
  HTTP/3 / QUIC is not offered.
- Clients must gate ALL calls on the user's offline setting
  (`!cfg.offline` in FOK-snake): when offline is ON, never contact the
  server.
- Timestamps: ALL timing/sync values are unix MILLISECONDS - `pts`,
  time.php's `t`, and hello's `now` (the same PTS clock everywhere). The
  one exception is t.txt's `X-Fok-T` header, which is MICROSECONDS.
  Only `created` fields on stored records (scores, relayed signals)
  are unix SECONDS: they are calendar bookkeeping, never used for
  timing - format dates from them, do not mix them with PTS.

## Time synchronization and PTS

Online games need one clock both players agree on - for starting levels
simultaneously, playing music/sfx in perfect sync, and ordering events.
There is exactly ONE PTS reality: the SERVER clock in milliseconds. The
server imposes it and never adjusts to anyone; each client measures its
own offset and adjusts itself. All sync work is client-side - the
server does zero per-client computation, which is what makes this
scale. A timestamp on this clock is called the PTS (presentation
timestamp).

### GET /api/t.txt - clock source (REQUIRED, preferred)

    GET /api/t.txt  ->  200, body "ok"
    Response header:  X-Fok-T: t=1784281823033613

The clock rides in a header on a STATIC file, and the value is the moment
Apache received the request, in MICROSECONDS since the epoch (note the
`t=` prefix; divide by 1000 for PTS milliseconds). The header is exposed
via CORS (`Access-Control-Expose-Headers`) and the response is
`no-store` - never cache it, a cached timestamp is a wrong clock.

Static on purpose: it is answered without PHP, so it never queues for a
PHP-FPM worker. That queue wait happens before PHP starts, so PHP can
neither see nor subtract it, and it would otherwise land in the offset
as if it were network delay - exactly when the server is busiest.

Know what that does NOT buy. Bypassing the PHP pool does not bypass the
connection: a t.txt request still shares an HTTP/2 connection, and a web
server, with everything else the client has in flight. Measurement on live
shows waits of tens of milliseconds served by workers that were already
warm, which places that contention ABOVE the PHP pool - in the same layer a
static file sits in. The file protects the STAMP; it does not protect the
round trip taken around it. That is why WHERE a client measures matters -
see "Anchor the clock when the wire is quiet" below.

`GET /api/time.php -> {"ok":true, "t": <ms>}` remains as the FALLBACK,
in milliseconds, for clients that cannot read the header (and for a
`now` re-check). Prefer t.txt; fall back if the header is absent.

### The sync procedure

    1. Record local time t0.
    2. GET /api/t.txt -> T (microseconds; T/1000 = ms). Record local
       time t1 on arrival.
    3. rtt = t1 - t0;  offset = T/1000 + rtt/2 - t1_wallclock
    4. Repeat ~5 times, keep the offset from the sample with the
       LOWEST rtt. localToPts(x) = x + offset.

Keeping the lowest-rtt sample is what removes the error, not averaging:
a sample delayed by queuing carries that delay into the offset, and the
fastest sample is the least polluted one. SPREAD the samples out (a few
hundred ms apart) rather than firing them back to back - consecutive
requests hit the same server load and can all be slow together, leaving
no clean sample to pick.

#### Anchor the clock when the wire is quiet (4.4)

The offset is the client's ONE binding onto the shared clock, and every
simultaneous moment in a duel is derived from it. Where it is measured
therefore matters as much as how.

Do NOT measure it during the connection handshake. Trickled ICE candidates
open several signal.php requests inside the same second over one
connection, and a clock sample taken alongside them inherits their wait.
Half of any such delay lands straight in the offset: a 50 ms wait is a
25 ms error, and on a 60 Hz timeline that is one and a half ticks of
disagreement between two peers about when a server-issued start actually
is - established at the moment the match begins and carried for the rest
of it.

Min-RTT does not rescue this by itself. Samples fired into one congested
moment are all slow together, so the lowest of them is the least-bad of a
bad set, and nothing in the reading says so.

The rule:

- CONNECT FIRST, ANCHOR SECOND. Take the offset once the DataChannel is
  open and candidate traffic has stopped, never in parallel with the
  handshake. Nothing needs it earlier - the first start request comes
  after the channel opens anyway.
- Do not sample while the client has requests of its own in flight.
  Quiet means quiet.
- Re-sync before every start as required below. Those moments are quiet
  by construction and make good samples.
- When a response reports a non-trivial `q_ms` (see hello.php and
  start.php), the host is busy right now: defer the sync rather than bake
  that congestion into the offset.
- When start.php answers `resync: true`, the server has seen this pair's
  two clocks disagree. Re-anchor before the next start.

Both clients now share a PTS base accurate to roughly rtt/2 (a few ms
on typical connections) - enough for frame- and audio-level sync. The
server does zero per-client work for any of this, which is what makes it
scale.

Clients MUST sync:

- before sending an invite, and on receiving one;
- before starting an online game;
- before EVERY start request (see start.php) - so before the first
  start, before each next level, after a death and before the respawn,
  and before resuming from a pause;
- periodically during long sessions (a device clock drifts by roughly
  1-3 ms per minute).

The rule is simply: a fresh sync always precedes a new start PTS.

### Using PTS

- EVERY message the peers exchange (DataChannel game packets, chat,
  and the pts field on server signals) carries the sender's current
  PTS, so the receiver can order events and measure staleness.
- Field size: a full PTS is unix milliseconds - 13 decimal digits,
  41 bits today (48 bits is safe for centuries; always below JS's
  2^53). JSON APIs carry it as a plain integer. Inside bit-packed
  DataChannel packets, save the bits: agree on a match epoch (e.g.
  the scheduled level-start PTS) and send PTS relative to it -
  24 bits of relative ms cover 4.6 hours, 32 bits cover 49 days.
- Clients report REALITY, not predictions: a message's PTS is the
  moment the event actually happened, stamped and sent as soon as
  possible. By the time it arrives anywhere, that PTS is already in
  the past.
- LEVEL STARTS ARE SERVER-ISSUED: the absolute start PTS comes from
  POST /api/start.php (below), never from a client - the server owns
  the clock, so it owns the start point. Both clients receive the
  identical value and trigger the start (music, READY/GO, first tick)
  at that instant using their local offset.
- Peers may still schedule COSMETIC-only events among themselves with
  future PTS values on the DataChannel (those never reach the server);
  anything gameplay-relevant uses the server-issued start.
- A confirming "start" message between the peers follows at the actual
  start. Receivers must understand its PTS refers to a moment ALREADY
  IN THE PAST when it arrives - it verifies the schedule, it does not
  trigger anything.
- Same pattern for anything that must be simultaneous: music cues,
  countdowns, sudden-death onset.
- Audio implementation note: for actually-synchronous playback, map
  PTS to AudioContext.currentTime once and schedule sounds through
  WebAudio (sample-accurate); never trigger audio from setTimeout
  (4-50 ms jitter). Compensate AudioContext.outputLatency where the
  browser exposes it. With the sync above (offset error is a few ms)
  the audible limit is then the device's own audio stack, not the
  network.

### Latency measurement and reporting (MANDATED)

Every client regularly measures its latency to the server and reports
it via hello's `latency` field (integer ms), so the server keeps a
record per player (shown in the admin UI, and served to friends - see
hello's `friends_latency`).

Measurement procedure:

    1. Take at least THREE samples: rtt of GET /api/t.txt each
       (reuse the clock-sync samples - same requests).
    2. If the FIRST value is an extreme outlier (cold connection: DNS,
       TCP and TLS setup make it much larger), discard it.
    3. Discard any sample taken while the client had other requests
       of its own in flight, or whose response reported a non-trivial
       `q_ms`: it measured the client's own queue, not the network.
    4. Report the AVERAGE of the remaining samples, rounded to ms -
       a stable value, not a single noisy reading.

Report with the next hello after measuring; re-measure at least when
entering the multiplayer screen and every few minutes while online.
Valid range 0..60000; omit the field between measurements (the server
keeps the last value).

An inflated reading is not harmless: start.php scales its lead time by the
pair's worst reported latency, so one sample polluted by queuing widens the
lead on every start that pair takes afterwards.

### POST /api/start.php - server-issued start of play

    POST {"id": "c0ffee42", "peer": "deadbeef", "epoch": 3,
          "reason": "respawn", "pts": 1784190295120}
      -> {"ok":true, "start_pts": 1784190295323, "epoch": 3,
          "now": 1784190295123, "q_ms": 0, "resync": false,
          "mid": "<32-hex>", "secret": "<32-hex>"}

The server owns the clock, so it owns EVERY moment play begins or
resumes. A start is requested for each of these, not only the first:

| `reason`  | when                                  |
|-----------|---------------------------------------|
| `first`   | first start of a match                |
| `level`   | next level                            |
| `respawn` | after a death, before play resumes    |
| `resume`  | coming back from a pause              |
| `rematch` | replaying against the same peer       |

Anything that halts or restarts the run goes through here. Peers never
pick the moment themselves.

**Both peers call it, and both name the same `epoch`.** The epoch counts
halts within the current connection: it starts at 0 and increments by one
per halt. Deterministic lockstep means both peers count identically
without either being authoritative, so they arrive at the same number by
themselves. The peer that asks first causes the start to be issued; the
second gets the IDENTICAL value back.

Naming the epoch is what makes the answer independent of WHEN each peer
asks. A late peer receives the same `start_pts`, possibly already in the
past - it then knows exactly how late it is and can fast-forward. This
matters most for mid-game halts: a pause or a respawn is noticed by one
peer first, and the other only learns of it over the DataChannel, so it
asks late by definition.

The server never pushes a start. The peers agree over the DataChannel
(or the relay) that a halt happened and which epoch it is; the server is
asked only for its timing.

- `epoch`: integer 0..1000000, REQUIRED. A peer that has fallen BEHIND
  the pair's epoch gets **409** `stale epoch` and must resynchronise its
  game state rather than start from a wrong origin.
- `reason`: one of the table above, REQUIRED.
- `pts`: the caller's own current PTS, REQUIRED - the proof it is synced
  (see below).
- `start_pts`: absolute, on the shared clock. Trigger everything
  (music, READY/GO, first tick) exactly then, via the local offset.
- `now`: a free clock re-check.
- `q_ms` (4.4, ADDITIVE): how long THIS request waited for a PHP worker
  before any PHP ran, in ms; normally 0. A non-trivial figure says the host
  was busy serving this very request, so the round trip around it is not a
  clean sample - see the clock-anchor rule above.
- `resync` (4.4, ADDITIVE): true when the server has seen this pair's two
  clocks disagree by more than it can account for (see the pair cross-check
  below). Re-anchor before the next start. It is a HINT, never a rejection,
  and a client that ignores it works exactly as before.
- `mid`, `secret` (contract 4.0, ADDITIVE): the pair's match id and the
  CALLER'S OWN per-match secret - never the peer's, each side gets only
  its own. They exist so a client can attest item transfers to
  /api/items.php; see the Item registry below. A start that BEGINS play
  (`first`, `rematch`) mints a fresh match, and the in-run halts (`level`,
  `respawn`, `resume`) carry that same one forward, so ONE match spans a
  whole duel and both peers read the same `mid`. A client on an older
  contract simply ignores both fields. `mid` is `""` only in the
  degenerate case of an in-run start with no begin behind it, which a
  real duel never reaches; treat an empty `mid` as "no item claims
  possible for now" rather than an error.

The lead time is chosen by the server: at least 200 ms
(`start_lead_min_ms`), scaled by the pair's latencies
(150 + 2 x worst latency when that exceeds the minimum), capped at 3 s.
A player who has never reported a latency counts as 100 ms, so a pair
that has not measured yet gets a 350 ms lead rather than the 200 ms
floor - report latency (see above) and the lead fits the pair instead.

The epoch line belongs to one pairing, and the server resets it when a
pairing BEGINS: an `invite`, an `invite-relay` or an `offer` for the pair
drops whatever line was standing, so their next match opens at `epoch: 0`
again. It is deliberately not keyed on `bye`: once the DataChannel is
open a bye travels over it and never reaches the server, so a rematch
would meet the finished line and be refused. Clients need do nothing for
this beyond the normal handshake.

#### The sync gate

`pts` is REQUIRED and must be a fresh reading of the shared clock. A
start is a moment on that clock, so a client that cannot place itself on
it is turned away rather than let into a desynced game:

- ahead of the server -> **400** `bogus pts` (zero tolerance, logged),
  for EVERY reason;
- absent -> **400** `pts required`, for EVERY reason;
- older than `start_sync_max_age_ms` (default 2 s) -> **400**
  `stale pts` (resync via t.txt and retry) - but ONLY for a start that
  BEGINS play (`first`, `rematch`). The in-run halts (`level`, `respawn`,
  `resume`) are exempt: the pair is already synced from its first start,
  so a stale proof does not block them and the client may resync as it
  goes. A `pts` in the future is still `bogus` even in-run.

Be aware of what this does and does not prove. What reaches the server is
`pts + one-way delay + any clock error`, and those cannot be separated
from a single direction - the very reason NTP needs a round trip. So the
gate is deliberately GROSS and generous: it catches a client that never
synced (a raw device clock is off by seconds to minutes) and passes any
client that did (min-RTT sampling bounds the error to a few ms). Passing
it is not a licence to skip the sync; the procedure above is the contract.

##### The pair cross-check (4.4)

There is one thing the server can see that neither client can. Both peers
prove their clock against the SAME start, so their two proofs are
comparable even though neither is interpretable alone. For each caller the
server forms `server receive time - reported pts` - one-way delay plus
signed clock error, still inseparable - and compares the pair's two
figures. Their DIFFERENCE bounds how far apart the two anchors are, and
past a tolerance (`start_pair_skew_ms`) the answer carries
`resync: true`.

It is as gross as the gate above and for the same reason, and it is a hint
rather than a refusal: a genuinely healthy pair on very asymmetric paths
would otherwise be locked out of its own match. The second caller learns it
in its own response; the first has already been answered and picks it up on
its next start or hello, which is soon enough, because a re-anchor precedes
every start anyway.

### Server-side PTS validation

Client PTS can NEVER be in the future - no tolerance. Endpoints that
accept a `pts` field (signal.php, scores.php, start.php) reject any value
ahead of the server clock with 400 `bogus pts: in the future`; the
incident is counted and logged as a bogus-client alert in the admin UI.
If an honest client gets this rejection its clock sync has drifted:
re-sync immediately (min-RTT sampling keeps the offset error at a few ms,
comfortably below any real network transit time). start.php additionally
rejects a pts too far in the PAST, but only for a start that begins play
(first/rematch) - see its sync gate.

## POST /api/hello.php - heartbeat and poll

The single periodic request a client makes. It (a) registers/refreshes
presence, (b) refreshes an ongoing 1:1 duel, and (c) delivers any pending
matchmaking/signaling messages addressed to the caller.

Request:

    {
      "id": "c0ffee42",           required, player ID
      "name": "KAI",              optional, display name (max 15 chars);
                                  recorded server-side and shown to
                                  accepted friends
      "duel_with": "deadbeef",    optional, peer ID while a 1:1 game runs
      "latency": 23,              optional, measured latency in ms (the
                                  MANDATED regular report, see Latency
                                  measurement; server keeps the last value)
      "friends": ["deadbeef"],    optional, up to 64 IDs to check (send the
                                  friend list when the multiplayer screen
                                  is open)
      "auto_accept": true         optional bool: send true in EVERY hello
                                  while the QR/add-friend screen is open -
                                  incoming friend requests are then accepted
                                  immediately (see Friendships). Expires
                                  ~60 s after the last flagged hello; a
                                  hello without the flag clears it.
      "debug": true,              optional bool: whether the client IS in
                                  debug mode right now (absent means it is
                                  not). See Debug mode below.
      "tourneys": true            optional bool: ask for the open tournament
                                  lobbies hosted on the caller's own network
                                  (see Tournament mode). Send it only while a
                                  screen that shows them is open.
      "nets": ["198.51.100.7",    optional, up to 4: the caller's OWN public
               "2a02:1:2:3::9"]   addresses, as the client discovered them.
                                  The server sees one address family per
                                  request and cannot ask a browser for the
                                  other, so this is the only way the second
                                  one becomes known - see Self-reported
                                  networks below.
    }

Response:

    {
      "ok": true,
      "api": "4.4",               contract version, see Versioning
      "now": 1784182417123,       server PTS clock, unix MILLISECONDS
                                  (free coarse re-sync on every heartbeat)
      "q_ms": 0,                  4.4: ms THIS request waited for a PHP
                                  worker before any PHP ran; normally 0.
                                  Non-trivial means the host is busy NOW -
                                  do not anchor the clock against it
      "pace": {                   4.4: how the server wants this client to
        "hello_ms": 30000,        pace itself. Additive and ignorable, but
        "poll_ms": 9000,          only the server knows its own load.
        "hold": true,             See Pacing below.
        "spread_ms": 4000
      },
      "debug": false,             the server's instruction: the client MUST
                                  honour it (see Debug mode below)
      "online": 3,                players seen in the last 60 s
      "playing": 2,               players currently in 1:1 games
      "registered": 17,           total known player IDs
      "signals": [                pending messages for "id", oldest first
        {"from": "deadbeef", "type": "invite", "payload": "", "created": 1784182410}
      ],
      "friends_online": {"deadbeef": true},  only when "friends" was sent
      "friends_latency": {"deadbeef": 31},   ms while online, else null
      "friends_name": {"deadbeef": "KAI"},   last reported display name
      "friends_playing": ["deadbeef"],       accepted friends in a duel NOW
      "tourneys": [                          only when "tourneys" was true
        {"tid": "<32-hex>", "code": "K7QMX2", "host": "c0ffee42",
         "host_name": "KAI", "players": 3, "max": 8, "stakes": false}
      ]
    }

The friends_* fields are AUTHORIZATION-GATED: real values are served only
for ids with an ACCEPTED friendship to the caller (see Friendships);
any other id reads as offline/null and never appears in friends_playing,
so possessing an id alone reveals nothing.

`friends_playing` lists the accepted friends who are in a duel right now.
Online is not the same as available - a friend mid-duel cannot take an
invite or join a lobby - so show them as busy rather than inviting them.

`tourneys` is served only when the request set `"tourneys": true`, and
lists the OPEN lobbies whose host shares a NETWORK with the caller: the
same public IPv4 address, or the same IPv6 /64. The two are not
interchangeable - IPv4 is NATed, so a household shares one address, while
on IPv6 every device carries its own address out of the site's /64 and only
the prefix is shared. It is a network-local convenience, not a directory:
everything else is joined by `code`, and the code is the capability (see
Tournament mode).

A player is on as many networks as the address families it has spoken.
A dual-stack client picks a family per connection, so the host's hello can
arrive over IPv6 while the joiner's arrives over IPv4 - the same room,
described by two strings that can never be equal. The server therefore
remembers one network per family per player (v4 and v6) and announces a
lobby when ANY network of the host meets ANY network of the caller, both
seen within `tournament_announce_window` (default 180 s). A device that has
only ever spoken one family has exactly one network, and a pair that never
overlaps - one on cellular, one behind iCloud Private Relay - is genuinely
not in the same room and still has the join code.

The announce window is deliberately wider than the 60 s presence window: a
host waiting in a lobby is a background tab or a phone with the screen off
as often as not, and browsers throttle background timers to about one a
minute.

### Pacing (`pace`, 4.4)

The server sets the beat, because only the server knows its own load. The
object is additive - a client that ignores it behaves exactly as it does
today - but a client that honours it lets the host carry more players.

    hello_ms    how often to send this heartbeat
    poll_ms     the long-poll wait to ask poll.php for
    hold        whether this client may hold a long poll AT ALL. A held
                poll occupies a PHP worker for its whole duration, which
                makes this the real lever: when it is false, poll without
                waiting and lean on the heartbeat. It is withdrawn by
                tier - a client in a duel or reconnecting keeps it
                longest, then a tournament screen with a match pending,
                then one merely browsing the lobby.
    spread_ms   a jitter budget. Draw an offset from it ONCE, keep that
                offset for the session, and apply it to every periodic
                request. It exists so clients that started together do
                not stay together.

Both intervals are clamped server-side at both ends: a floor, or the client
looks offline, and a ceiling, or it floods. Read the values as advice about
THIS moment rather than a setting - they move with load.

### Self-reported networks (`nets`)

The server only learns a network when a request actually arrives over that
address family, and a browser gives the client no way to choose one: on a
dual-stack line Happy Eyeballs may pick IPv6 for hours on end, so the
device's public IPv4 address stays unknown here indefinitely. Optional and
additive, `nets` closes that: the client reports its own public addresses
and the server records the family it could not observe.

    "nets": ["198.51.100.7", "2a02:1:2:3:4:5:6:7"]

Rules, none of which the client has to implement - they are what the server
does with what it is given:

- Send plain IP strings, not prefixes. The server derives the network the
  same way it does for an observed address (IPv4 as-is, IPv6 collapsed to
  the /64), so a client cannot claim a wider network than it is on.
- At most 4 entries, at most one used per family (the first).
- Only PUBLIC addresses count. Loopback, link-local (`fe80::`),
  unique-local (`fc00::/7`), RFC 1918 (`10/8`, `172.16/12`, `192.168/16`)
  and Chrome's `.local` mDNS placeholders are dropped: two households
  behind `192.168.0.0` are not one room. Sending them is harmless - ICE
  gathers them by nature - they are simply ignored.
- A malformed FIELD (not a list, a non-string entry, more than 4) is a
  `400 invalid nets`. An unusable ADDRESS inside a well-formed list is
  dropped silently.
- What the server SAW outranks what it was told. A self-reported network
  never displaces an observed one for the same family while that
  observation is still inside the announce window, and a report cannot be
  rewritten more than once a minute - so a client cannot sweep networks by
  reporting a different one on every heartbeat.

Where the client gets them: a one-shot ICE gather against a dual-stack STUN
server yields a server-reflexive candidate per family - the public IPv4
address and the global IPv6 one - without ever connecting to us over
either. Send them on every hello once gathered (the server no-ops when
nothing changed), re-gather every few minutes and on a network change. A
client that sends nothing keeps today's behaviour exactly: it is matched on
the families the server happens to see it on.

### GET /api/net.php - what network the server sees you on

    { "ok": true, "ip": "2a01:db8:7:7:aaaa::9", "family": 6,
      "net": "2a01:db8:7:7::/64" }

A field diagnostic, not part of the contract: it reports the caller's own
address, its family, and the network key the announce above matches on.
Open it in a browser on two devices to settle whether they reach the server
the same way at all - the question behind "the lobby on my PC is not
announced to my phone". It reads and writes nothing.

Rules:

- Signals are DRAINED on delivery: each message is returned exactly once.
  The client must process every element of `signals` immediately.
- Cadence: send hello every ~30 s, always. It is the heartbeat, not a
  fast poll; use /api/poll.php for the fast signaling window.
- While a 1:1 game is running, keep sending `duel_with` at least every
  60 s (the duel counts as over when neither peer refreshed it within
  60 s).

## Debug mode

The server can turn a specific client's debug mode on remotely - an
operator sets it per player in the admin dashboard, to diagnose a client
in the field without asking its user to do anything.

Two separate bits are involved, and they are deliberately independent:

- **The instruction**, `debug` in the hello RESPONSE. What the server
  wants. The client MUST honour it: `true` turns its debug mode on,
  `false` turns it off again. It arrives on the next hello (so up to
  ~30 s after an operator sets it), never sooner.
- **The report**, `debug` in the hello REQUEST. What the client IS
  actually doing. Send `true` in every hello while debug mode is on,
  whatever turned it on.

They differ legitimately, and the admin view names each case: `pending`
is an instruction the client has not picked up yet, and `self` is a
client that enabled debug mode on its own (a developer, a local build).
A client must therefore never derive one from the other: report what is
true, honour what is asked.

What "debug mode" shows is entirely the client's business; the server
only carries the bit.

## GET /api/poll.php - fast signal poll (matchmaking window only)

    GET /api/poll.php?id=c0ffee42[&wait=9]

    -> 204 No Content                          nothing pending
    -> 200 {"ok":true,"signals":[...]}         pending messages, drained

With `wait` (seconds, capped server-side at 9) this is a LONG POLL: the
server holds the request open and answers the moment a signal arrives,
checking every 20 ms. This is the lowest-latency delivery path - during
an active handshake, loop `wait=9` requests back-to-back and a relayed
signal reaches you in ~20 ms plus network, instead of a full poll
interval. Without `wait` it degrades to the plain cheap poll (one indexed
read, 204).

`wait` is a REQUEST, not a promise. A held request occupies one of the
server's limited workers, so there is a budget for how many may be held
at once (`hold_max_workers`); past it your poll behaves as if you had
sent no `wait` at all - your mailbox is still read and anything pending
is still delivered, but an empty one answers 204 immediately instead of
after nine seconds. Nothing about the response distinguishes the two, and
nothing has to: the client loop is the same either way. Do not treat a
fast 204 as an error or back off on it.

Same drain semantics as hello's `signals`. Use it ONLY while waiting
for or performing matchmaking/signaling; stop when the DataChannel
opens or the attempt is abandoned. In P2P mode the server is then out
of the in-game path entirely - peer packets flow directly and there is
no server hop to optimize. In relay mode it is the path (relay.php).

## GET /api/scores.php - global top 100

Optional `?limit=N` (1..100, default 100) caps the number of entries,
e.g. `?limit=10` for a lazily loaded scores page.

Response:

    {
      "ok": true,
      "scores": [
        {
          "rank": 1,
          "player_id": "c0ffee42",
          "name": "SNAKE PLISSKEN",
          "score": 4200,
          "level": 7,
          "diff": 2,
          "color": 3,
          "shopItems": {"hat": 1},
          "completed": true,       bool (3.4): the run cleared the final level
                                   (finished the game), not merely reached it
          "platform": "mobile",    string|null (3.4): device category the run
                                   was played on - pc, mobile, tv or console;
                                   null if the client did not report one
          "date": "16.07.26",      DD.MM.YY, same format as the local list
          "created": 1784182950    unix seconds, for exact ordering
        }
      ]
    }

Entries carry the same fields as a FOK-snake local top-10 entry
(name, score, level, diff, color, shopItems, date) plus rank, player_id,
completed, platform and created. Sorted by score descending, ties broken
by earlier submission - `completed` does not change the ordering, it
distinguishes a finished game from a run that only reached the same level.

## POST /api/scores.php - submit a score

Request:

    {
      "id": "c0ffee42",           required, player ID
      "name": "KAI",              optional, display name (trimmed, max 15
                                  chars = MAX_NAME in the client); missing
                                  or empty is stored as ANONYMOUS
      "score": 4200,              required, int 0..1000000000
      "level": 7,                 required, int 1..99
      "diff": 2,                  optional, int 0..3, default 1
      "color": 3,                 optional, int 0..255, default 0
      "shopItems": {"hat": 1},    optional, object, max 2 KB as JSON
      "seed": 305419896,          optional, the 32-bit game seed
      "inputs": [[12,1],[40,2]],  optional, tick-stamped input log, max 256 KB as JSON
      "completed": true,          optional bool (3.4), default false: the run
                                  CLEARED the final level (finished the game),
                                  not merely reached it; client-asserted
      "platform": "mobile",       optional string (3.4): the device category
                                  the run was played on - one of pc, mobile,
                                  tv, console; an absent or unrecognized value
                                  is stored as null (unknown)
      "pts": 1784190295123        optional, PTS of the game-over moment
                                  (never in the future, see PTS validation)
    }

Response:

    {"ok": true, "rank": 1, "top": true}

- `rank` is the submission's global rank; `top` says whether it entered
  the top 100.
- ALWAYS send `seed` and `inputs` when available. They are stored
  verbatim so the server can later validate the score by deterministic
  re-simulation (anti-spoofing). Scores without replay material may be
  treated as unvalidated in the future.
- Submit once, at game over. There is no update/delete from the client.

## POST /api/signal.php - matchmaking and WebRTC signaling

Sends one message to another player. Delivery happens through the
recipient's next hello or poll.php request (long-poll for the lowest
latency). The server never interprets `payload`.

Request:

    {
      "id": "c0ffee42",           required, sender
      "to": "deadbeef",           required, recipient (must differ from id)
      "type": "invite",           required, see below
      "payload": "...",           optional string, max 16 KB
      "pts": 1784190295123        optional, sender's PTS when the event
                                  happened (never in the future, see PTS
                                  validation)
    }

Response: `{"ok": true}`

Types (fixed set, anything else is rejected):

    invite    ask "to" for a 1:1 game            payload: JSON {"profile": <profile>}
              (requires an ACCEPTED friendship with "to", else 403)
    invite-relay  invite WITH the no-P2P bit set  payload: JSON {"profile": <profile>}
              (friendship gate + relay capacity
              checked immediately, 503 when full)
    accept    accept an invite                    payload: JSON {"profile": <profile>}
    accept-relay  accept WITH the no-P2P bit set  payload: JSON {"profile": <profile>}
              (relay capacity checked immediately,
              503 when full)
    decline   decline an invite                   payload: ""
    offer     WebRTC SDP offer                    payload: JSON {"sdp": <RTCSessionDescription>,
                                                                 "seed": <32-bit int>,
                                                                 "profile": <profile>}
    answer    WebRTC SDP answer                   payload: JSON {"sdp": <RTCSessionDescription>,
                                                                 "profile": <profile>}
    ice       ICE candidate                       payload: JSON-encoded RTCIceCandidate
    ices      SEVERAL ICE candidates (4.4)        payload: JSON ARRAY of RTCIceCandidate,
              see "Batching ICE candidates"                max 24 entries
    bye       leave / abort the session           payload: ""
    chat      text message (max 120 bytes total)  payload: plain text
    watch     ask a peer to feed you a match      payload: JSON {"nid": <node id>,
              (spectating, see Tournament mode)                  "tid": <32-hex>}
    friend    RESERVED - server-generated only    payload: JSON {"event":
              (clients cannot send it: 400)         "request"|"accepted"
                                                     |"expired",
                                                    "from": "8-hex"}
    undelivered  RESERVED - server-generated only payload: JSON {"event":
              (clients cannot send it: 400)         "undelivered",
                                                    "peer": "8-hex",
                                                    "type": <lost type>}
    peer-net  RESERVED - server-generated only     payload: JSON {"event":
              (clients cannot send it: 400)          "peer-net",
                                                     "peer": "8-hex",
                                                     "ip": <peer server-seen>,
                                                     "family": 4|6|0,
                                                     "self_ip": <your ip>,
                                                     "self_family": 4|6|0}
    tourney   RESERVED - server-generated only     payload: JSON, see the
              (clients cannot send it: 400)          event list under
                                                     Tournament mode

The 'friend' signal is the friendship NOTIFICATION: the server delivers
it into the peer's mailbox when a friend request is created for them or
their request gets accepted. It arrives like any other signal (hello or
poll.php, long-poll included), so an online client learns of a request
within its poll cadence; an offline client finds the pending entry via
friend.php list on next start (mailbox signals expire after 30 s).

The 'undelivered' signal is the FAILURE RECEIPT for a connection attempt.
An invite / invite-relay / accept / accept-relay that nobody picks up
before it expires (signal_ttl, 30 s) is a failed attempt, so the sender
is told instead of waiting forever on the ok:true it got. It is addressed
"from" the peer that never collected the message and names the lost
"type". Treat it as "this attempt is dead": stop waiting, tell the user,
offer a retry. It is raised on the next mailbox read, so it arrives with
the sender's next hello. The reverse does NOT hold: no receipt is not a
delivery confirmation, only the absence of an expiry.

The 'peer-net' signal is a DIRECT-CONNECTION HINT. The moment a 1:1
pairing is confirmed - a plain 'accept' of an invite, or a fresh quick
match - and BEFORE the WebRTC offer/answer, the server drops one into
BOTH mailboxes. It carries the peer's server-observed IP and address
family (the address that peer reaches the server from) plus the
recipient's own, so a client can compare the two. When both sides share
a family (two IPv6, or two IPv4) a direct path is likely, so the client
SHOULD try the direct ICE path first and fall back to relay only if that
fails. It is a hint, not a guarantee: the server sees the request source
address, not the eventual UDP port, and cannot know whether two
addresses can actually reach each other; family 0 means the address was
unknown. It is NOT sent when relay was declared ('accept-relay', or a
pair already relaying), since those never attempt a direct connection.
It is additive - a client that ignores the type is unaffected - and it
bumps only the api MINOR (3.1). The major stays 3, so a v3 client stays
compatible; a client reads the minor to know the hint is available.

### Batching ICE candidates (`ices`, 4.4)

A duel start trickles 4-10 ICE candidates per side, and one POST each puts
them all on the wire inside the same second, over one HTTP/2 connection,
where they queue behind each other. Measured on live: six such requests
from a single client in one second, waiting up to 134 ms each, every one
of them served by a worker that was ALREADY WARM. The cost on this host is
paid per REQUEST, not per byte, so the remedy is fewer requests. `ices` is
that - one message whose payload is a JSON array of candidates:

    payload: a JSON array, e.g. [{"candidate": "...", "sdpMid": "0"},
                                 {"candidate": "...", "sdpMid": "0"}]

The rules matter more than the saving:

- It is a SEPARATE TYPE. `ice` keeps its exact meaning and shape; never
  send an array as an `ice` payload.
- SEND THE FIRST CANDIDATE ALONE, as an ordinary `ice`, immediately. It
  is usually the host candidate - the one that connects a LAN duel in
  about a millisecond - and holding it back to fill a batch would trade a
  real win for a theoretical one. Batch the TAIL, over a short gather
  window.
- GATE ON THE PEER'S VERSION, NOT THE SERVER'S. The server will mailbox
  an `ices` to anybody. A peer built before 4.4 has no case for the type
  and drops the WHOLE array in silence, narrowing ICE for the entire
  match with no error raised anywhere. The answerer learns the peer's
  version from the offer and may batch immediately; the offerer only
  learns it from the answer and sends singles until then. That asymmetry
  is expected and self-healing.
- RETRY THE WHOLE ARRAY. Delivery is one-shot and a lost candidate
  quietly narrows ICE, which is why even a single candidate is retried
  once on 5xx. Batched, one lost POST costs every candidate in it, so the
  retry must carry the batch and not just its last element.
- The receiving side needs no ordering work it does not already do:
  candidates are parked until the remote description is set and released
  then, so a batch behaves exactly like the singles it replaces.

At most 24 candidates per message, and the ordinary 16 KB payload limit
still applies - ten candidates is roughly 2 KB, so in practice neither is
a constraint. Batching also relieves the 64-message mailbox cap.

## The player profile object

So the two players really see each other (name and look, not just an
ID), matchmaking messages carry a profile object:

    {
      "name": "KAI",              display name, max 15 chars (= MAX_NAME)
      "color": 3,                 SNAKE_COLORS index
      "shopItems": {"hat": 1}     worn cosmetic items (cfg.wornItems)
    }

- invite/accept carry it so each side can render the opponent (name,
  snake color, worn items) already in the invite dialog.
- offer/answer carry it too, because quick-matched players (match.php)
  skipped the invite step; including it always keeps one code path.
- The server relays profiles verbatim and never stores them. Clients
  MUST treat received profile fields as untrusted: clamp name to 15
  chars, clamp color/shopItems to known values, and render as text
  only (canvas/textContent, never HTML).

## POST /api/friend.php - friendships

REQUIREMENT: friendships are established THROUGH THE SERVER, and exist
only once the server has recorded them. A client-local friend list
(e.g. FOK-snake's localStorage list) establishes NOTHING by itself -
status queries and invites against an id the server has no accepted
friendship record for will not work. Migrating clients must run the
request/accept handshake below for every local friend.

The server records friendship relations as a mutual handshake. An
ACCEPTED friendship is what entitles a client to query the friend's
status (hello's friends_* maps, friend.php list) and to send game
invites; quick match remains open to strangers by design.

    POST {"id":"c0ffee42", "action":"request", "peer":"deadbeef"}
      -> {"ok":true,"state":"pending","exists":true}
                                            recorded; peer sees it in list
      -> {"ok":true,"state":"accepted","exists":true}
                                            when the peer had already
                                            requested me (auto-match), OR
                                            when the peer is currently on
                                            the QR/add-friend screen
                                            (hello auto_accept flag): being
                                            there is the consent, the
                                            handshake completes instantly
                                            and both sides get an
                                            'accepted' notification
      -> {"ok":true,"exists":false}         (API 3.5) NO player has ever
                                            registered that id: nothing is
                                            recorded and the peer is not
                                            notified, so "state" is absent.
                                            Show the user "no such id" - a
                                            mistyped or stale code, since a
                                            real id is shared by QR or link.

    The "exists" field is added in API 3.5. It reports only whether a
    players row exists for the peer (it has contacted the server at least
    once), never whether it is online. On a pre-3.5 server the field is
    absent AND a request to an unknown id records a normal "pending" row as
    before; a client that reads "exists" MUST treat its ABSENCE as unknown
    and fall back to the "state" it got. Because an id is a public
    identity, this is a deliberate existence oracle, acceptable only while
    ids are not secret (see the session-token caveat below).

    POST {"id":..., "action":"accept", "peer":...}
      -> {"ok":true,"state":"accepted"}     404 without a pending request
    POST {"id":..., "action":"remove", "peer":...}
      -> {"ok":true}                        declines a request or removes
                                            an existing friendship

Removal is always immediate and silent: the client performs it WITHOUT
a confirmation dialog (auto-confirmed), the server notifies nobody, and
no celebration effect (confetti etc.) accompanies it - celebrations are
reserved for a completed handshake.

Player expiry: a player not seen for player_ttl_days (default 180,
admin-configurable, 0 disables) is automatically removed from the
database and all of its friendships are cancelled. Each friend receives
a best-effort 'friend' {event:"expired"} notification while online;
because mailbox signals are short-lived, clients MUST also reconcile
their local friend list against friend.php list at startup - the server
list is authoritative. Scores remain as history.
    POST {"id":..., "action":"list"}
      -> {"ok":true,"friends":[{"id":"deadbeef","state":"accepted",
          "outgoing":false,"name":"KAI","online":true,"latency":31}]}
          name/online/latency filled only for accepted entries; a
          pending entry with "outgoing":false is a request awaiting MY
          acceptance.

Request rate (API 3.5): the "request" action is throttled per id on three
scales, independent of the spam ban below. Its job is to stop the "exists"
oracle above from being used to enumerate ids: the throttle is checked
BEFORE existence, so a rapid prober is turned away with 429 (learning
nothing about the peer) rather than handed an "exists" answer. A request
sent less than 1 second (admin-configurable) after the same id's previous
one answers 429 `{"ok":false,"error":"friend requests too fast",
"retry_after":1}`. And after 10 requests in a row (admin-configurable)
with no real pause, the id enters a 60-second (admin-configurable)
cooldown: every request until it ends answers 429 `{"ok":false,
"error":"friend request cooldown","retry_after":<seconds left>}`, and the
server records a visible warning in its log for the operator. The streak
clears after an idle gap of one cooldown length. A prober that waits out
the cooldown and immediately bursts AGAIN - a second cooldown trip within
10 minutes (admin-configurable) of the last - is treated as persistent and
escalated to a 1-hour cooldown (admin-configurable); the reply shape is
unchanged, only `retry_after` is larger. Honor `retry_after` (or just the
HTTP 429) and back off; do not spin. Normal use - one tap per friend -
never trips any of it. Only "request" is limited; accept, remove and list
are not.

Spam ban: a client whose UNANSWERED requests exceed a threshold
(default 15 per hour, admin-configurable) is banned from making friend
requests for a while (default 1 h), ALL of its pending requests are
deleted, and the incident is logged as an alert. The request that trips
the threshold answers 429 `friend request spam - banned`; every request
while the ban lasts answers 429 `friend requests banned`. Match on the
status, not the text. Normal use never gets close.

Poll list (or rely on hello) while the friends screen is open to notice
incoming requests. Caveat until the session-token work lands: ids are
public identities, so friendship gating is privacy hygiene, not
authentication.

## POST /api/match.php - quick match (pair with anyone waiting)

For "play with anyone" (as opposed to inviting a specific friend ID).

Request: `{"id": "c0ffee42", "action": "seek"}` - poll at ~1-2 Hz while
the user waits. Responses:

    {"ok":true, "waiting":true}                          keep polling
    {"ok":true, "matched":"deadbeef", "role":"offerer",
     "peer_name":"KAI"}                                  you create offer + seed
    {"ok":true, "matched":"deadbeef", "role":"answerer",
     "peer_name":"KAI"}                                  wait for the offer

peer_name is the opponent's latest server-recorded display name (null
if never reported) - quick match pairs strangers, so the friendship-
gated name lookups do not apply; the pairing itself is the entitlement.

`{"action": "cancel"}` leaves the queue (also automatic after 10 s
without a seek poll). After a match both sides continue at step 3 of the
1:1 flow below, with the "offerer" acting as A.

## 1:1 game flow (the intended sequence)

Player A wants to play with player B (A knows B's ID, e.g. from the
friend list; the hello `friends` field tells A whether B is online):

    1. A -> signal {type: "invite", to: B, payload: {"profile": ...}};
       A starts polling poll.php (~1 s). B's UI can now show who is
       asking, with name and snake look.
    2. B sees the invite in its hello poll (within ~30 s; within ~1 s if
       B is on the multiplayer screen and therefore polling poll.php).
       UI asks the user. B -> signal accept with B's profile (or
       decline, ending the flow).
    3. A (on accept) generates the 32-bit duel seed, creates an
       RTCPeerConnection with a DataChannel (unreliable, unordered:
       maxRetransmits 0, ordered false), and sends signal offer with
       payload = JSON {"sdp": <description>, "seed": n, "profile": ...}.
       The offerer ALWAYS generates the seed; both clients start the
       deterministic duel sim from it (startDuel(seed)).
       Both sides also receive a 'peer-net' hint here (delivered with the
       accept) carrying each other's server-observed IP and family; a
       same-family pair SHOULD prefer the direct ICE path first.
    4. B sets the remote description, answers: B -> signal answer.
    5. Both sides exchange candidates as they arrive: the FIRST as an
       `ice` immediately, the rest batched into `ices` when the peer is
       on 4.4 or newer and as further `ice` messages when it is not
       (see "Batching ICE candidates").
    6. When the DataChannel opens on both ends, BOTH clients stop
       polling poll.php, sync the clock (t.txt) - HERE, with the
       handshake finished and the wire quiet, never in parallel with
       step 5 - and EACH calls
       POST /api/start.php {id, peer, epoch: 0, reason: "first", pts}:
       the server answers both with the identical absolute start_pts,
       and the level begins exactly then (music, READY/GO, first tick).
       From here ALL game traffic flows peer-to-peer (see FOK-snake
       docs/multiplayer-server-prompt.md for the tick sync protocol).
       Clients keep the normal slow hello heartbeat (~30 s) with
       duel_with set, so the server can count running games.
    7. EVERY further halt of the run - next level, respawn, resume from
       pause - repeats step 6 with the next epoch and its reason, and a
       fresh sync each time. See start.php for the epoch rules.
    8. Either side sends bye (via the DataChannel if open, and via
       signal as fallback) to end the session. A rematch is a new
       pairing: it re-runs the handshake from step 1 and opens a new
       epoch line at 0 (the invite/offer is what resets it server-side,
       precisely because a DataChannel bye never reaches the server).

## Relay fallback - when P2P cannot connect

DEPRECATED. This endpoint and the `invite-relay` / `accept-relay` signal
types are still live and unchanged, but the server-side relay fallback is
being phased out in favour of a persistent async hub off this host. Do not
build new clients around it. Removal is a MAJOR contract change (it drops
the two signal types and relay.php) and will be coordinated with the client.
See DEPRECATED-relay.md in this repo.

P2P fails for some pairs (symmetric NAT, UDP-blocking firewalls). When
the DataChannel does not open within 5 s of signaling (the default
fallback timeout; both peers must use the same value), BOTH clients
fall back to relaying through the server.

THE NO-P2P BIT - BOTH MODES COEXIST. Clients implement a "disable P2P"
setting whose DEFAULT IS OFF:

- Setting OFF (default, the old way): send plain `invite` / `accept`,
  attempt the P2P DataChannel, and fall back to the relay only after
  the 5 s timeout. Nothing changes for these clients.
- Setting ON (the new way): declare relay mode UP FRONT - the inviter
  by sending `invite-relay` instead of `invite`, or the acceptor by
  answering `accept-relay` instead of `accept`.

The declaration is HONORED when set by EITHER side, regardless of the
other side's setting: as soon as one of the two signals carried it,
the game runs through the hub from the start and both peers skip
WebRTC entirely. Consequently every client MUST handle RECEIVING
`invite-relay` and `accept-relay` even when its own setting is off.
In relay mode the inviter still sends the `offer` signal but with
payload {"seed": n, "profile": ...} and NO sdp, the acceptor answers
with {"profile": ...} - then both call start.php and use relay.php
immediately. The server checks relay capacity at the declaring signal
itself, so a full relay answers 503 "relay busy" before any game setup
is wasted. When neither side declared the bit, nothing is checked
early and the 5 s-fallback path applies unchanged. Budget ~200-400 ms
one-way as a CONSERVATIVE upper bound - the figure the prediction/correction
model should be built to absorb, not a measured typical. The server's own
contribution is small: the hub runs in APCu shared memory - its only
transport - and forwards in roughly a millisecond. The rest is client cadence,
round trips and the wider internet. Relay INPUT events, state hashes and control
messages only - never high-rate state. The local snake stays instant; the
remote side trails and the model absorbs the lag. Show a "relay mode"
indicator so latency self-explains.

    POST /api/relay.php {"id":me, "peer":opponent, "payload":"...",
                         "pts": ms?, "pull": bool?}
      -> {"ok":true}
      -> {"ok":true,"messages":[{"seq":n,"payload":"...","created":s,"age":ms}]}
                                    only when "pull":true AND inbound was
                                    pending (piggyback, see below)
      -> 429 "relay backlog full"   receiver stopped fetching; back off
      -> 429 "relay store full"     hub shared memory was momentarily full
                                    and refused this message; RESEND it, do
                                    not treat it as delivered
      -> 429 "relay rate limit"     you are sending too fast; back off
      -> 503 "relay busy"           concurrent relayed-duel cap reached:
                                    tell the user the server is full and
                                    end the match attempt
      -> 503 "relay unavailable"    this host has no usable shared memory, so
                                    the hub cannot run at all (GET answers it
                                    too): there is no relay play on this
                                    deployment, do not retry

    GET /api/relay.php?id=me&peer=opponent&wait=9
      -> {"ok":true,"messages":[{"seq":n,"payload":"...","created":s,"age":ms}]}
         oldest first, delivered exactly once
      -> {"ok":true,"gone":true}   the pairing was torn down (a bye/decline
         marked it ended): the peer LEFT - end the session now (v3.3)
      -> 204 after the hold when nothing arrived (loop wait=9 requests
         back-to-back while in relay mode, like poll.php). Also like
         poll.php, the hold is subject to the server's worker budget and
         may answer at once instead of waiting - the pass still drains,
         and POST "pull" is the delivery path that never depends on the
         held GET being held.

LEAVE ("gone", v3.3). In relay mode the peer is watching only its held GET,
not the signal mailbox, so a P2P DataChannel-close has no equivalent: without
this the peer sat in the game until its own liveness timeout after the other
side left. The held GET now answers {"ok":true,"gone":true} the moment the
pairing is torn down (a bye or decline). Read it and end the session, same as
an in-band bye. A v3.2 client ignores it and keeps timing out.

PIGGYBACK ("pull", v3.2). A relayed duel POSTs constantly (an input plus a
keepalive), so a sender can collect its OWN inbound on those responses
instead of leaning entirely on the held GET - which stalls if the FPM pool
is saturated. Set "pull":true on the POST and read messages[] off the reply,
through the SAME exactly-once/seq dedup as the GET (a message drains to
whichever of the two arrives first, never both). It is drained on return, so
a client that does not consume the reply LOSES it: only set "pull" if you do.
A v3.1 server ignores it and answers the plain {"ok":true}. With "pull" the
held GET can be dropped or slowed, which also frees server workers.

"age" (ms, v3.2) is how long the message sat on the server before this
delivery - it separates "waited in the mailbox" (a store/poll delay) from
"queued before PHP even ran" (pool exhaustion). "created" stays whole seconds.

payload is opaque to the server (max 2 KB, defaults admin-configurable);
seq is a server-assigned increasing number for ordering. Keep sending
hello with duel_with during relayed games too. The concurrent-duel cap
exists because every relayed duel holds server workers with its long
polls - a capped, honest "busy" beats degrading the server for everyone.

Send rate is also capped per client: a sender sustaining more than
relay_rate_max messages a second (measured over more than a second, so a
brief burst is fine) is blocked with 429 for relay_rate_block_secs and an
alert is raised. Legitimate in-duel traffic is an order of magnitude under
this, so the cap only catches a runaway or malicious client.

A slot is taken by the first message a pair really pushes through the hub
and held until ~90 s after its last one (a running duel refreshes it many
times a second), so a 503 can only hit a pair that is not relaying yet -
a live game is never cut off by a full server. Declaring the no-P2P bit
does NOT reserve a slot: that 503 is a capacity preflight, so a pair can
still be turned away at its first relayed message. Handle it the same way
in both places.

`bye` also discards that pair's undelivered relay backlog, so a stale
input from a finished duel can never reach the pair's next one. Relay
messages undelivered after relay_ttl (30 s, admin-configurable) are
dropped: this is a live channel, not
a queue for an absent peer - a receiver away longer than that has lost
the duel anyway (its in-game liveness timeout fires first).

## In-game liveness

In P2P mode - the normal case - the server is NOT polled during
gameplay and the DataChannel itself is the session:

- Game state updates arrive at the net tick rate: recommended
  netInterval = max(2, ticksPerMove) on the 60 Hz engine, i.e. up to
  the maximum of 30 updates/s on fast levels for snappy correction of
  mispredictions (the 1280-byte packet cap still applies); every
  received packet proves the peer is alive.
- When no game packet is due, send a tiny in-band ping every 1 s and
  expect the peer's ping/traffic at the same rate. No packets for ~3 s
  means the session is dead: show "connection lost" and end the game.
- Also watch RTCPeerConnection.connectionState; "failed" or "closed"
  ends the session immediately.

This gives the required once-per-second alive check at zero server load
and much lower latency than any HTTP poll could.

In RELAY mode there is no DataChannel and no connectionState: the
relay.php long poll is the session. The same 1 s in-band ping and ~3 s
timeout apply, carried as relay messages; a 429/503 or repeated
transport errors end the match the same way "connection lost" does.

## Live chat (prepared, not yet implemented)

The architecture reserves a chat path for both phases; clients may ship
it later without any server change:

- Before the DataChannel is open (invite pending, lobby): the "chat"
  signal type relays a plain-text message between the two IDs. The
  server hard-rejects payloads over 120 bytes.
- During a duel: chat rides in-band on the open DataChannel like every
  other game message, e.g. {"t": "chat", "text": "..."}. Clients
  enforce the same 120-byte cap on send AND on receive (a hostile peer
  is not bound by our client code).
- Render received chat as plain text only, never HTML. Rate-limit
  display client-side (e.g. drop to 1 message/s) to keep spam from
  affecting gameplay.

Notes:

- Undelivered signals expire after 30 s. Signals only reliably arrive
  fast while the recipient is actively polling (multiplayer screen open);
  an idle client on the 30 s hello cadence can miss the window. When a
  connection-establishing message dies that way the sender is told - see
  the 'undelivered' receipt above - so an invite either goes through or
  fails loudly. Everything else (ice, ices, chat, bye) expires silently:
  those belong to a handshake the client is already timing out on its own.
- Use a public STUN server (e.g. stun:stun.l.google.com:19302) in the
  RTCPeerConnection config. There is NO TURN server: this server forwards
  the signaling (SDP/ICE) and nothing else, and sees no game traffic once
  the DataChannel is open. When P2P cannot connect, WebRTC is ABANDONED
  rather than relayed - the duel falls back to relay.php and plain HTTP
  messages (see "Relay fallback"). "P2P failed" is the switch to the hub,
  not the end of the match; only a failing relay ends it.
- Signaling payloads fit the 16 KB limit. Send the FIRST ICE candidate
  as its own `ice` signal and batch the rest into `ices` (4.4): the cost
  on this host is per request, not per byte. See "Batching ICE
  candidates".

## Stats backup / restore

A client can back its OWN config up to the server and restore it on another
device from its id and a secret token alone. Live; clients may use it now.

    POST /api/backup.php {"id": "c0ffee42", "payload": "<string>", "token"?: "<hex>"}
      -> 200 {"ok": true, "token": "<hex>", "updated": <unix seconds>}
    GET  /api/backup.php?id=c0ffee42&token=<hex>
      -> 200 {"ok": true, "payload": "<string>", "updated": <unix seconds>}
      -> 404 {"error": "no backup"}       nothing stored for this id
      -> 403 {"error": "bad token"}       missing or wrong token

The token (the secret that binds a backup to its owner):

- The FIRST backup of an id omits `token`; the server MINTS a 128-bit token
  and returns it. The client MUST store it alongside its id (e.g. in its
  cookie / local storage) - it is shown only when created.
- Every LATER backup must send that `token` (it comes back unchanged), and
  every restore must send it. It NEVER changes for a given id.
- Without the token, no one who merely knows the id (ids are exchanged
  during a duel) can read or overwrite the backup.
- Keep the token OUT of the payload. A backup that carries its own token is
  self-authenticating, so anyone who obtains the file (a shared copy, the
  operator export below) would gain full read/overwrite. FOK-snake holds the
  token in a cookie beside the id, never in the blob.
- A client that loses its token cannot read or overwrite its backup on its
  own; an operator can reset it (see Manual recovery) so the client
  re-enrolls with a fresh one on its next backup.

The payload is OPAQUE to the server - stored and returned verbatim, never
parsed - capped at 64 KB (FOK_STATS_MAX; 413 above it). One backup per id; a
POST replaces the previous one.

Payload manifest - the FOK-snake config file (`snake-fok-backup.json`): the
payload IS that file, so that id + token restore everything and an operator
export (below) is a file the game imports directly. It is one JSON object;
each field is the client's saved state stored VERBATIM as its localStorage
string, plus an integrity checksum:

    {
      "v": 1,
      "hs":      "<high-scores JSON string>",
      "coins":   "<FOKoins, a number as a string>",
      "ach":     "<achievements JSON string>",
      "cfg":     "<settings JSON string>",
      "name":    "<display name>",
      "pid":     "<8-hex player id>",
      "friends": "<friend-id array JSON string>",   // omitted if none
      "crc":     <integer FNV-1a checksum, see below>
    }

`crc` is a 32-bit FNV-1a hash of `JSON.stringify` over the fields in exactly
this order, crc EXCLUDED: {v, hs, coins, ach, cfg, name, pid, friends}. The
client rejects a restored file whose crc does not match (a file with no crc
is still accepted, for backups predating it). The server stores and returns
the blob VERBATIM and never computes or checks the crc - integrity is the
client's guard, not the server's. (Server-side records already keyed by id -
a player's friendships and submitted scores - also persist across a device
change on their own.)

Manual recovery (operator, NOT a client call): for a client that lost its
token, the admin dashboard can (a) DOWNLOAD its backup WITHOUT the token -
the same `snake-fok-backup.json` the game imports through its normal file
restore - and (b) RESET the token, so the client re-enrolls on its next
backup (a fresh token is minted; the data is kept). These paths live only
behind /admin.

## Per-player stats

Added in contract 3.4 (additive). A client saves its cumulative gameplay
counters and reads them back to restore progress on another device. Unlike the
config backup above, these are PARSED - typed integers the server aggregates
and the admin dashboard shows.

    GET  /api/stats.php?id=c0ffee42
      -> 200 {"ok": true, "stats": {...}, "updated": <unix seconds>}
    POST /api/stats.php {"id": "c0ffee42", "stats": {...}}
      -> 200 {"ok": true, "stats": {...}, "updated": <unix seconds>}

`stats` is an object of counters; all fields are optional ints >= 0:

    {
      "games":        142,   games played
      "levels":       310,   levels cleared (cumulative)
      "best_level":   17,    furthest level reached (0..99)
      "deaths":       190,   deaths
      "duels":        44,    1:1 duels played
      "duels_won":    21,    1:1 duels won
      "play_seconds": 86400  total playtime, seconds
    }

Send your RUNNING TOTALS (the whole counter, not a delta). The server keeps
each field's high-water mark:

- Stored MONOTONICALLY - a submitted value never lowers the stored one, so a
  stale or replaying device cannot roll the totals back. Send at the end of a
  run or session, not per frame.
- Each field is hard-capped (counts at 1e9, `best_level` at 99, `play_seconds`
  at ~4e9); an over-cap value is CLAMPED, not rejected, so a client never gets
  stuck. A malformed field is ignored; the rest still apply.
- The response echoes the resulting stats (with any unsent fields at their
  stored value). GET returns all zeros for an id with nothing stored yet.
- These are SELF-REPORTED (no token, no server authority): knowing an id (they
  are shared during a duel) lets anyone raise a counter up to its cap, never
  lower or corrupt it. They are progress / vanity figures, not a trust signal.
- Writes are throttled per id: a submission within a few seconds of the last
  stored one is accepted and echoed but persists on the next submission (your
  totals are cumulative, so nothing is lost), keeping the single writer clear.

## Item registry

Added in contract 4.0, and the reason 4.0 is a MAJOR bump. The server now
owns item-instance OWNERSHIP: a cosmetic a player carries between games is
a ROW in the server's item table, not a flag in the client's own config.
That is what stops a restored backup or an edited local save from
resurrecting an item that was traded away - the server decides who owns
what, and a transfer MOVES the one instance instead of copying it.

### Scope boundary - read this first

Only OWNERSHIP is authoritative. MINTING is still CLIENT-TRUSTED: the coin
economy lives on the client, so opening a box or buying in the shop is
asserted by the client and merely rate-limited. So 4.0 makes items
CONSERVED and AUDITABLE, not unforgeable. Concretely, a client can still
create an item it did not earn; it can NOT end up holding an instance that
another player also holds, take one without a transfer both sides can be
shown to have observed, or roll ownership back by restoring an old backup.
Every mint and every move lands on a tamper-evident ledger for
after-the-fact review. Unforgeable minting needs the coin economy to move
server-side, which is future work and a later contract.

### Identifiers

- a PLAYER id is the usual 8-hex public identity (`c0ffee42`).
- an `item_id` is a CATALOG id (`crown`, `neon_1`): the KIND of item.
  Lowercase `^[a-z0-9_]{1,32}$`. Many instances share one.
- a `uid` is a server-minted INSTANCE id: 32 lowercase hex. THE item.
  Knowing a uid entitles nobody to anything on its own (see claim).
- a `mid` (32 hex) is a MATCH id and a `secret` (32 hex) a per-match key,
  both issued by start.php.
- a `seq` is an instance's transfer counter. It starts at 0 and increments
  by exactly one per settled transfer; a client echoes the value it last
  read, which is how the server detects a stale or racing claim.

All four actions are POST to the same endpoint, always
`{"id": "<8-hex>", "action": "list|mint|seed|claim", ...}`.

### list - what a player owns

    POST /api/items.php {"id":"c0ffee42", "action":"list"}
      -> {"ok":true, "items":[{"uid":"<32-hex>","item_id":"crown","seq":3},
                              ...]}

A pure read, and the client's source of truth for its wardrobe: read it at
startup and after any claim that answered `stale seq`. It needs no secret,
because a uid grants nothing without a match and its secrets.

### mint - a box open or a purchase

    POST /api/items.php {"id":"c0ffee42", "action":"mint",
                         "item_id":"crown", "origin":"box"}
      -> {"ok":true, "uid":"<32-hex>", "seq":0}
      -> 429 {"ok":false,"error":"mint rate limit: too many this hour"}

Creates one fresh instance owned by `id`, at seq 0. `origin` is `box` or
`shop` - the only two a client may assert. Client-trusted per the scope
boundary, so capped per player per hour (`mint_max_per_hour`, default 60,
admin-configurable); over the cap answers 429. Normal play never reaches
it; on 429 stop and retry later rather than hammering.

### seed - the one-time legacy grandfather

    POST /api/items.php {"id":"c0ffee42", "action":"seed",
                         "items":["crown","hat","neon_1"]}
      -> {"ok":true, "items":[{"uid":"<32-hex>","item_id":"crown"}, ...]}

Mints instances for what a player already owned BEFORE 4.0, so an existing
wardrobe survives ownership moving server-side. Call it ONCE, the first
time a 4.0-capable client starts against a 4.0 server, then use list from
then on.

It is ONE-TIME and IDEMPOTENT per player: the first call mints, every
later call simply returns the current wardrobe unchanged. A retry after a
timeout therefore never double-mints - the guard is a server-side flag on
the player, not a client promise. Invalid and duplicate `item_id`s are
dropped silently and at most 128 instances are seeded.

Where the server already holds the player's config backup (see Stats
backup / restore), it PREFERS the item ids in that backup over the list in
the request - it is the stronger source, having been written earlier under
a secret token. It looks only at the top level of the backup JSON, for
either of two optional shapes:

    {"items":  ["crown", "hat"]}         an array of catalog ids, OR
    {"owned":  {"crown": 1, "hat": 1}}   an object whose truthy keys are ids

Neither shape present, an unparseable payload, or no enrolled backup falls
back to the submitted `items` list. Send the real owned list either way;
the amnesty is one-shot and the fallback is what covers a client that
never enrolled.

### claim - report a transfer

An item changes hands DURING a duel (a wager, a steal). The server does
not watch the game and never simulates it, so a transfer is REPORTED after
the fact by a claim. The attestation model below is what makes that report
trustworthy.

    POST /api/items.php {"id":"c0ffee42", "action":"claim",
                         "mid":"<32-hex>", "uid":"<32-hex>",
                         "from":"c0ffee42", "to":"deadbeef",
                         "tick": 4096, "seq": 3,
                         "ws_digest":"<opaque string>",
                         "my_tag":"<16-hex>", "peer_tag":"<16-hex>"}
      -> {"ok":true, "seq":4, "state":"confirmed"}
      -> {"ok":false, "error":"<reason>"}   with a 4xx status, see Outcomes

- `mid`: the match, from start.php. Both peers hold the same one.
- `uid`: the instance changing hands.
- `from`, `to`: the LOSING and the GAINING player. Both must be the two
  parties of `mid`, and the caller must be one of them.
- `tick`: the lockstep tick the transfer happened at, int 0..100000000.
  It NAMES the moment, the same way a start's epoch names a start.
- `seq`: the instance's transfer counter as the client last read it.
- `ws_digest`: an OPAQUE client hash of the shared ownership state at that
  tick, max 256 bytes. The server never interprets it - it only checks
  that both sides attested to the SAME one.
- `my_tag`: the caller's own attestation tag (required).
- `peer_tag`: the other side's tag (optional; absent, null or empty all
  mean "no peer evidence yet").

#### The attestation model

start.php issues, per duel, a match id `mid` and a per-match `secret` to
each peer - its OWN secret, never the other's. A tag is a truncated HMAC
over the moment and the state, keyed by a peer's secret:

    tag = first 16 hex chars of
          HMAC-SHA256(key = <secret as 16 raw bytes>,
                      msg = mid + "|" + tick + "|" + ws_digest)

Two encoding details, because getting either wrong yields a well-formed
tag that never verifies:

- the `secret` arrives as 32 hex chars, but the HMAC KEY is its 16 RAW
  BYTES - hex-decode it, do not key on the hex text;
- `tick` joins the message as its plain DECIMAL digits, unpadded (4096,
  not 0x1000 and not 00004096), and the separator is a single `|`.

The tag is the first 16 characters of the LOWERCASE hex digest. Because it
is bound to `mid`, `tick` AND `ws_digest`, it cannot be lifted onto a
different moment or a different outcome.

In a claim the two tags play different roles:

- `my_tag` AUTHENTICATES the caller as a genuine participant of that
  match - only the two peers hold the secrets.
- `peer_tag` is EVIDENCE OF JOINT OBSERVATION: the other side's tag over
  the same (mid, tick, ws_digest), i.e. proof both peers saw the same
  ownership state at the same tick.

A client computes its own tag from the `secret` start.php gave it and
obtains the peer's tag over the DataChannel (or the relay) as part of
agreeing the transfer in-game. Exchange it in-band: a packet that ARRIVED
cannot have been corrupted into a well-formed but WRONG tag, so the server
treats a shape-valid tag that does not verify as provable tampering rather
than as noise.

#### Outcomes

On success the reply carries the instance's new `seq` and a `state`:

- `settled` - the caller reported LOSING the item (`from` == caller).
  Nobody lies to give an item away, so it moves immediately.
- `confirmed` - a valid `peer_tag` came with the claim: jointly witnessed,
  so it moves immediately whichever side reported it. This is the normal,
  healthy path and the one clients should aim for.
- `held` - a GAIN claim (`to` == caller) with no valid `peer_tag` yet. The
  item does NOT move and `seq` is unchanged. The claim is parked and
  becomes settleable after `claim_grace_ms` (default 60 s,
  admin-configurable) provided nothing contradicts it. Send the SAME claim
  again once you have the peer's tag (it then answers `confirmed`), or
  again after the grace has passed (it then answers `settled`). This delay
  is deliberate: a one-sided "I gained it" must wait for either the peer's
  witness or the grace period.

Failures are `{"ok":false,"error":"..."}` with a 4xx status:

| status | error | meaning |
|--------|-------|---------|
| 400 | `invalid claim` | malformed body (bad mid, uid, tag, tick or seq shape) |
| 400 | `invalid peer_tag` | `peer_tag` was present but is not 16 hex. Omit it entirely when you have none |
| 400 | `item_out_of_match` | no open match names both parties (alerts the operator), or the match's window has closed. A match accepts claims while its duel still reports in, plus `match_open_max_ms` (default 1 min) after it goes quiet |
| 403 | `bad self tag` | `my_tag` does not verify: not a proven participant. Nothing changes |
| 409 | `stale seq, re-read` | your `seq` is behind the server's. Re-`list` and retry |
| 409 | `lost race, re-read` | another claim moved the item first. Re-`list` and retry |
| 409 | `counterfeit` | the claim names a non-owner as `from`. Alerts the operator |
| 409 | `no such item` | a uid the server never minted. Alerts the operator |
| 409 | `tag invalid` | a well-formed `peer_tag` that does NOT verify: provable tampering. The instance is FROZEN and the operator alerted |
| 409 | `contradiction` | another claim for the same (mid, uid, tick) asserts a DIFFERENT direction. Impossible honestly - one moment has one outcome - so the instance is FROZEN and the operator alerted |
| 409 | `item frozen` | the instance was frozen by an earlier dispute and can no longer transfer at all |

A claim is IDEMPOTENT: re-sending one that already settled answers
`{"ok":true, "seq":<current>, "state":"confirmed"}` and moves nothing, so
retrying after a lost response is always safe. Retry the identical body -
same mid, uid, tick, from, to - rather than rebuilding it.

A FROZEN instance is out of play until an operator resolves it from the
admin dashboard. Freezing is deliberately blunt: it is reached only from
the two provable-tampering paths, where the alternative is letting a
contested item keep moving.

#### Client rules

- Treat every non-`ok` answer as a soft failure: log it, do not crash
  gameplay, and never retry in a tight loop. `stale seq` and `lost race`
  are the only ones worth an immediate retry, and only after a fresh
  `list`.
- Gate item play on the contract MAJOR, as for every other feature: a
  client built against 3.x must not carry items into an online duel
  against a 4.x server, since its transfers would go unreported.
- Do not surface any of the tampering outcomes to the user as an
  accusation. They are operator signals (below), and a single one can be
  an ordinary lost packet.

### Suspected-fraud logging

The provable-tampering outcomes (`tag invalid`, `contradiction`,
`counterfeit`, `no such item`, `item_out_of_match`) each raise a
DE-DUPLICATED alert in the admin dashboard naming the instance and the
claiming player. Alongside them the server keeps three per-player tallies
- claims that were jointly witnessed, claims that settled without a peer
tag, and claims that were disputed - so an operator reviews a PATTERN
rather than an incident: one dispute can be a dropped packet, a climbing
disputed count is not.

Every mint and every settled transfer is also appended to a hash-chained
ledger, each row chained to the one before it, so a row cannot be altered
or reordered after the fact without detection. The dashboard can verify
the chain on demand. The ledger is never consulted to answer "who owns
this" - that is the item row - it exists purely as the audit trail. It is
truncated by CHECKPOINTS that fold in a digest of the whole ownership
table, so old history can be dropped while the remaining chain still
verifies.

None of this is exposed to clients: suspected fraud is recorded for the
operator, never announced to the accused.

## Spectating

A spectator watches a live duel it is not playing. The feed is PEER-TO-PEER
like the duel itself: the server never sees a frame, a tick or an input of
it. What the server provides is the introduction.

    watch   sent by a would-be spectator to the player it wants the feed
            from (in a tournament: the feeder, or an assigned primary)
            payload: JSON {"tid": "<32-hex>", "nid": "<node id>"}

The recipient answers with the ordinary WebRTC sequence (`offer` /
`answer` / `ice`) on a second connection, and then streams its own game
state over that data channel. A `bye` ends it.

The feed is a TREE, not a broadcast: the player being watched feeds at
most two spectators directly, and each of those may feed further ones. A
duel is latency-critical for the two people playing it, and fanning eight
data channels out of a phone mid-match is the one thing that would cost
them the match. Who feeds whom is assigned per match (see the `roles`
event below); outside a tournament, a `watch` is simply a request the
recipient may honour or ignore.

`watch` is NOT in the receipt set: a spectator whose request expires
undelivered gets no 'undelivered' signal. Failing to get a feed is not a
failed connection - the scoreboard keeps updating either way.

## Tournament mode

`POST /api/tournament.php` runs a tournament for 2 to 8 players: a lobby,
a first round, a knockout, the standings between them and a scoreboard
break at every round boundary.

It also gets HARDER as it narrows. A round is played at the LEVEL of its
own round number, so the size of the lobby decides how deep the final
gets: two players play a level-1 round and a level-2 final, eight play a
group stage, semi-finals and a level-3 final (see The round ladder).

THE SERVER ORCHESTRATES, THE PLAYERS PLAY. Every match in a tournament is
an ordinary P2P duel between the two players the server names, established
exactly like any other duel (`offer`/`answer`/`ice`, then `start.php` for
the shared start, the match id and the match secret). Spectator feeds are
P2P as well. No match traffic and no spectator traffic passes through the
server at any point, and tournament mode has nothing to do with the
deprecated relay fallback. What the server owns is the schedule, the roles,
the results and the bracket - and what a client renders is what the server
says, never a bracket of its own devising.

The server also does not WATCH a match. A result is what the two players
who played it report, and the server's whole job there is to decide when
two reports agree, when one is enough, and when they contradict each other
(see The result ladder).

### Lifecycle

    open -----> running -----> done
      |            |
      +--> abandoned <--+     (lobby reaped, or the host left the lobby)

- `open`: the lobby. Players join by code, the host starts it.
- `running`: matches are played ONE AT A TIME, in the order the server
  deals them. Everyone not playing is watching. BETWEEN two rounds the
  tournament pauses on a scoreboard until the host presses on (see The
  break between rounds): still `running`, but with `cursor` null and
  `break` set.
- `done`: the final has been settled.
- `abandoned`: the host left the lobby, or nobody started it within
  `tournament_join_ttl` (15 min).

### Requests

Always POST, always `{"id": "<8-hex>", "action": "..."}` plus the action's
fields:

    create    {id, stakes?}          -> {ok, tid, code, stakes, max}
    join      {id, tid}  or  {id, code}
                                     -> {ok, ...lobby fields}
    leave     {id, tid}              -> {ok}
    start     {id, tid}              -> {ok}                     host only
    continue  {id, tid}              -> {ok}                     host only
    state     {id, tid}              -> {ok, ...the whole tournament}
    result    {id, tid, nid, outcome, score, mid?}
                                     -> {ok, nid, state}
    standdown {id, tid, nid}         -> {ok}
    orphan    {id, tid, nid}         -> {ok}

`tid` is 32 hex characters. `code` is the 6-character join code, from an
alphabet with no 0/O/1/I/L in it because it is read off someone's screen
and typed back in. Codes are unique among OPEN tournaments only, so they
recycle. `stakes` (default false) declares that the matches are played for
items; it is passed through to the clients and the server does not act on
it - item transfers go through the item registry exactly as in any duel.

A host may hold one open-or-running tournament at a time (409), and may
create one every `tournament_create_cooldown` (429 with `retry_after`).

`join` is idempotent: joining a lobby you are already in returns the lobby
rather than an error, so a client that lost the first response just asks
again. So is `continue`: with no break open it answers `{"ok": true}`,
because the press that closed it may well have been this client's own.

`leave` depends on who sends it. From a GUEST it is a plain departure in
the lobby, and a FORFEIT once the tournament is running: the bracket
carries on without them and their remaining matches become walkovers.
From the HOST it ends the tournament for everyone at any point in its
life - state `abandoned`, `cursor` null, and a `lobby` event carrying that
state to every participant. There is no separate action for it: the same
`leave` means both things, and the server decides which by the sender.

A match in flight when the host ends it is peer-to-peer and simply plays
out; the result report is answered 409, which clients already treat as
final.

### The first round

Deliberately SPARSE. A full round-robin at 8 players is 28 matches played
one at a time, which is an evening nobody finishes. Instead:

- N <= 4: every pair (that is at most 3 matches each already).
- N >= 5: the two circulant edges on the seat circle, offsets 1 and 2, so
  every player has exactly 4 matches and the round is 2N of them.

Match counts, N = 2..10: 1, 3, 6, 10, 12, 14, 16, 18, 20.

Matches are ordered for REST: repeatedly the first remaining pair that
shares no player with the one just played, falling back to the first
remaining pair when none qualifies. On the sparse schedule (N >= 5) that
fallback never triggers and nobody plays twice in a row; at N = 3 or 4 the
dense round-robin runs out of disjoint pairs and it sometimes does.

All of it is derived from the tournament's `seed` (minted at CREATE, before
anyone knows who will join, so nobody can steer the draw by choosing when
to join) and the join order, both fixed at `start`:

    x' = (1664525 * x + 1013904223) mod 2^32     x0 = first 8 hex of seed,
                                                 or 0x9e3779b9 if that is 0
    draw(k) = next x, then x mod k

Seats are a Fisher-Yates shuffle of the join order, i from N-1 down to 1,
j = draw(i+1). A client may reproduce all of it to verify a bracket, but
it MUST render what the server sent.

Round-1 matches are played at 2 hearts and at level 1.

### Standings and who advances

    win 1, draw 0.5, loss 0
    diff = the sum of (own score - opponent score) over settled matches

Ties are broken in this order:

1. points
2. head-to-head, but ONLY between exactly two tied players whose round-1
   meeting exists and was decisive. The schedule is sparse: most pairs
   never meet, and a three-way tie has no complete sub-tournament to read,
   so anything more elaborate would be arbitrary rather than fair.
3. score difference
4. `sha256(seed + "|" + id)`, ascending. A coin toss fixed by a seed that
   existed before any result did, so it can never be tuned to the standing
   it decides.

The best `max(2, ceil(N/2))` advance. A walkover and a void contribute no
score difference (they have no score).

### The round ladder

Round 1 is played at level 1, and every round after it one level deeper:

    level = min(round, tournament_max_level)

`tournament_max_level` is 10, the game's last level - above it there is no
harder board to reach, only one the client does not have, so a tournament
deep enough to run off the end stays at the cap.

Because `round` is the stage number, the FIELD decides how far the game
gets. Three players play a level-1 round and a level-2 final; eight play a
level-1 group stage, level-2 semi-finals and a level-3 final. Half the
field advances (see Standings and who advances), so a quarter-final needs
eight advancers - a field of 16, above the default
`tournament_max_players`.

The level arrives twice, and both are the same number:

- on the `roles` sheet as `lvl`, which is what the two players preset the
  match to, exactly as they already preset `hm` hearts
- on every node in `schedule` and `bracket` as `lvl`, so a bracket can be
  drawn with the level of each stage on it before it is played

Each stage also carries a `stage` TOKEN, never a caption - the client owns
the wording and its translations:

    group     round 1, whatever its size
    quarter   4 matches      semi   2 matches      final   1 match
    ko        anything wider (a round of 16 and up), no common name

An unknown token MUST render as a plain round number rather than as
nothing.

### The break between rounds

A finished round does not roll straight into the next one. The server
stops, publishes a scoreboard, and waits for the HOST to `continue`.

    ... last match of round 1 settles
    -> cursor becomes null, `break` is set, everyone gets a `round` event
    -> the host presses continue (not before `wait` ms have passed)
    -> the first match of round 2 is dealt, `roles` as usual

While a break is open the tournament is still `running`, `cursor` is
null, and `round` has ALREADY moved to the round about to be played -
`break.done` is the one that ended.

    continue  {id, tid} -> {ok: true}
              403 not a participant / host only
              409 too early, with retry_ms

`continue` is host only and refused before `tournament_break_ms` (1 s)
has passed, because the scoreboard is the whole point of stopping and a
press that beats it is the tap that ended the last match arriving late.
The refusal carries `retry_ms`; a client may simply disable the button
for that long. Once the break has been passed it can never re-open, so a
forfeit cascade during the next round cannot put the board back up.

The break also clears ITSELF after `tournament_break_ttl_ms` (2 min),
evaluated lazily like every other deadline here: a host that closed its
browser must not be able to wedge a tournament everybody else is still
in. A client that never implements `continue` at all therefore still
works - it just waits out the deadline.

### The knockout

The advancers are folded into a bracket of the next power of two, by the
standard recursive seeding: P(1) = [1], and P(2k) interleaves each seed s
of P(k) with (2k+1-s). For 8 that is [1,8,4,5,2,7,3,6]. Seeds above the
advancer count are phantoms, so their opponent gets a bye and the node
settles immediately.

Nodes are named `ko1.1`, `ko1.2`, ... `ko2.1`, ... and the last one is
`final`. Every knockout match is 2 hearts except `final`, which is a
normal 3-heart duel. Each knockout stage is one level deeper than the one
before it (see The round ladder). A drawn knockout node is simply REPLAYED: same node
id, fresh match, fresh roles.

A VOIDED one is not - there is nobody left to replay it. It advances an
empty slot instead, which the next node reads as a bye for whoever is
still standing; if that node is empty on both sides too it voids in turn,
and a bracket that voids all the way to the top ends with an empty
podium.

### Roles - who plays and who watches

When a match comes up, every participant gets a `roles` event. It names
the two players, the feeder, and the spectator tree.

    players     the two ids, in seat order
    hm          hearts: 2, or 3 for the final
    lvl         the level the match is played at (see The round ladder)
    stage       "group" | "quarter" | "semi" | "final" | "ko"
    feeder      players[0] - the side that opens the P2P connection and
                feeds the primaries
    primaries   at most 2 spectators, fed by the feeder
    secondaries the rest, fed by a primary
    names       {id: display name or null} for every participant, so the
                bracket can be drawn without a second lookup
    you         "play" | "spectate" | "idle"

Spectators are the participants who are online and have not forfeited, in
seat order. `idle` means offline-at-deal or forfeited: keep showing the
standings.

The two players then set the duel up THEMSELVES, the ordinary way, calling
`start.php` for the mid and the secret. The tournament does not pre-mint
anything: `start.php` remains the sole authority for match ids and match
secrets, and the tournament node merely records the `mid` its players
report.

Two roles-only repairs exist, and neither can touch a result:

    standdown   a primary is about to background and hands its feed on
    orphan      a secondary lost the primaries it was fed by

Both answer `{"ok": true}` and re-deal the tree for the CURRENT node; the
new tree reaches every participant as a `roles-patch` event, not in the
response. `orphan` is rate-limited per player (one every 3 s); a stale one
for a node that has moved on is a harmless no-op.

### The result ladder

Both players report when their match ends:

    {id, tid, nid, "outcome": "win"|"loss"|"draw",
     "score": [mine, theirs], "mid": "<the match id>"}

`score` is the reporter's own score first; the server stores it in seat
order. `mid` is optional and recorded for audit only.

    outcome        alone                     with the opponent's report
    -------------  ------------------------  --------------------------
    loss           settles at once           confirmed
    win            held, then settles after   confirmed
                   tournament_result_ms
    draw           held, then settles after   confirmed
                   tournament_result_ms

Response `state` is `settled`, `confirmed`, `held`, `frozen` or `void`.

Nobody lies to lose, so a reported LOSS is taken at once. A lone win or
draw waits ~15 s for the other side and then stands - the opponent's
client may have been closed the moment the match ended.

Two reports that disagree about the winner FREEZE the node. The server
raises an admin alert, sends a `freeze` event, and stops: it cannot know
which player is right, and guessing would be worse than waiting. A frozen
round-1 node blocks only the advancer cut, so that round plays on; a frozen
knockout node has no winner to send forward and stops the bracket where it
stands. NOTE: the admin surface for clearing one is not built yet, so today
a frozen knockout node ends that tournament in place.

Only the two players of a node may report it (403 otherwise) - a spectator
report is the one input that could rewrite a result nobody disputes.
Reporting a node that is not the current one is a 409.

A node that is already CLOSED (settled, confirmed, frozen or void) is a
different case: the report is accepted, answered with the state that
already stands, and not applied. Retrying a report whose response went
missing is therefore safe, and a late contradiction can neither re-decide
a settled node nor freeze one nobody was disputing.

### When nobody answers

Nothing here runs on a timer - the host has no cron - so every deadline is
evaluated lazily, on whatever request touches the tournament next:

- a held one-sided result settles after `tournament_result_ms` (15 s)
- the match in flight is forfeited after `tournament_walkover_ms` (3 min)
  by a player who is ALSO offline. A slow match between two players who
  are both present is never taken away from them.
- an unstarted lobby is abandoned after `tournament_join_ttl` (15 min)
- a round break continues by itself after `tournament_break_ttl_ms`
  (2 min), so a host that walked away cannot wedge the tournament

A player who forfeits (by leaving, or by being offline past the walkover)
loses their remaining matches as walkovers. A node where BOTH sides are
gone is voided: no points, no difference, no winner.

### state - the full read-back

`state` returns everything a client needs to draw the tournament from
nothing, for a reload or a rejoin. Events elsewhere are deltas; this is
the whole picture.

    {
      "ok": true,
      "event": "lobby",           the lobby fields are carried verbatim,
                                  this one included - ignore it here
      "tid": "<32-hex>", "state": "running", "code": "K7QMX2",
      "host": "c0ffee42", "stakes": false, "max": 8,
      "players": [{"id": "c0ffee42", "name": "KAI"}, ...],
      "round": 1,                 1 = the first round, 2+ = knockout stages.
                                  During a break this is ALREADY the round
                                  about to be played.
      "cursor": "r1.4",           the node being played, or null
      "schedule": [ <node>, ... ],
      "bracket":  [ <node>, ... ],   empty until round 1 is over
      "standings": [ {"seat": 0, "id": "c0ffee42", "pts": 2.5,
                      "diff": 34, "rank": 1}, ... ],
      "break": <the round board, or null>,   see The break between rounds;
                                  identical to the `round` event plus
                                  `tid`, and DERIVED on every read, so a
                                  forfeit during the break shows up in it
      "roles": <the caller's own roles sheet, or null>
    }

There is no `podium` field: `over` is the only place the server names one
(see Events). A client that missed that event reads the finished tournament
back and derives the podium from the bracket the same way the server does -
winner and runner-up from the `final` node, third place from the better
`standings` rank of the two players the round below it knocked out.

A node is:

    {"nid": "r1.4", "round": 1, "hm": 2, "lvl": 1,
     "players": ["c0ffee42", "deadbeef"],    either may be null in a
                                             knockout node not yet fed
     "state": "pending"|"held"|"settled"|"confirmed"|"frozen"|"void",
     "winner": "c0ffee42" | null,
     "draw": false,
     "score": [12, 9] | null}                null for a walkover, a bye
                                             or a void

### Events

Every transition is announced to each participant as a server-generated
`tourney` signal (see signal.php), delivered through the ordinary mailbox.
A client that was offline picks its events up on its next hello. Every
payload carries `tid`.

    lobby        {event, tid, state, code, host, stakes, max,
                  players:[{id,name}], reason?}
                 someone joined or left; `reason` explains an abandon
    roles        {event, tid, round, stage, match, of, nid, hm, lvl,
                  stakes, players, feeder, primaries, secondaries,
                  names, you}
                 a match is up. `match`/`of` are its 1-based position in
                 the stage. `you` differs per recipient.
    roles-patch  {event, tid, nid, primaries, secondaries}
                 the spectator tree changed; the match is unaffected
    standings    {event, tid, rows:[{seat,id,pts,diff,rank,adv}],
                  advancers:[id, ...]}
                 the first round is over and the bracket is drawn
    round        {event, tid, done, next, stage, lvl, hm, matches, of,
                  host, at, wait, auto,
                  rows:[{seat,id,name,pts,diff,rank,adv,until,gone,
                         w,l,d}],
                  advancers:[id, ...]}
                 a round ended and the next one is waiting on the host.
                 This is the scoreboard the client shows between rounds:

                   done      the round that just ended
                   next      the round about to be played
                   stage     what to call it (see The round ladder)
                   lvl       the level it is played at
                   hm        hearts in it: 2, or 3 for the final
                   matches   how many matches it holds
                   of        how many players are through
                   host      whose CONTINUE everyone is waiting for
                   at        server ms when the break opened
                   wait      ms before `continue` is accepted (>= this
                             long the board must stay up)
                   auto      ms after `at` when the break clears itself

                 One row per PARTICIPANT, ordered as an elimination
                 ladder: still in, then whoever went out most recently,
                 then by rank. `pts`/`diff`/`rank` are the round-1
                 standings and do not move again - the knockout is
                 decided by winning, not by points - while `w`/`l`/`d`
                 count the round that just ended and nothing else.
                 `adv` is "through to `next`", `until` is the deepest
                 round that player reaches, and `gone` marks a forfeit.
    result       {event, tid, nid, winner, draw, score}
                 a node settled (winner is null for a draw or a void)
    freeze       {event, tid, nid}
                 the two reports contradicted each other. Round 1 plays
                 on and only the advancer cut waits; in the knockout the
                 bracket stops here (see The result ladder)
    over         {event, tid, podium:[winner, runner_up, third?]}
                 done. Third place is the better first-round rank of the
                 two players knocked out in the round before the final;
                 there is no third-place match. The podium is empty when
                 the final itself was voided - both finalists gone.

Every pushed event may carry `after_ms` (4.4, ADDITIVE): a small
per-recipient delay in milliseconds to wait before making any follow-up
REQUEST the event prompts. A round board wakes eight clients in the same
instant and they all call back together - the same pile-up the ICE burst
makes, eight-handed - so the server spreads them by seat. Render the
event itself immediately and delay only the calls it provokes. A client
that ignores the field behaves exactly as before.

A client MUST NOT act on a `tourney` signal it did not expect to the
extent of playing a match it cannot see in `state` - when in doubt, call
`state` and render that.

### What it costs on the wire

Tournament mode adds NO polling of its own. Every transition is PUSHED
through the mailbox a client already drains - hello, or poll.php during a
signaling window - so the traffic is a function of how many matches are
played, not of how long the tournament lasts.

The figures below are measured on a full 8-player run at the worst case
the server allows: eight participants online for every deal, 15-character
names throughout, both sides reporting every match, and one spectator-tree
repair per match. That is 19 matches - 16 in the sparse first round, then
two semi-finals and the final.

Sizes are bytes of ONE DELIVERED SIGNAL as it appears in the `signals`
array: the event plus its envelope, with the payload escaped as a JSON
string. Copies is per RECIPIENT - a `roles` event reaches eight people, so
it counts eight times.

    event          copies    max B   total B   sent when
    -----------  --------  -------  --------  --------------------------
    roles             152      717    108568  a match comes up
    roles-patch       152      272     39712  the spectator tree changed
    result            152      211     32000  a node settled
    round              16     1645     26136  a round boundary
    lobby              35      645     18648  a join, a leave, an abandon
    standings           8      853      6824  round 1 is over
    over                8      187      1496  the podium
    -----------  --------  -------  --------
    TOTAL             523              233384

233 KB for the entire tournament across all eight clients - about 29 KB
each for 19 matches. The largest single push is the round-break scoreboard
at ~1.6 KB; the steady one is the roles sheet at ~0.7 KB per match per
participant.

The request side is smaller. Response bodies, same run:

    create       90 B     join        495 B     start       11 B
    result       45 B     continue     11 B     standdown   11 B
    orphan       11 B     state      4997 B

`state` is the exception, and the one thing to get right: a full read-back
is ~5 KB, seven times a `roles` event. It exists for a reload, a
reconnect, or a client that believes it missed an event - NOT as a poll.
Eight clients polling `state` once a second would cost more server egress
every second than the whole tournament costs in pushed events.

So the RATE has no tournament term in it at all. The only periodic
requests are the ones a client already makes: hello every ~30 s (a
108-byte response, 122 with `tourneys`), and back-to-back poll.php long
polls during a signaling window, which answer 204 in about 196 bytes of
headers when nothing is pending. `orphan` is separately capped at one
every 3 s per player.

What the rate DOES have is a BURST term, and 4.4 addresses it in both
places it appears: `after_ms` spreads the follow-up calls a pushed event
provokes, and `pace` spreads the periodic ones. Neither saves bytes. Both
cut how many requests land in the same instant, which on this host is the
thing that actually costs - see Pacing.

None of this includes the match itself or the spectator feeds: those are
peer-to-peer and never reach the server (see Spectating).

### Errors

    400  invalid id / action / tid / outcome / score / mid, and
         invalid tid/code when a join names neither
    403  host only (start, continue); not a participant; not your match
         (result)
    404  no such tournament; no such node
    409  already hosting; already started; full; need 2; not running;
         not current (a result for a node that is not the one in flight);
         too early (continue before the board has been up `wait` ms,
         with retry_ms)
    429  create cooldown (with retry_after, seconds)
    503  no join code available; tournaments unavailable (the server has
         no shared memory to hold a tournament in - see below); busy (a
         transition is already in flight; simply ask again)

Tournament state is held in the server's shared memory, not in its
database: it is worthless the moment the tournament ends, and nothing else
on the server ever reads it. A client needs to know only two consequences.
A server without usable shared memory refuses `create` with 503 and offers
no tournament mode at all, so treat that 503 as a capability answer rather
than a retry. And nothing is kept longer than it is worth: an unstarted
lobby expires after `tournament_join_ttl`, a running tournament after
`tournament_run_ttl` UNTOUCHED - every transition starts that clock again,
so it never expires under players who are still playing - and a finished or
abandoned one after `tournament_done_ttl`, which is the window in which its
finished bracket can still be read back. After that the tid is simply
unknown, and `state` answers 404.

## Debug reports

A client can submit a debug bundle - structured logs and up to two image
snapshots - and gets back a short 4-digit PIN that names it. The user reads
the PIN out to support, who looks the dataset up in the admin dashboard.

    POST /debug/submit.php  <JSON bundle>
      -> 200 {"ok": true, "pin": "0042"}
      -> 413 {"error": "dataset too large"}    over 8 MB

The bundle is a single JSON object the client structures, e.g.

    {
      "app": "1.2.3", "id": "c0ffee42", "when": <ms>,
      "logs": [...], "state": {...},
      "images": ["data:image/png;base64,...", "data:image/webp;base64,..."]
    }

Stored VERBATIM - the server validates only that it is JSON and within the
cap. Limits:

- 8 MB per dataset (FOK_DEBUG_MAX); larger is rejected with 413.
- Up to two images, by convention - the 8 MB cap is the hard limit.
- Kept ONE DAY, then purged. The PIN space is small (0000-9999), so a PIN is
  reused once its dataset expires: it is a short-lived handle, not an id.

The PIN is a human handle, NOT a secret: retrieval (view / download) is
admin-only, behind /admin. A debug dataset is never readable through the
client API.

## Admin

`/admin/` is a human web UI (session login), not part of the client API.
Game clients never call it.
