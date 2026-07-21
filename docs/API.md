# FOK-server API

Definition of the HTTP API that game clients (currently FOK-snake) use.
This is the contract: anything not documented here is not part of the API
and may change without notice.

- Base URL: `https://fok-server.poggensee.it`
- Staging instance (same API, own database): `.../staging`
- Server source of truth: this repo, `public/api/`

## Versioning

Two versions exist and both are exposed by `GET /api/version.php`:

    {"ok":true, "server":"<x.y.z>", "api":"3.2", "env":"live"}

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
optional feature (e.g. the peer-net hint, added in 3.1) is available.

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
    3. Report the AVERAGE of the remaining samples, rounded to ms -
       a stable value, not a single noisy reading.

Report with the next hello after measuring; re-measure at least when
entering the multiplayer screen and every few minutes while online.
Valid range 0..60000; omit the field between measurements (the server
keeps the last value).

### POST /api/start.php - server-issued start of play

    POST {"id": "c0ffee42", "peer": "deadbeef", "epoch": 3,
          "reason": "respawn", "pts": 1784190295120}
      -> {"ok":true, "start_pts": 1784190295323, "epoch": 3,
          "now": 1784190295123}

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
      "debug": true               optional bool: whether the client IS in
                                  debug mode right now (absent means it is
                                  not). See Debug mode below.
    }

Response:

    {
      "ok": true,
      "api": "3.2",               contract version, see Versioning
      "now": 1784182417123,       server PTS clock, unix MILLISECONDS
                                  (free coarse re-sync on every heartbeat)
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
      "friends_name": {"deadbeef": "KAI"}    last reported display name
    }

The friends_* maps are AUTHORIZATION-GATED: real values are served only
for ids with an ACCEPTED friendship to the caller (see Friendships);
any other id reads as offline/null, so possessing an id alone reveals
nothing.

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

With `wait` (seconds, capped server-side at 9 by default,
admin-configurable) this is a LONG POLL: the server holds the request
open and answers the moment a signal arrives, checking every 20 ms. This
is the lowest-latency delivery path - during an active handshake, loop
`wait=9` requests back-to-back and a relayed signal reaches you in ~20 ms
plus network, instead of a full poll interval. Without `wait` it degrades
to the plain cheap poll (one indexed read, 204).

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
          "date": "16.07.26",      DD.MM.YY, same format as the local list
          "created": 1784182950    unix seconds, for exact ordering
        }
      ]
    }

Entries carry the same fields as a FOK-snake local top-10 entry
(name, score, level, diff, color, shopItems, date) plus rank, player_id
and created. Sorted by score descending, ties broken by earlier
submission.

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
    bye       leave / abort the session           payload: ""
    chat      text message (max 120 bytes total)  payload: plain text
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
      -> {"ok":true,"state":"pending"}      recorded; peer sees it in list
      -> {"ok":true,"state":"accepted"}     when the peer had already
                                            requested me (auto-match), OR
                                            when the peer is currently on
                                            the QR/add-friend screen
                                            (hello auto_accept flag): being
                                            there is the consent, the
                                            handshake completes instantly
                                            and both sides get an
                                            'accepted' notification
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

Rate limit: a client whose UNANSWERED requests exceed a threshold
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
    5. Both sides exchange ice messages as candidates arrive.
    6. When the DataChannel opens on both ends, BOTH clients stop
       polling poll.php, sync the clock (t.txt), and EACH calls
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
contribution is small (about the poll interval, and roughly a millisecond
when the hub runs on shared memory); the rest is client poll cadence, round
trips and the wider internet. Relay INPUT events, state hashes and control
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

    GET /api/relay.php?id=me&peer=opponent&wait=9
      -> {"ok":true,"messages":[{"seq":n,"payload":"...","created":s,"age":ms}]}
         oldest first, delivered exactly once
      -> 204 after the hold when nothing arrived (loop wait=9 requests
         back-to-back while in relay mode, like poll.php)

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
  fails loudly. Everything else (ice, chat, bye) expires silently: those
  belong to a handshake the client is already timing out on its own.
- Use a public STUN server (e.g. stun:stun.l.google.com:19302) in the
  RTCPeerConnection config. There is NO TURN server: this server forwards
  the signaling (SDP/ICE) and nothing else, and sees no game traffic once
  the DataChannel is open. When P2P cannot connect, WebRTC is ABANDONED
  rather than relayed - the duel falls back to relay.php and plain HTTP
  messages (see "Relay fallback"). "P2P failed" is the switch to the hub,
  not the end of the match; only a failing relay ends it.
- Signaling payloads fit the 16 KB limit; send one signal per ICE
  candidate rather than batching.

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
