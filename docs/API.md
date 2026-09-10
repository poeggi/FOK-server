# FOK-server API

Definition of the HTTP API that game clients (currently FOK-snake) use.
This is the contract: anything not documented here is not part of the API
and may change without notice.

- Base URL: `https://fok-server.poggensee.it`
- Staging instance (same API, own database): `.../staging`
- Server source of truth: this repo, `public/api/`

## Versioning

Two versions exist and both are exposed by `GET /api/version.txt`:

    {"ok":true, "server":"<x.y.z>", "api":"4.12", "env":"live"}

- `server` (FOK_SERVER_VERSION) is the implementation version; it bumps with
  every release and is informational.
- `api` (FOK_API_VERSION) is THE CONTRACT version of this document, as a
  `MAJOR.MINOR` string.
  - MAJOR bumps only on breaking changes (fields removed, semantics
    changed). This is the half a compatibility gate would read.
  - MINOR bumps on additive, backward-compatible changes (a new optional
    signal type or field). It advertises a capability; it never breaks a
    client on the same major.

A client is told `api` without asking: every hello and every poll body
carries it (4.9). `version.txt` answers it too, for anything that is not
a client - a deploy, a probe, a person. What a client does with it is its
own business: the server checks nothing, and behaves no differently
whether a client reads it or ignores it.

`version.txt` is a STATIC file, written by the deploy from the same
constants the running code is built from. It is served without running
any of that code, so asking what the server runs costs no request worker
and is answered while the server is busy.

What the two halves mean, for a client that wants a compatibility gate:
a MAJOR newer than the one a client was built against says fields it
relies on may be gone, which is the point at which talking to the server
anyway is worse than saying so. A newer MINOR on the same MAJOR
is safe to talk to; a client may read the MINOR to tell whether an
optional feature (e.g. the peer-net hint, added in 3.1, tournament mode,
added in 4.1, self-reported networks, added in 4.2, the tournament round
ladder and its round breaks, added in 4.3, batched ICE candidates, the
queue-wait figure and the hold decision, added in 4.4, friend presence
deltas, added in 4.6, the announced end of a duel and private duels,
added in 4.7, replacing the tournament you host, added in 4.8, or the
poll carrying the whole beat - auto-accept, the roster, the tournament
announce, the announced end of a duel, the debug report, and `api` and
the debug instruction on every body it sends, with a 5 s default
hold - added in 4.9, or one request at a time from a client while its
poll is parked, with a margin a clock reading may be ahead by before it
is refused - added in 4.10, or events: a room an operator opens, whose
members get in by scanning a code, whose roster lives only on the server
and whose tournaments nobody outside it can see - added in 4.11, its
printed key shortened to fit the code the game itself can scan in 4.12) is
available, and
which heartbeat the server expects: 60 s from 4.5, which also counts every
request as a beat, 30 s before it (see Pacing).

A MINOR is also RE-RELEASED when a later server on the SAME `api` string
gains an optional flag or field that an earlier server of that MINOR does
not have. 4.4 carries such re-releases: `friends_list` on hello with its `friends`
response, and later the latency report on hello relaxed from mandated to
optional, once the start lead stopped depending on it (see start.php:
the lead is a flat 1000 ms). The same rule runs the other way: a later server may
stop sending an optional field, as 4.4 dropped `pace.spread_ms` - a
per-session jitter offset that bought nothing a client could not get from
the request gap and `after_ms`, both of which act where the requests
actually stack - and later the interval fields of `pace` (`hello_ms`,
`poll_ms`, `gap_ms`), which had only ever carried the constants the contract
states under Pacing. 4.7 carries the same kind of withdrawal, of everything
no client had ever reached for: hello's `friends` id list with its
`friends_online` / `friends_latency` / `friends_name` / `friends_playing`
maps, superseded by the delta and now simply not answered; start.php's
`resync` and the pair cross-check behind it; the `chat` signal type, which
was only ever reserved; and `GET`/`POST` /api/stats.php, whose table no
client ever wrote. So the version says what the contract
PERMITS, not what the server in front of you implements. FEATURE-DETECT
every optional field - ask for it, use it when the answer carries it, fall
back when it does not - and never gate an optional feature on the MINOR. The
rule costs nothing when the field is present and is the only thing that
works when it is not.

## Conventions

- All endpoints speak JSON. POST bodies are JSON documents
  (`Content-Type: application/json`), responses are JSON objects.
- Every response contains `"ok": true` or `"ok": false`. On failure the
  object is `{"ok": false, "error": "<short reason>"}` with an HTTP status
  of 400 (bad input), 403 (not friends, see signal.php), 404 (unknown),
  405 (wrong method), 413 (request
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
- CORS: browsers may call the API from `https://poeggi.github.io` and, for
  local client development, `http://localhost:8000` /
  `http://127.0.0.1:8000`. Those two are the ONLY `http://` origins in the
  allowlist, and they are deliberate: loopback never leaves the machine, so
  there is no cleartext on a wire to protect. Every other origin must be
  `https://`. Origins outside the allowlist are not sent CORS headers. Two
  answers come from the web server itself, ahead of the code that holds the
  allowlist, and so cannot consult it;
  both answer any origin. `t.txt` discloses nothing the standard HTTP `Date`
  header does not. The `OPTIONS` preflight on `/api/` carries no data, and
  the response behind it is still answered normally and still applies the
  allowlist, so a stranger still cannot read an answer. A reply to an allowed
  origin also carries `Timing-Allow-Origin` naming that origin. Without it a
  browser blanks the connection breakdown of a cross-origin request -
  `nextHopProtocol`, the connect and TLS marks, the transfer sizes. It names
  the caller rather than any origin because it also discloses the response
  size. `t.txt` answers any origin: its own size is fixed.
- Transport: HTTPS only, and ENFORCED rather than merely expected, at three
  levels:
  - `http://` is answered with a redirect to the same path on `https://`
    (the host sends 301, the rule this repo ships sends 308);
  - every response carries `Strict-Transport-Security: max-age=31536000`,
    so a browser that has loaded the site once upgrades later `http://`
    URLs itself, without the redirect;
  - a cleartext request that gets through anyway is REFUSED with **426**
    `{"ok":false,"error":"HTTPS required"}`. It is a backstop, not a normal
    path: the redirect sits in front of it.
  TLS 1.2 is the floor - below it the same 426 answers
  `{"ok":false,"error":"TLS 1.2 or higher required"}` - and 1.3 is what the
  host negotiates in practice.
  Clients MUST request `https://` URLs directly and never lean on the
  redirect. A 301 does not preserve a POST body, so a redirected POST
  arrives empty; and the body has already crossed the network in cleartext
  by the time the redirect comes back.
- Connections: HTTP/2 (ALPN `h2`, with HTTP/1.1 fallback) and persistent
  (keep-alive). Clients should REUSE one connection across requests -
  browsers do this automatically. It matters for the long-poll pattern:
  over HTTP/2 a held poll GET and any outbound POSTs share one multiplexed
  connection, with no per-request TLS handshake and no HTTP-level
  head-of-line blocking between them. This is transport only: it keeps
  connections up, it does NOT let the server push without a held request
  (each held request still occupies one worker). HTTP/3 / QUIC is not
  offered.
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
the server received the request, in MICROSECONDS since the epoch (note the
`t=` prefix; divide by 1000 for PTS milliseconds). The header is exposed
via CORS (`Access-Control-Expose-Headers`) and the response is
`no-store` - never cache it, a cached timestamp is a wrong clock.
`Timing-Allow-Origin` is set as well, so a browser may read the connection
breakdown: a client can SEE that a request paid a TCP and TLS handshake
rather than inferring it from an outlying first sample.

Static on purpose: it is answered without running any server code, so it
never queues for a request worker. That queue wait is over before the
server can time it, so nothing can subtract it, and it would otherwise
land in the offset as if it were network delay - exactly when the server
is busiest.

Know what that does NOT buy. Bypassing the worker pool does not bypass the
connection: a t.txt request still shares an HTTP/2 connection, and a web
server, with everything else the client has in flight. Measurement on live
shows waits of tens of milliseconds served by workers that were already
warm, which places that contention ABOVE the pool - in the same layer a
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
    4. Repeat 3 to 5 times, keep the offset from the sample with the
       LOWEST rtt. localToPts(x) = x + offset.

Keeping the lowest-rtt sample is what removes the error, not averaging:
a sample delayed by queuing carries that delay into the offset, and the
fastest sample is the least polluted one. Space the samples by the
request gap (100 ms, fixed - see Pacing) rather than firing them back to
back: consecutive requests hit the same server load and can all be slow
together, leaving no clean sample to pick. A sweep is EXCLUSIVE: from
the first sample to the last, no other HTTP request leaves the client
for this server (the rule below).

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
- Do not sample while the client has requests of its own in flight, and
  send NOTHING ELSE while a sweep runs: from the first sample to the
  last, no other HTTP request leaves the client for this server - no
  heartbeat, no signal, no claim, no poll re-arm. Begin the sweep only
  once the wire is quiet; a request already in flight is let finish
  first. A held poll that is already parked is not traffic and may stay
  parked, but one that answers mid-sweep is re-armed after the last
  sample is back, not before. Quiet means quiet.
- When a response reports a non-trivial `q_ms` (see hello.php and
  start.php), the host is busy right now: defer the sync rather than bake
  that congestion into the offset.
Both clients now share a PTS base accurate to roughly rtt/2 (a few ms
on typical connections) - enough for frame- and audio-level sync. The
server does zero per-client work for any of this, which is what makes it
scale.

When to sweep is the client's business. The server checks two things and
nothing else: how far ahead of the server a `pts` reads - a warning in
the log past `pts_ahead_max_ms` / 2, and **400** `bogus pts: in the
future` past `pts_ahead_max_ms` itself (default 200 ms), so repair the
anchor and retry - and a `pts` on a start
that BEGINS play is computed at send time from an anchor the client
holds (the sync gate under start.php rejects a reading older than 1 s,
never an old anchor). Two facts size how long an anchor stays good: a
device clock drifts by roughly 1-3 ms per minute, and a suspended
device's counter freezes, so on return from background the anchor is off
by the whole sleep. Whether a client refreshes on a timer, on a screen,
on an age, or blends a new reading into the anchor it holds is its own
choice; the server only ever sees the resulting `pts`. The pair's
residual disagreement is the peer-to-peer burst's job, not the server
sync's.

### Using PTS

- EVERY message the peers exchange (DataChannel game packets and the
  pts field on server signals) carries the sender's current
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

### Latency measurement and reporting (OPTIONAL)

A client MAY measure its latency to the server and report it via hello's
`latency` field (integer ms). The server keeps the last value per player
and uses it for display only: the admin UI, and friends - see the
`latency` in a friend delta, which is null for a friend that never
reported.
Nothing in gameplay reads it; the start lead is a flat figure (see
start.php). A client that never reports loses nothing. One that does
must follow the procedure below - a wrong figure is worse than none,
because a friend reads it as the state of the line.

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

Report with the next hello after measuring. How often to re-measure is
the client's choice: a value taken from the multiplayer screen's clock
sync is plenty, and there is no obligation to sample for this alone.
Valid range 0..60000; omit the field between measurements (the server
keeps the last value).

### POST /api/start.php - server-issued start of play

    POST {"id": "c0ffee42", "peer": "deadbeef", "epoch": 0,
          "reason": "first", "pts": 1784190295120,
          "duel_private": false}
      -> {"ok":true, "start_pts": 1784190295323, "epoch": 0,
          "now": 1784190295123, "q_ms": 0,
          "mid": "<32-hex>", "secret": "<32-hex>"}

The server owns the clock, so it owns the moment play BEGINS - and that
is the only moment it is asked about. There are two reasons:

| `reason`  | when                                  |
|-----------|---------------------------------------|
| `first`   | first start of a match                |
| `rematch` | replaying against the same peer       |

The halts WITHIN a run - the next level, a respawn, coming back from a
pause - are settled peer-to-peer over the DataChannel and never reach the
server. A boundary inside an ongoing match is the pair's own business.
Peers never pick the moment play BEGINS themselves.

**Both peers call it, and both name the same `epoch` and `reason`.** The
peer that asks first causes the start to be issued; the second gets the
IDENTICAL value back, however late it is - it then knows exactly how late
and can fast-forward. That is what makes the answer independent of WHEN
each peer asks.

A rematch names `epoch: 0` exactly as a first start does, so `reason` is
what tells the server the pair wants a NEW moment rather than the one it
already issued them. A stored start also ages out of the pairing window
after a few seconds, which covers a rematch that repeats both fields.

The server never pushes a start. The peers agree over the DataChannel (or
the relay) that play is beginning; the server is asked only for its
timing.

- `epoch`: integer 0..1000000, REQUIRED. Both peers name the same one.
- `reason`: one of the two above, REQUIRED.
- `pts`: the caller's own current PTS, REQUIRED - the proof it is synced
  (see below).
- `duel_private` (4.7, ADDITIVE): this duel is counted but never
  attributed, so no friend is offered a spectate link for it. Absent means
  public, exactly as on hello - the flag is a property of the duel, stated
  on every request that holds it up, not a latch set once (see Announcing
  a duel).
- `start_pts`: absolute, on the shared clock. Trigger everything
  (music, READY/GO, first tick) exactly then, via the local offset.
- `now`: a free clock re-check.
- `q_ms` (4.4, ADDITIVE): how long THIS request waited for a free request
  worker, before any server code ran, in ms; normally 0. A non-trivial figure says the host
  was busy serving this very request, so the round trip around it is not a
  clean sample - see the clock-anchor rule above.
- `mid`, `secret` (contract 4.0, ADDITIVE): the pair's match id and the
  CALLER'S OWN per-match secret - never the peer's, each side gets only
  its own. They exist so a client can attest item transfers to
  /api/items.php; see the Item registry below. Every start begins play, so
  every one mints a fresh match and both peers read the same `mid` for the
  duel it opens. A client on an older contract simply ignores both
  fields.

The lead time is chosen by the server and is the same for every pair:
1000 ms (`start_lead_ms`, admin-configurable). It depends on nothing a
client reports, so no measurement a client makes can move the moment
play begins. Clients never compute it; they trigger on `start_pts`.

The epoch line belongs to one pairing, and the server resets it when a
pairing BEGINS: an `invite`, an `invite-relay` or an `offer` for the pair
drops whatever line was standing, so their next match opens at `epoch: 0`
again. It is deliberately not keyed on `bye`: once the DataChannel is
open a bye travels over it and never reaches the server, so a rematch
would meet the finished line and be refused. Clients need do nothing for
this beyond the normal handshake.

#### The sync gate

`pts` is REQUIRED and must be a fresh reading of the shared clock. A
start is a moment on that clock: a client that cannot place itself on it
is turned away rather than let into a desynced game, and one whose
reading is merely off is answered and recorded.

- more than `pts_ahead_max_ms` (default 200) ahead of the server ->
  **400** `bogus pts: in the future`, an error in the server log naming
  how far ahead it read, and a bogus-client alert on the dashboard. The
  400 is the only part of that the client can see, and the only thing
  that will make it repair its anchor;
- more than half of that (100 ms) ahead -> accepted and answered
  normally, one warning line in the server log, no alert. The anchor is
  drifting, not broken;
- absent -> **400** `pts required`;
- older than `start_sync_max_age_ms` (default 1 s) -> **400**
  `stale pts`, logged as an error (resync via t.txt and retry).

All of it applies to every start, because every start begins play and a
pair has to enter its run aligned.

Be aware of what this does and does not prove. What reaches the server is
`pts + one-way delay + any clock error`, and those cannot be separated
from a single direction - the very reason NTP needs a round trip. So the
gate is deliberately GROSS and generous: it catches a client that never
synced (a raw device clock is off by seconds to minutes) and passes any
client that did (min-RTT sampling bounds the error to a few ms). Passing
it is not a licence to skip the sync: the procedure above (HOW to sample)
is the contract; WHEN to sweep is the client's business, bounded only by
these gates.

##### The pair cross-check (4.4)

### Server-side PTS validation

What arrives is pts + one-way delay, so the trip already pays for a clock
that is a little fast; a reading that still lands ahead is an anchor off
by more than the trip. Endpoints that accept a `pts` field (signal.php,
scores.php, start.php, relay.php) sort those readings into two:

- ahead by more than `pts_ahead_max_ms` / 2 (default 200, a setting, so
  100 ms): ANSWERED NORMALLY, one WARNING in the server log. The anchor
  is drifting and the client is still usable.
- ahead by more than `pts_ahead_max_ms`: **400** `bogus pts: in the
  future`, one ERROR in the server log for every occurrence, plus a
  bogus-client alert on the dashboard (one row per alert cooldown). The
  log lines name the endpoint, the reading and how far ahead it was.

The thresholds are drawn where honest anchoring error ends - min-RTT
sampling keeps it to a few ms, and the worst honest case is a sample
taken on a busy wire - and far below the error of a client that never
synced at all, whose clock is off by seconds to minutes. Before 4.10 the
line was at zero, which refused clients whose only fault was where they
anchored.

The 400 is not bookkeeping: nothing on the server reads the value, so
the only reason to refuse is that the CLIENT cannot see a server log.
A client told nothing repairs nothing and goes on playing desynced
matches, and its peer pays for that too. start.php refuses a pts too far
in the PAST for the same reason, but only for a start that begins play
(first/rematch), and logs an error too. See its sync gate.

## POST /api/hello.php - heartbeat and poll

The single periodic request a client makes. It (a) registers/refreshes
presence, (b) refreshes an ongoing 1vs1 duel, and (c) delivers any pending
matchmaking/signaling messages addressed to the caller.

Request:

    {
      "id": "c0ffee42",           required, player ID
      "name": "KAI",              optional, display name (max 15 chars);
                                  recorded server-side and shown to
                                  accepted friends
      "duel_with": "deadbeef",    optional, the peer while a 1vs1 game runs
                                  - REFRESHES what start.php announced
                                  (see Announcing a duel below)
      "duel_private": false,      optional, 4.7: this duel counts in the
                                  "playing" figure but is never attributed
                                  to the caller, so no friend is offered a
                                  spectate link for it
      "duel_end": "deadbeef",     optional, 4.7: the peer the caller has
                                  just STOPPED playing
      "latency": 23,              optional, measured latency in ms (see
                                  Latency measurement; display only, the
                                  server keeps the last value)
      "friends": ["deadbeef"],    optional, up to 64 IDs to check (send the
                                  friend list when the multiplayer screen
                                  is open). Superseded by "friends_since"
                                  in 4.6, and ignored when that is present
      "friends_since": 0,         optional, 4.6: a cursor in ms. Answer
                                  with the caller's ACCEPTED friends whose
                                  presence changed after it, no ids sent.
                                  0 asks for all of them. See Friend
                                  presence deltas below
      "auto_accept": true         optional bool: send true in EVERY hello
                                  while the QR/add-friend screen is open -
                                  incoming friend requests are then accepted
                                  immediately (see Friendships). Expires
                                  ~120 s after the last flagged hello; a
                                  hello without the flag clears it. Since
                                  4.9 poll.php's `aa=1` arms it too, so a
                                  client already holding a poll needs no
                                  hello for it.
      "debug": true,              optional bool: whether the client IS in
                                  debug mode right now (absent means it is
                                  not). See Debug mode below.
      "friends_list": true        optional bool: return the caller's WHOLE
                                  friend roster in this response - the same
                                  array friend.php list returns. Send it in
                                  place of a separate friend.php call while
                                  a screen that shows the roster is open.
                                  It is a 4.4 RE-RELEASE addition, so a 4.4
                                  server may not have it: fall back to
                                  friend.php when the response carries no
                                  "friends", never gate on the version.
      "tourneys": true            optional bool: ask for the open tournament
                                  lobbies hosted on the caller's own network
                                  (see Tournament mode). Send it only while a
                                  screen that shows them is open.
      "events": true              4.11, optional bool: ask for the caller's
                                  own events (see Events). Send it on the
                                  hello before a screen that needs them,
                                  not on every beat.
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
      "api": "4.12",               contract version, see Versioning
      "now": 1784182417123,       server PTS clock, unix MILLISECONDS
                                  (free coarse re-sync on every heartbeat)
      "q_ms": 0,                  4.4: ms THIS request waited for a free
                                  worker, before any server code ran;
                                  normally 0.
                                  Non-trivial means the host is busy NOW -
                                  do not anchor the clock against it
      "pace": {                   4.4: whether this client may hold a long
        "hold": true              poll right now. Additive and ignorable.
      },                          See Pacing below.
      "debug": false,             the server's instruction: the client MUST
                                  honour it (see Debug mode below)
      "online": 3,                players seen in the last 120 s
      "playing": 2,               players currently in 1vs1 games, private
                                  ones included (see Announcing a duel)
      "registered": 17,           total known player IDs
      "signals": [                pending messages for "id", oldest first
        {"from": "deadbeef", "type": "invite", "payload": "", "created": 1784182410}
      ],
      "friends_delta": {                     only when "friends_since" was
        "deadbeef": {"online": true,         sent: each accepted friend
                     "playing": false,       whose state changed after the
                     "latency": 31,          cursor, whole
                     "name": "KAI"}
      },
      "friends_at": 1784182417123,           the cursor for the next read
      "friends_more": false,                 true: ask again immediately
      "friends": [                           only when "friends_list" was
        {"id": "deadbeef",                   true, AND only on a server that
         "state": "accepted",                has the 4.4 re-release. Byte
         "outgoing": false,                  for byte what friend.php list
         "name": "KAI",                      returns - see Friendships
         "online": true,
         "latency": 31}
      ],
      "tourneys": [                          only when "tourneys" was true
        {"tid": "<32-hex>", "code": "K7QMX2", "host": "c0ffee42",
         "host_name": "KAI", "players": 3, "max": 8, "stakes": false,
         "speed": false, "eid": null}
      ],
      "events": [                            only when "events" was true
        {"eid": "K7QM", "name": "Snake Night", "closed": false,
         "state": "active", "starts": null, "ends": null,
         "you": {"state": "member", "organizer": false},
         "members": 14}
      ]
    }

`friends` in the response is the ROSTER - who the caller's friends are at
all, including requests still pending, requests the caller sent out, and
names for ids the client has never seen. Presence is a different question
and a different shape: it comes from the delta below, which the caller asks
for with a cursor and never with a list of ids.

### Friend presence deltas (`friends_since`, 4.6)

The maps above answer for the ids the request names, in full, every time.
From 4.6 there is a second way to read the same thing: ask for what
CHANGED.

    hello.php   "friends_since": 0     request field, a cursor in ms
    poll.php    ?fs=0                  query parameter, the same cursor

The caller sends no ids. Status is served for the caller's ACCEPTED
friends, which the server already knows, under the same authorization
gate: an id with no accepted friendship is not in the answer at all.

Response, on both endpoints:

    "friends_delta": {            each friend whose state changed after
      "deadbeef": {               the cursor, keyed by id. An entry is the
        "online": true,           friend's CURRENT state, whole - not a
        "playing": false,         description of what changed - so applying
        "latency": 31,            one blind is always right and a repeat
        "name": "KAI"             costs nothing
      }
    },
    "friends_at": 1784182417123,  the cursor for the NEXT read
    "friends_more": false         true: rows are still pending

`playing` means a duel that may be WATCHED, so a private one reads false
(see Announcing a duel) - it answers "can I ask for a feed", not "is this
person in a game", and there is no way to ask the second question about a
named person. It flips with the same freshness `online` has: on the
announcement, or when the duel window lapses. The whole answer is
AUTHORIZATION-GATED - only ACCEPTED friendships appear at all, so
possessing an id reveals nothing.

The cursor:

- Start at 0. That is not "nothing changed since the epoch", it is "I know
  nothing": the answer then carries every accepted friend, which is what a
  screen opening wants.
- Continue from the `friends_at` you were just given, never from your own
  clock. It is the server's now when the whole delta fit, and the stamp of
  the last row included when it did not.
- `friends_more` true means the per-response cap (64 rows) cut the answer
  short. Ask again at once with the new cursor rather than at the next
  tick.
- Rows sharing a stamp always travel together, so a capped page may carry
  a few rows more rather than split a tie. A repeated row is harmless; a
  dropped one would leave a friend on screen in the wrong state forever.

What is pushed and what is derived:

- Coming online, starting a duel and a rename are TRANSITIONS. They stamp
  the friend when they happen and they wake a held poll (see poll.php), so
  a subscriber sees them within the hold's check interval instead of at
  its next tick.
- Going offline and leaving a duel are DERIVED from the presence and duel
  windows at the moment the delta is read. Nothing wakes for them - they
  are the absence of a beat - and they are at worst one read late.

`friends_delta` and the `friends_*` maps are alternatives, not layers. A
request carrying `friends_since` is answered with the delta and no maps; a
request carrying `friends` is answered exactly as in 4.5. Sending both is
not an error - the delta wins - but there is no reason to.

`tourneys` is served only when the request set `"tourneys": true`, and
lists the OPEN lobbies whose host shares a NETWORK with the caller: the
same public IPv4 address, or the same IPv6 /64. The two are not
interchangeable - IPv4 is NATed, so a household shares one address, while
on IPv6 every device carries its own address out of the site's /64 and only
the prefix is shared. It is a network-local convenience, not a directory:
everything else is joined by `code`, and the code is the capability (see
Tournament mode).

One exception, and it is the point of it: an EVENT's open lobbies are
listed to that event's MEMBERS whatever network they are on (4.11), and
to nobody else. A lobby carrying an `eid` is an event's - see Events.

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

The announce window is deliberately wider than the 120 s presence window: a
host waiting in a lobby is a background tab or a phone with the screen off
as often as not, and browsers throttle background timers to about one a
minute.

### Pacing (`pace`, 4.4)

The beat is part of the contract. Three constants, stated here and not on
the wire, the same for every client:

    heartbeat   hello every 60 s, from a client that has nothing else in
                flight. Half the 120 s online window, so one missed beat
                never reads as offline. The server checks the window with
                one second of grace, so a beat that lands the odd second
                late still counts. Against a server reporting `api` 4.4
                or older, beat every 30 s: its window is 60 s.
                EVERY request is a beat (4.5), poll.php included. A client
                looping a poll is therefore already beating, and a hello
                beside it is a second request in flight for nothing (see
                the gap below). From 4.9 the poll carries everything the
                SERVER has to say unasked - `api` and `debug` on every
                body it sends, the pace and the counters beside them - so
                nothing a client cannot see coming rides on a hello. Two
                readings stay hello's and are NOT on the poll: `now` and
                `q_ms`. The clock source is t.txt, and a client that
                wants either asks with a hello. What is left is what the
                client itself knows is due and the server cannot: a
                rename, a latency reading, its `nets`, and `duel_with`
                during a game, where nothing is holding a poll anyway.
                Send a hello for those once the poll has answered (4.10 -
                see the gap). Otherwise the poll is the beat.
    poll wait   ask poll.php for `wait` of 5 s. The server serves any
                hold up to 9 s, the longest it keeps a worker for, so a
                client may ask for more; anything shorter is served as
                asked. A shorter hold re-arms more often, and cuts how
                long a pushed event, a tournament deadline or a withdrawn
                `pace.hold` can wait for the next poll; a longer one keeps
                a worker for longer.
    gap         keep at least 100 ms between any two requests THIS client
                has in flight, whichever endpoints they are. It separates a
                client's own requests from each other - a client that fires
                a heartbeat and a roster read in the same tick queues the
                second behind the first and pays the wait twice. It is
                SPACING, not a wait: it sits just above what a single
                request costs, so a stacked burst drains in milliseconds
                and no one call is ever held long enough for a player to
                feel it. Serialise background traffic through ONE gate and
                hold the gap there; let the duel handshake (signal, start,
                poll) past it, because that is the latency a player feels.
                Past the gate is not the same as together: a parked poll
                is an open request, so one exempt call already makes two
                in flight, and two exempt calls sent in the same tick race
                each other - BOTH pay the full queue wait rather than one
                of them paying it.
                ONE AT A TIME (4.10). While a poll is parked, a client
                should send nothing else that reaches a worker. What is due
                waits for the poll to answer - one hold at most - and goes
                then, or rides the next poll. Try very hard not to break
                this: it is the difference between a client that costs the
                host one worker and one that costs it two.
                A SECOND request beside a parked poll is allowed where
                waiting would be worse than sending - the duel handshake
                above all, where a signal or a start is the latency a
                player feels and the poll is what carries the peer's reply
                back, so it cannot be dropped to make room either. Send it
                when that is genuinely true, and knowing what it costs
                (below). Do not send it because it was convenient.
                A THIRD IS FORBIDDEN. Two of the client's own requests
                beside a parked poll is not a trade-off, it is a stack:
                they race each other and BOTH pay the full queue wait,
                which is the opposite of what the second one was sent to
                avoid. There is no case where three is right.
                Why the rule is that strict: a parked poll owns a request
                worker for its whole wait, so the request sent beside it
                can be the one that takes the host to a concurrency it has
                not served before, and it then waits for a worker to be
                created - about 130 ms on the deployment this contract is
                written for, once, and not again at that level. Folding a
                request into the poll beats sending it beside the poll,
                and waiting for the poll to answer beats both.
                A request that never reaches a worker is not a request for
                this rule. t.txt is a static file stamped by the web
                server, so it can
                never take the host to a concurrency it has not served and
                has nothing to gain by waiting: do NOT serialise the clock
                sweep behind a held poll - it would buy nothing and pay
                for it in stale anchors.

    screen tick The lobby, friends, MY ID and tournament-lobby screens
                refresh out of the poll they are already holding: `fs`
                makes it carry the friend delta, the presence counters and
                the hold decision (4.6), and `aa` / `fl` / `tl` carry the
                last three answers those screens needed a hello for (4.9).
                Against a 4.9 server they send nothing beside the poll at
                all - not even the 60 s beat, because the poll is one. Against
                a server older than 4.6 there is no delta: fall back to
                `friends` on hello at the screen's own tick, and feature-
                detect on the response, never on the version.

Only one thing depends on the moment, and that is all the `pace` object
carries. It is additive - a client that ignores it behaves exactly as it
does today.

    hold        whether this client may hold a long poll AT ALL. A held
                poll occupies a request worker for its whole duration, which
                makes this the real lever: when it is false, poll without
                waiting and lean on the heartbeat. It is withdrawn by
                tier - a client in a duel or reconnecting keeps it
                longest, then a tournament screen with a match pending,
                then one merely browsing the lobby.

Earlier 4.4 servers also sent `hello_ms`, `poll_ms` and `gap_ms` in this
object. They only ever carried the constants above; treat them, present or
absent, as exactly those.

None of this is enforced. The server never rate-limits, delays or refuses a
request for arriving too close behind another one - a heartbeat is how a
player stays online, and holding one back would occupy the very worker the
pace exists to free. The one thing the server acts on itself is `hold`, which
it withdraws by tier and Holds refuses outright when the budget is spent. So
a client that ignores the gap is not punished; it pays its own queue wait, and
pays it on the LAST request of a burst rather than the first. That is what
`q_ms` reports, and it is the only symptom there is.

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

Rules:

- Signals are DRAINED on delivery: each message is returned exactly once.
  The client must process every element of `signals` immediately.
- Cadence: hello every ~60 s from a client with nothing else in flight
  (see Pacing). hello is a complementary keepalive and only that. Every
  request a client makes is a beat (4.5) - poll.php included - so hello is
  the beat a client sends when it has nothing else to say: it keeps the
  player online and drains whatever the mailbox holds by then. A client
  looping a poll is already beating and owes no hello for presence. Nothing time-critical rides on it -
  a signal or a tournament event that matters now reaches a client
  through /api/poll.php, and hello merely catches what a client with no
  poll running would otherwise see a minute late.
- While a 1vs1 game is running, send `duel_with` in every hello. It
  refreshes a duel start.php has already put on record; it is not what
  puts it there (see Announcing a duel below).

### Announcing a duel

A duel is stated the way being online is: an edge in, an edge out, and a
window that expires when neither arrives. Three requests carry it, and the
first of them is not the heartbeat:

- **start.php SETS it.** Both peers call start.php at the moment play
  begins, so the duel is on record from that moment rather than from
  whichever beat happens next - and from two independent callers, so one
  peer's request being slow does not delay the other's side of it.
- **`duel_with` on hello REFRESHES it**, once a beat, which is what holds
  the offer up for as long as the match runs.
- **`duel_end` on hello CLEARS it**, naming the peer just left. Since 4.9
  poll.php's `de=` says the same thing, which is where a client that
  returns to a screen holding a poll can say it without a second request.
  An end for
  a peer the server does not have the caller playing is ignored, so an end
  overtaken by the next pairing cannot cancel it - and one hello may carry
  `duel_end` and `duel_with` together, because the end is applied first.

Absence clears NOTHING. A client that is closed mid-match never sends
another request, so the end cannot be the absence of a field. What bounds
that client is the window: the offer EXPIRES 91 s after the last
`duel_with`, comfortably above the 60 s beat that refreshes it. The DUEL
itself keeps the longer 120 s window, and an item claim's deadline is
measured from that (see Item registry) - so neither the teardown
announcement nor the shorter offer window ever shortens the window a claim
has.

`duel_private` marks a duel that is COUNTED but never ATTRIBUTED: it is in
the `playing` figure, holds the duel window, gets the same patience under
load and reaches the operator's dashboard exactly like any other, and it is
absent from a delta's `playing`. It
rides start.php and hello alike, and it is a property of the duel rather
than a latch: state it on every request that holds the duel up, because
leaving it off makes the duel public again from that request on.

## Debug mode

The server can turn a specific client's debug mode on remotely - an
operator sets it per player in the admin dashboard, to diagnose a client
in the field without asking its user to do anything.

Two separate bits are involved, and they are deliberately independent:

- **The instruction**, `debug` in the hello RESPONSE and (4.9) on every
  poll answer with a body. What the server wants. The client MUST
  honour it: `true` turns its debug mode on, `false` turns it off
  again. It arrives on the client's next hello, or on the next poll
  that reports `db` - answered at once when the two differ (see
  poll.php), so within one hold period - and never sooner than the
  client's next request.
- **The report**, `debug` in the hello REQUEST and `db` on the poll
  (4.9). What the client IS actually doing. Send `true` in every
  hello, and `db=1` on every poll, while debug mode is on, whatever
  turned it on.

They differ legitimately, and the admin view names each case: `pending`
is an instruction the client has not picked up yet, and `self` is a
client that enabled debug mode on its own (a developer, a local build).
A client must therefore never derive one from the other: report what is
true, honour what is asked.

What "debug mode" shows is entirely the client's business; the server
only carries the bit.

## GET /api/poll.php - fast signal poll

    GET /api/poll.php?id=c0ffee42[&wait=5][&fs=<cursor>]
                     [&aa=1][&fl=1][&tl=1][&de=<8-hex>][&db=0|1]

    -> 204 No Content                          nothing pending
    -> 200 {"ok":true,"signals":[...]}         pending messages, drained

With `wait` (seconds, capped server-side at 9) this is a LONG POLL: the
server holds the request open and answers the moment a signal arrives,
checking every 20 ms. This is the lowest-latency delivery path - during
an active handshake, loop `wait=5` requests back-to-back (the default
hold, see Pacing; anything up to 9 is served) and a relayed signal
reaches you in ~20 ms plus network, instead of a full poll interval.
Without `wait` it degrades to the plain cheap poll (one indexed read,
204).

A poll is a beat (4.5): like every other request it refreshes the
caller's presence, so a client looping poll.php stays online whether or
not its hello is on time - and needs no hello to stay online at all.

Every answer WITH A BODY carries `api` and `debug` (4.9), beside the
`signals` array:

      "api": "4.12",             the contract version, re-read here for
                                the same reason hello carries it: it
                                un-latches a client after a rollback
      "debug": false,           the server's debug instruction for this
                                client, exactly as hello answers it

Those two travel server to client ONLY. A client cannot know either is
due, so it can never be its job to ask - which is what makes a poll a
complete beat rather than most of one. `now` and `q_ms` are NOT here:
they are readings, and hello's (see Pacing).

`aa`, `fl`, `tl`, `de` and `db` (4.9), and `ev` (4.11), carry what a
screen holding this poll would otherwise send a hello for. Each is the
hello field of the same name, on the request the client is already
making:

    aa=1        arm auto-accept for ~120 s, as hello's `auto_accept`
                does. A poll can only ARM it; only a hello clears it
                early, and it expires on its own either way.
    fl=1        answer `friends`, the whole roster, as hello's
                `friends_list` does.
    tl=1        answer `tourneys`, the local tournament announce, as
                hello's `tourneys` does.
    ev=1        answer `events`, the caller's own events, as hello's
                `events` does (4.11).
    de=<8-hex>  the peer this client has just STOPPED playing, as hello's
                `duel_end` does (4.7). The screen a client returns to
                after a match is one holding this poll, so state it here
                and the WATCH row goes down with the match.
    db=0|1      what this client reports its OWN debug mode to be, as
                hello's `debug` does. ABSENT IS NOT FALSE here: a poll
                that did not mention it is not a client saying no, so
                absence changes nothing.

`fl` and `tl` ANSWER AT ONCE - a screen that just opened is not waiting
for a signal that is not coming - so a poll carrying either is a 200 with
`wait` effectively ignored. Either of them, and `fs`, also brings the
presence counters and `pace`: `pace.hold` is how the server withdraws
holding, and a client that has stopped beating has to be able to hear
that.

A poll that sends `db` also answers at once when the server's `debug`
instruction differs from what that `db` reported - a 204 has no body to
carry an instruction in, so an operator would otherwise lose reach to
exactly the client that is polling quietly. It settles itself: act on the
instruction, report the new state with `db` on the next poll, and the
holds resume. It happens at most once per hold period, because a
disagreement may legitimately STAND - an instruction of false against a
client whose user turned debug on locally - and a client that re-arms the
moment it is answered would otherwise never hold again. A client that
never sends `db` is never woken this way and behaves as it always did.

Feature-detect `fl` and `tl` on the response, never on the version.
`aa`, `de` and `db` are not visible in a 204, so a client that intends to
stop beating for them gates on `api` >= 4.9.

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
A tournament participant's poll also runs that tournament's deadlines
(see Tournament mode, When nobody answers); the request and the answer
are unchanged.

### Friend presence on the poll (`fs`, 4.6)

With `fs=<cursor>` the answer also carries the friend delta described
under hello (`friends_delta`, `friends_at`, `friends_more`), the presence
counters and `pace`, so a screen holding a poll needs nothing else to stay
current:

    -> 200 {"ok":true, "signals":[], "friends_delta":{...},
            "friends_at":1784182417123, "friends_more":false,
            "online":3, "playing":2, "registered":17, "pace":{"hold":true}}

Two things change, and only for a request that sends `fs`:

- A held poll returns when a friend TRANSITION lands as well as on a
  signal. One transition wakes every subscriber at once.
- `signals` can therefore be EMPTY in a 200. Read the two independently:
  the delta is not a signal and a signal is not a delta.

Nothing changes for a poll without `fs`. A 204 remains the answer whenever
the hold runs out with nothing pending and no transition; keep the cursor
you have and ask again.

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

    invite    ask "to" for a 1vs1 game            payload: JSON {"profile": <profile>}
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
    event     RESERVED - server-generated only     payload: JSON, see
              (clients cannot send it: 400)          The `event` signal
                                                     under Events (4.11)

The 'friend' signal is the friendship NOTIFICATION: the server delivers
it into the peer's mailbox when a friend request is created for them or
their request gets accepted. It arrives like any other signal (hello or
poll.php, long-poll included), so an online client learns of a request
within its poll cadence; an offline client finds the pending entry via
friend.php list on next start (mailbox signals expire after 120 s).

The 'undelivered' signal is the FAILURE RECEIPT for a connection attempt.
An invite / invite-relay / accept / accept-relay that nobody picks up
before it expires (signal_ttl, 120 s) is a failed attempt, so the sender
is told instead of waiting forever on the ok:true it got. It is addressed
"from" the peer that never collected the message and names the lost
"type". Treat it as "this attempt is dead": stop waiting, tell the user,
offer a retry. It is raised on the next mailbox read, so it arrives with
the sender's next hello. The reverse does NOT hold: no receipt is not a
delivery confirmation, only the absence of an expiry.

The 'peer-net' signal is a DIRECT-CONNECTION HINT. The moment a 1vs1
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

READING the roster does not need this endpoint. `list` is the one action
here that is not a mutation, and a client that polls it on a timer is
sending a second request alongside a heartbeat it was sending anyway - two
requests into the same instant, the second queued behind the first. Send
hello's `friends_list` flag instead and read `friends` off the heartbeat;
call friend.php only for the mutating actions below, which are
user-initiated and rare. `list` stays for clients that have no heartbeat in
flight and for the fallback path when a server does not answer the flag.

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

Player expiry: a player not seen for player_ttl_days (default 365,
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
1vs1 flow below, with the "offerer" acting as A.

## 1vs1 game flow (the intended sequence)

Player A wants to play with player B (A knows B's ID, e.g. from the
friend list; the hello `friends` field tells A whether B is online):

    1. A -> signal {type: "invite", to: B, payload: {"profile": ...}};
       A starts polling poll.php (~1 s). B's UI can now show who is
       asking, with name and snake look.
    2. B sees the invite in its hello poll (within ~60 s; within ~1 s if
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
       That start.php call is also what announces the duel, so it is
       on record from this moment and not from the next beat; the
       normal slow heartbeat (~60 s) carries duel_with afterwards to
       hold it up (see Announcing a duel).
    7. The further halts of the run - next level, respawn, resume from
       pause - are settled between the peers over the DataChannel. The
       server is not told and none of them calls for a sweep.
    8. Either side sends bye (via the DataChannel if open, and via
       signal as fallback) to end the session, and each side tells the
       server with a hello carrying duel_end: a bye that went over the
       DataChannel is the one end the server cannot see for itself. A
       rematch is a new pairing: it re-runs the handshake from step 1
       and opens a new epoch line at 0 (the invite/offer is what resets
       it server-side, precisely because a DataChannel bye never
       reaches the server).

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
"queued before any server code ran" (pool exhaustion). "created" stays whole seconds.

payload is opaque to the server (max 2 KB, defaults admin-configurable);
seq is a server-assigned increasing number for ordering. Keep sending
hello with duel_with during relayed games too, and duel_end when one ends. The concurrent-duel cap
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
| 409 | `counterfeit` | the claim names a non-owner as `from`: a stale wardrobe, typically a restored backup or an item lost in a duel the client has not synced since. Nothing moves, the instance stays where the registry has it and is never frozen, and the client drops it at its next `list`. Logged, not alerted |
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
contested item keep moving. The registry records which of the two
verdicts froze it and when, and the first verdict stands: a later claim
on an instance already out of play cannot rewrite the finding.

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

Outside a tournament, who is worth asking comes from a delta's `playing`
(see Announcing a duel). That answer is only as fresh as
the announcements behind it: it is the two peers stating the edges of their
own match, plus a 91 s window for the client that stops stating anything.
Nothing else on the server observes a duel, so a feed can always be gone by
the time the request lands.

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
- `abandoned`: the host left the lobby or ended it for everyone, nobody
  started it within `tournament_join_ttl` (15 min), or none of its players
  had been seen for `tournament_idle_ttl` (3 min) and the server ended it
  (4.8). The last one is why a client that goes away and comes back may
  find a `lobby` event saying `abandoned` with no one having pressed
  anything: every request is a beat, so a tournament reads as abandoned
  only when nobody at it has made ANY request for that long.

### Requests

Always POST, always `{"id": "<8-hex>", "action": "..."}` plus the action's
fields:

    create    {id, stakes?, replace?, lvl?, speed?, eid?}
                                     -> {ok, tid, code, stakes, lvl, speed,
                                         max, eid}
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

`lvl` (4.9, default 1, clamped to 1..`tournament_max_level`) is the level
ROUND 1 is played at; every round after it is one deeper, as always (see
The round ladder). Absent reads as 1, which is what every client sent
before the field existed. It rides the create's answer back, and nothing
else carries it: a player who JOINS learns the level from the first `roles`
sheet.

`speed` (4.10, default false) says every round of this tournament is
played as a speed round. Like `stakes` it is carried, never acted on: the
server has no idea what a speed round is, and what the two players do
with the flag is their business. It is a property of the TOURNAMENT and
is fixed at create - there is no way to turn it on later. Unlike `lvl` it
rides the lobby as well as the `roles` sheet, so a player can see it
before joining.

`eid` (4.11, optional) makes this an EVENT tournament: the caller must be
that event's organizer and the event must be active. It changes nothing
about how the tournament runs - the difference is who may join it (its
event's members, and 403 `not in the event` for anyone else, by tid and
by code alike), who is shown it in the local announce (its members, on
any network) and that it is archived on the event when it finishes. It
rides the create's answer, the lobby, the announce and the `roles` sheet;
absent or null means an ordinary tournament. See Events.

A host may hold one open-or-running tournament at a time (409), and may
create one every `tournament_create_cooldown` (429 with `retry_after`).

`replace` (4.8, default false) is the answer to that 409: it ends the
tournament the caller is hosting - exactly as their own `leave` would -
and opens the new one in the same call. Its players get the usual `lobby`
event, with `reason` "host opened a new one". Ask the player first: a
running tournament ends for everyone, not just for its host. The create
cooldown is charged BEFORE anything is ended, so a `replace` answered 429
leaves the tournament it would have replaced standing. A client that would
rather send `leave` and then `create` may still do so; `replace` exists so
that one cannot end up holding neither, having lost the second half.

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

Round 1 is played at the level the host chose, and every round after it
one level deeper:

    level = min(start + round - 1, tournament_max_level)

`start` is the create's `lvl`, 1 unless it said otherwise.
`tournament_max_level` is 10, the game's last level - above it there is no
harder board to reach, only one the client does not have, so a tournament
deep enough to run off the end stays at the cap.

Because `round` is the stage number, the FIELD decides how far the game
gets from the level it starts at. Three players play their start level and
one above it; eight play a group stage, semi-finals one deeper and a final
one deeper again. Half the
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
    speed       the create's `speed`, true on every match of a tournament
                that asked for it
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
evaluated lazily, on the next request that touches the tournament: any
tournament.php request, or any participant's poll.php or hello.

- a held one-sided result settles after `tournament_result_ms` (15 s)
- the match in flight is forfeited after `tournament_walkover_ms` (3 min)
  by a player who is ALSO offline. A slow match between two players who
  are both present is never taken away from them.
- a match NOBODY EVER STARTED is re-dealt once after
  `tournament_deadlock_ms` (2.5 min) and voided at the same distance
  again. This is the case presence cannot see: both players are awake and
  asking, and it is the link between them that never comes up. The server
  knows it apart from a slow match because both peers call start.php
  where play begins, so a pair that got a match going is left alone
  however long it runs.
- an unstarted lobby is abandoned after `tournament_join_ttl` (15 min)
- a round break continues by itself after `tournament_break_ttl_ms`
  (2 min), so a host that walked away cannot wedge the tournament

So the mailbox drain a participant makes anyway is what keeps the clock
moving, and whatever a deadline produces - a settled result, the next
roles sheet - is in that same answer. With a held poll that is one hold
at worst - 5 s, or up to 9 s for a client asking for the longest hold;
with hello alone, about 60 s. Nothing is added to the wire for
it, and a client never calls `state` for timekeeping: `state` is for a
reload, a rejoin, or genuine doubt that an event was missed, and nothing
else.

A player who forfeits by LEAVING loses their remaining matches as
walkovers at once. Being walked over for absence is not the same thing:
it settles that node only, and every later node of theirs is dealt
normally and waits its own full `tournament_walkover_ms`, testing again
whether they are still offline - so a player whose phone wakes up is back
in the schedule. A node where BOTH sides are gone is voided: no points,
no difference, no winner. So is one neither present player could ever
connect, after the re-deal above has been spent - both turned up, so
there is nobody to name as the winner, and the bracket treats it as any
other node that was not played.

A walkover names a winner with `"score": null`, which is what keeps it out
of the score-difference tie-break. It advances in a knockout and takes the
full point in the round robin exactly as a played win does, and the
standings ride the `result` event with it.

### state - the full read-back

`state` returns everything a client needs to draw the tournament from
nothing, for a reload or a rejoin. Events elsewhere are deltas; this is
the whole picture.

    {
      "ok": true,
      "event": "lobby",           the lobby fields are carried verbatim,
                                  this one included - ignore it here
      "tid": "<32-hex>", "state": "running", "code": "K7QMX2",
      "host": "c0ffee42", "stakes": false, "speed": false, "max": 8,
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

    lobby        {event, tid, state, code, host, stakes, speed, max,
                  players:[{id,name}], reason?}
                 someone joined or left; `reason` explains an abandon
    roles        {event, tid, round, stage, match, of, nid, hm, lvl,
                  speed, stakes, players, feeder, primaries, secondaries,
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
    result       {event, tid, nid, winner, draw, score,
                  rows:[{seat,id,pts,diff,rank}]}
                 a node settled (winner is null for a draw or a void).
                 `rows` are the standings after it, in the `standings`
                 event's row shape without `adv`: the result is applied
                 from the event and nothing is re-read
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
makes, eight-handed - so the server staggers them by seat: 100 ms per
seat, seat 0 waits nothing, the eighth seat 700 ms, never more than
1000 ms. Render the event itself immediately and delay only the calls it
provokes. A client that ignores the field behaves exactly as before.

A client MUST NOT act on a `tourney` signal it did not expect to the
extent of playing a match it cannot see in `state` - when in doubt, call
`state` and render that.

That is the doubtful case only. An expected `roles` event already carries
the whole sheet a match needs - `nid`, `hm`, `lvl`, `speed`, `stakes`,
`players`, `feeder`, `primaries`, `secondaries`, `names` and `you` - and a
`result` carries the standings it moved, so `state` is not a routine
follow-up to either, and least of all one made alongside the `start.php`
that a `roles` event prompts. One event, one call.

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
    result            152      758    115216  a node settled
    round              16     1645     26136  a round boundary
    lobby              35      645     18648  a join, a leave, an abandon
    standings           8      853      6824  round 1 is over
    over                8      187      1496  the podium
    -----------  --------  -------  --------
    TOTAL             523              316600

317 KB for the entire tournament across all eight clients - about 40 KB
each for 19 matches. The largest single push is the round-break scoreboard
at ~1.6 KB; the steady ones are the roles sheet and the result, ~0.75 KB
each per match per participant.

The result is that size because it carries the standings rows (server
1.4.17; 211 B without them). That buys out the `state` read a client
would otherwise make after every settle - 152 of them at ~5 KB, ~760 KB,
more than twice the whole event stream - and the burst those make,
eight-handed, 19 times a tournament.

The request side is smaller. Response bodies, same run:

    create       90 B     join        495 B     start       11 B
    result       45 B     continue     11 B     standdown   11 B
    orphan       11 B     state      4997 B

`state` is the exception, and the one thing to get right: a full read-back
is ~5 KB, seven times a `roles` event. It exists for a reload, a
reconnect, or a client that believes it missed an event - NOT as a poll,
and not for timekeeping either: the deadlines run on the drain (see When
nobody answers).
Eight clients polling `state` once a second would cost more server egress
every second than the whole tournament costs in pushed events.

So the RATE has no tournament term in it at all. The only periodic
requests are the ones a client already makes: hello every ~60 s (a
108-byte response, 122 with `tourneys`), and back-to-back poll.php long
polls during a signaling window, which answer 204 in about 196 bytes of
headers when nothing is pending. `orphan` is separately capped at one
every 3 s per player.

What the rate DOES have is a BURST term, and 4.4 addresses it in both
places it appears: `after_ms` staggers the follow-up calls a pushed event
provokes, and the 100 ms gap spaces a client's own. Neither saves bytes. Both
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
so it never expires under players who are still playing - a finished one
after `tournament_done_ttl`, which is the window in which its bracket can
still be read back, and an abandoned one after
`tournament_abandoned_ttl`, which is shorter because there is no finished
bracket to come back to. After that the tid is simply unknown, and `state`
answers 404.

## Events (4.12)

An EVENT is a room an operator opens on the server: a LAN party, a club
night, a stand at a fair. A player gets in by scanning its QR code -
straight in when the event is OPEN, after the organizer approves them
when it is CLOSED. Inside, the organizer runs tournaments only members
can see or join, past tournaments are archived on the event, joining
grants a secret achievement, and any member can pass the event on with a
QR that lives 20 seconds.

THE SERVER IS THE ROSTER. Membership is rows on the server and nothing
else: a client reads the roster fresh whenever it shows it, keeps no
copy, and reconciles nothing at startup. A member learns it was removed
by the event no longer being in its list. This is deliberately NOT the
friends-list pattern - a local copy plus a startup reconciliation is what
makes a restored config fire a burst of requests.

An event tournament is an ORDINARY tournament: same lifecycle, same
bracket, same deadlines, same caps, same requests (see Tournament mode).
`eid` on it is a tag and a membership check on the way in, nothing more.

CHANGED IN 4.12, and it is the only change since 4.11: the printed key is
11 characters and names its own event, so `join` takes `{id, code}` with
no `eid` beside it. 4.11 had a 16-character key in a 63-byte URL, which no
version 3 code can hold - so the poster could not be read by the game's own
scanner, which was the whole point of it. Nothing else moved.

### Identifiers

    eid     4 chars, alphabet 23456789ABCDEFGHJKMNPQRSTUVWXYZ. The
            event's public name on the wire. It grants NOTHING on its
            own: every action but `join` answers 404 for a caller with
            no row, so an eid alone cannot even tell you an event
            exists.
    key     11 chars, same alphabet. The long-lived code, printed on
            the event's poster and nowhere else. It NAMES ITS OWN
            EVENT - there is no eid beside it - because 11 characters
            is the entire budget (see The URL a QR carries). It is in
            no JSON answer this API can produce, for anybody, ever.
    pass    6 chars, same alphabet. The live code a member shows on
            screen. It is derived from the server's clock, so it is
            valid for 20 s and no row is stored for it.
    ach id  `ev_<eid>` - the achievement joining grants.

`key` and `pass` are both called `code` on the wire and the server tells
them apart by LENGTH (16 or 6). A client never has to know which it
scanned.

### The URL a QR carries

One shape, and the code is one of two things:

    https://poeggi.github.io/FOK-snake/#event=<eid>.<pass>   a pass
    https://poeggi.github.io/FOK-snake/#event=<key>          the key

Both are 11 characters, so both URLs are 53 bytes. THE DOT IS WHAT TELLS
THEM APART: a pass carries the eid in front of its own dot, and a key has
no dot because it names its own event.

A phone's camera opens the game, which IS the event page - there is no
landing page on the server. The client parses the hash on load and in
its own scanner, exactly as it already does for `#friend=` and
`#tourney=`.

THE BUDGET, and why the identifiers are the length they are: every QR an
event shows has to be readable by the GAME'S OWN SCANNER, which falls back
to a decoder built for a fixed QR version 3 at level L wherever the browser
offers no native one - 53 text bytes, no more. The URL prefix is 42 bytes,
which leaves 11 for the code, and 53 in all. Not one byte spare.

That is why a pass names no issuer (4 + 1 + 6 is already the whole of it),
and why the printed key is 11 characters carrying no eid rather than a
longer code beside one. The server encodes the printed QR at exactly
version 3 / level L / mask 0 for the same reason: a poster nobody can scan
in the app is the wrong poster.

### State

An event's state is DERIVED at read time, never swept: there is no cron
here, so nothing fires at a scheduled moment and nothing needs to.

    upcoming   visible to its members; nobody can join yet, no passes,
               no tournaments
    active     joins, passes and tournaments
    paused     no joins, no passes, no new tournaments; a tournament
               already running plays on
    ended      FROZEN and terminal: no joins, no passes, no new
               tournaments, and the event never leaves this state. A
               tournament RUNNING at the moment of the end finishes
               normally and is archived - it began while the event was
               live, and a clock must not stop two players mid match.

A SCHEDULED event has `starts` and/or `ends` and walks itself: upcoming
before `starts`, active after it, ended at `ends`. Nothing is pushed
when either moment arrives - a client derives the state from `starts`,
`ends` and the server clock, all three of which ride every answer. The
organizer cannot run, pause or end a scheduled event (409 `scheduled`);
the operator can end one.

An UNSCHEDULED event is driven by its organizer with `run`, `pause` and
`end`.

`state` on the wire is always the DERIVED value. `starts` and `ends` are
unix MILLISECONDS like every other timing value in this API, because a
client compares them against the same clock as `now`. Dates that are
only ever displayed - `asked`, `joined`, `finished` - are unix SECONDS,
like `created` everywhere else.

### The door: open or closed

`closed` is a property of the event. It decides what a scanned code
does and nothing else about the event changes with it:

    open     the code makes the scanner a MEMBER at once, and the
             answer carries the achievement
    closed   the code makes the scanner PENDING. The organizer is told
             (an `event` signal, `request`), and approves or declines.
             Approval makes the row a member and tells the requester
             (an `event` signal, `accepted`). A DECLINE drops the row
             and tells nobody - the friend logic exactly.

The code stays the only way in under both doors: nobody can ask to join
an event whose code they have not scanned, and an organizer approves,
never adds.

A PENDING caller sees the PUBLIC FACE of the event and nothing else:
name, description, organizer, schedule, state, and that it is waiting.
No member count, no member list, no tournaments, no archive, no pass, no
achievement.

Flipping a closed event open does NOT approve what is already pending -
the organizer still decides those, while new scans go straight in.

TWO DIFFERENT WORDS FOR TWO DIFFERENT WAITS, and they are not the same
thing: an EVENT before its start is `upcoming`; a MEMBER ROW before
approval is `pending`. Both appear in one answer, as `state` and
`you.state`.

### POST /api/event.php

Always POST, always `{"id": "<8-hex>", "action": "...", "eid": "<4>"}`
plus the action's own fields. `eid` is required by every action EXCEPT
`join`, which takes the scanned code instead and finds the event from it -
a printed key has no eid to send.

    join      {id, code}        -> {ok, ...state fields}
                                   the only action that takes a code,
                                   and the only way to get a row. It
                                   takes NO eid: post the code exactly
                                   as it was scanned and the server
                                   reads the event out of it.
                                   Idempotent: a repeat answers what the
                                   first did, achievement included, so a
                                   client that lost the response simply
                                   asks again
    state     {id, eid}         -> {ok, ...} see below
    members   {id, eid}         -> {ok, members: [...]}
    pass      {id, eid}         -> {ok, step, valid, slots: [...]}
                                   any member, while the event is active
    monitor   {id, eid}         -> {ok, ...} takes or renews the event's
                                   one monitor slot and answers the whole
                                   screen. See The monitor below
    leave     {id, eid}         -> {ok} the caller removes its own row.
                                   A pending caller withdraws the same
                                   way. The organizer cannot leave its
                                   own event (403). The row is gone and
                                   the person may scan again
    roster    {id, eid, peer, set}
                                -> {ok} organizer only
    access    {id, eid, closed} -> {ok} organizer only, flips the door
    run       {id, eid}         -> {ok} organizer, unscheduled only
    pause     {id, eid}         -> {ok} organizer, unscheduled only
    end       {id, eid}         -> {ok} organizer, unscheduled only.
                                   TERMINAL

`state` answers the caller's whole view of the event:

    {
      "ok": true,
      "eid": "K7QM",
      "name": "Snake Night",
      "descr": "Every Thursday, back room",
      "organizer": "c0ffee42",         may be null (see below)
      "organizer_name": "KAI",
      "closed": false,
      "state": "active",               derived: upcoming|active|paused
                                       |ended
      "starts": 1784182417000,         unix ms, or null
      "ends": null,                    unix ms, or null
      "monitor_allowed": true,         whether this event offers a
                                       monitor at all (see The monitor)
      "now": 1784182417123,            server clock, so the state above
                                       can be re-derived locally
      "you": {"state": "member",       member|pending|monitor
              "organizer": false},
                                       everything below: MEMBERS ONLY
      "members": 14,                   how many have joined
      "ach": {"id": "ev_K7QM",         the achievement, see below
              "name": "NIGHT OWL",
              "desc": "Joined Snake Night",
              "icon": {...}},
      "tourney": {"tid": "<32-hex>",   the event's live tournament, or
                  "code": "K7QMX2",    null. Same fields the tournament
                  "state": "open",     announce carries
                  "players": 3,
                  "max": 8},
      "archive": [                     newest first
        {"tid": "<32-hex>", "finished": 1784100000, "seats": 8,
         "played": 7,
         "podium": [{"id": "c0ffee42", "name": "KAI"}, ...]}
      ]
    }

AN ORGANIZER IS PRE-SUBSCRIBED TOO, and is an ordinary member: it is named
rather than admitted, so it has a row from the moment it is named and the
event is in its `events` list without it scanning anything. It is a
participant like everybody else, and it cannot `leave` its own event.

`you` ALWAYS DESCRIBES THE CALLER'S OWN ROW, in every answer that carries
it - `state`, `monitor` and the `events` list alike - and never the role
the caller is playing at that moment. So a member who has taken a free
monitor slot still reads `member`, and the organizer still reads
`organizer: true` while running the screen. Only a RESERVED monitor reads
`monitor`, because that is what its row says.

`organizer` is null when the player who ran the event has expired. The
event keeps running on its schedule; nobody can open a tournament or
work the door until an operator names a new one.

`members` (the action) answers the roster:

    {"ok": true,
     "members": [
       {"id": "c0ffee42", "name": "KAI", "state": "member",
        "joined": 1784100000, "organizer": true, "friend": "none"}
     ]}

Every id carries its name. `state` is `member` for everyone; `pending`
and `banned` rows are answered to the ORGANIZER only. `friend` is
`none`, `pending` or `accepted` and is what an "ask to be friends"
button reads - the request itself is friend.php, unchanged.

The roster names NO ONLINE STATE. Presence is friendship-gated in this
API and stays that way: being in the same room does not make two people
friends.

`roster` is the organizer's one verb over a row:

    set  "member"   approve a pending row
         "none"     decline a pending row, remove a member, or lift a
                    ban. The row is dropped and the person may scan
                    again
         "banned"   the row stays and every scan answers 403

`peer` must already have a row: a peer with none is 404. The organizer
APPROVES, never adds. A `set` that changes nothing answers ok, like a
repeated friend accept.

`pass` hands out the next six 10-second slots at once:

    {"ok": true, "step": 10, "valid": 20,
     "slots": [{"at": 1784182410000, "code": "H3KM9P"}, ...6]}

`at` is the server-clock millisecond the slot begins, `step` how far
apart the slots are and `valid` how long each code is accepted for -
both in SECONDS, and both read from the answer rather than hard-coded,
because they are admin-configurable. The overlap is deliberate: a code
stays valid for two slots, so a code read off a screen still works while
the screen has already moved on.

Six slots is one minute of QR, which is one request a minute for a
screen that rotates locally on the synced clock. Ask again before the
last slot lapses.

A pass names NO ISSUER. The server never learns who passed an event on,
and cannot: there is no room for an issuer in 53 bytes.

### The monitor

An event can offer ONE MONITOR: a screen somebody puts on a TV in the
room. It shows the event live and, once a tournament is running, becomes
an invisible spectator of it - it sees the match and follows the bracket,
and it never plays, is never seated and is in no participant list.

It is meant to be left alone. Nothing on it is ever pressed, and it holds
its place by asking; it gives it up by stopping.

ASKING IS NOT TAKING. Whether an event offers a monitor is
`monitor_allowed` on `state` and on every row of the `events` list, so a
client decides whether to offer the screen at all without touching the
slot. The `monitor` call is the one that CLAIMS it - do not use it to find
out. Whether the slot is free is not asked in advance and cannot usefully
be: it is answered by taking it, or by 409 `monitor taken`.

    monitor  {id, eid}  -> {ok, ...the public face, plus:
                            "now": <server ms>,
                            "you": {...},         the caller's ROW, as
                                                  everywhere else - see below
                            "members": 14,        who has joined
                            "pending": 3,         who is waiting to be
                            "reserved": true,     this event names its screen
                            "archive": [...],     as `state` answers it
                            "tourney": {...}}     see below, or null

ONE REQUEST DOES EVERYTHING. It takes or renews the monitor slot and
answers the whole screen, so a monitor polls this and nothing else.

`tourney` is the WHOLE tournament projection - the same object
`tournament.php state` gives a participant: lobby, schedule, bracket,
standings, the round break and `roles`. `roles.you` reads `idle`, because
a monitor has no seat.

SPECTATING IS THE ORDINARY SPECTATOR PATH. `roles` names the two players
of the match in flight; the monitor asks one of them to watch with the
ordinary `watch` signal and the feed is peer to peer, exactly as it is for
a tournament spectator. No match traffic passes through the server for a
monitor either.

Reading the monitor NEVER settles a deadline. Everywhere else a request
from a participant is what runs a tournament's clock; this one is inert on
purpose, because a screen on a wall must not be what forfeits somebody's
match. The players' own requests do that.

TWO WAYS THE SLOT IS HELD:

- RESERVED. The event names a player as its monitor. That player holds the
  slot whether or not it is switched on - a screen in a hall is still that
  hall's screen while it is dark - and no one else can take it.
- FREE. Whoever asks first holds it, and keeps it while it keeps asking.
  Stop asking and it lapses within the online window, so an unplugged TV
  frees the slot with nobody pressing anything.

A RESERVED MONITOR IS PRE-SUBSCRIBED and is NOT A PARTICIPANT. Naming it
is granting it access: it has its row from that moment, so the event is in
its `events` list before it has scanned anything, and a scan is admitted
straight away even at a closed door - the event named it, so there is
nobody left to approve it. From then on:

- `you.state` is `monitor`, on `state`, on `monitor` and in the `events`
  list alike - which a FREE monitor's is not: that caller is an ordinary
  member (or the organizer) holding a lease, and every answer says so.
  `reserved` in the monitor answer is what tells the two apart;
- it is in NO member list and in no member count;
- it IS in the event's audience: the `event` signal reaches it and so do
  the event's open lobbies in the local announce, because a screen that
  is not told what changed shows the wrong room;
- it is granted no achievement: the achievement is for joining, and a
  screen was posted rather than joined;
- it may call `state` and `monitor` and NOTHING else. Every other action
  answers 403 `monitor only`.

Errors:

    403  "no monitor"     the event does not offer one
    403  "monitor only"   a monitor tried anything but state or monitor
    403  "not a member"   a pending row asked for the screen
    409  "monitor taken"  somebody else holds the slot

### Errors

    400  bad input (unknown action, malformed id, eid or code)
    403  "banned"            the caller's row is banned
    403  "not the organizer" an organizer-only action
    403  "not a member"      a members-only action from a pending row
    404  "no such event"     no such eid, OR a wrong key, OR a wrong or
                             expired pass, OR the caller has no row for
                             an event that does exist. ONE answer for
                             all four, so nothing can be enumerated
    409  "not started"       the event is upcoming
    409  "paused"
    409  "ended"
    409  "scheduled"         run/pause/end on a scheduled event
    409  "monitor taken"     somebody else holds the monitor slot
    403  "no monitor"        the event offers no monitor
    403  "monitor only"      a monitor tried anything but state or
                             monitor
    429  "too many attempts" too many wrong codes; `retry_after` is
                             seconds

A wrong code is throttled per player id, not per event: a client that
mistypes or scans something stale a few times is fine, a client walking
the keyspace is not. The codes are far too large to guess - the throttle
is there so the attempt is on record.

### On hello and poll

    hello body   "events": true
    poll query   ev=1

Either one adds the caller's own event rows to the answer:

    "events": [
      {"eid": "K7QM", "name": "Snake Night", "closed": false,
       "state": "active", "starts": null, "ends": null,
       "monitor_allowed": true,
       "you": {"state": "member", "organizer": false},
       "members": 14}
    ]

Member, pending and monitor rows are all in it, told apart by
`you.state`;
`members` (the count) rides a member row only. This list is HOW A CLIENT
KNOWS it is in an event at all - show the menu entry while it is
non-empty, hide it when it is empty - and how a removed member finds
out: the row is simply gone.

Ask for it on the hello or poll that precedes a screen that needs it,
not on every beat.

### The `event` signal

`event` is a RESERVED signal type: server-generated only, and a client
that sends one is refused with 400, exactly like `tourney`. It rides the
ordinary mailbox, so it arrives with a hello or a poll like anything
else. It goes to the event's AUDIENCE - its members and its monitor, the
same set the local announce serves - unless the line below says otherwise.
Every payload carries `eid`:

    {"event": "state",   "eid": "K7QM", "state": "paused"}
        when the organizer runs, pauses or ends the event. Not sent for
        a SCHEDULED event's own moments: those are derived, and nothing
        has to happen for them to arrive.
    {"event": "tourney", "eid": "K7QM", "tid": "...", "code": "K7QMX2"}
        when the organizer opens a lobby.
    {"event": "tourney", "eid": "K7QM", "tid": "...", "over": true}
        when that lobby stops being the event's live one - it finished,
        the host ended it, or an operator did. `code` is absent here and
        `over` is absent above, so the two are told apart by either.
        BEST EFFORT: a lobby that simply EXPIRES unattended announces
        nothing, because nothing runs for it. Both are a hint to re-read
        `state`, whose `tourney` is the truth - and re-reading on the
        hint is cheaper than polling for the same news.
    {"event": "request", "eid": "K7QM", "from": "c0ffee42"}
        to the ORGANIZER, when a closed event gets a pending row.
    {"event": "accepted", "eid": "K7QM"}
        to the requester, when the organizer approves them. No other
        fields: read `state`, which now carries the achievement.

Nothing is signalled for a join into an OPEN event (the count is read,
not pushed), for a decline, or for a removal - the friend logic again:
what did not happen is not announced.

### Tournaments inside an event

An event tournament is an ordinary tournament in every respect. The
whole difference is:

- `create` takes an optional `eid`. The caller must be that event's
  organizer and the event must be active, or the create is refused.
  The answer, the lobby projection, the announce card and the roles
  sheet all carry `eid` back.
- `join` by tid OR by code refuses a non-member with 403 `not in the
  event`. That is the entire secrecy: the code is no use to somebody
  who is not in the room.
- The local announce (hello `tourneys`, poll `tl=1`) carries an event's
  open lobbies to its AUDIENCE regardless of network, so an event
  tournament shows up on the normal tournament screen too. To everyone
  else it does not exist.
- A MONITOR is in that audience, so a screen watching an event learns of
  a new lobby on the poll it already holds rather than on its next
  `monitor` call. It still cannot join one, by tid or by code: joining
  tests membership, and a monitor is not a member.
- When it finishes - or is abandoned after at least one match was
  played - it is archived on the event and appears in `state`'s
  `archive`.

Caps and shape are unchanged: 2 to 8 players, one tournament at a time,
one live per host. A MONITOR NEVER TAKES A SEAT and never counts towards
that cap - it cannot join at all, by tid or by code, so a tournament with
a screen watching it still seats eight players.

### The achievement

Joining an event grants an achievement the client renders from what the
server sends:

    "ach": {"id": "ev_K7QM", "name": "NIGHT OWL",
            "desc": "Joined Snake Night",
            "icon": {"p": [...], "d": "..."}}

`icon` is optional and is in the client's own 8x8 icon shape; without
one the client uses its default. `ach` rides the `join` answer AND every
member's `state` answer, so a reinstalled or restored client re-grants
it silently instead of losing it. The server records nothing about
having granted it: BEING A MEMBER IS THE RECORD.

It is SECRET. It is never listed before it is earned, never carried to a
pending row, and nothing a non-member can read hints at it.

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
