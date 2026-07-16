# FOK-server API

Definition of the HTTP API that game clients (currently FOK-snake) use.
This is the contract: anything not documented here is not part of the API
and may change without notice.

- Base URL: `https://fok-server.poggensee.it`
- Staging instance (same API, own database): `.../staging`
- Server source of truth: this repo, `public/api/`

## Versioning

Two versions exist and both are exposed by `GET /api/version.php`:

    {"ok":true, "server":"<x.y.z>", "api":2, "env":"live"}

- `server` (FOK_SERVER_VERSION) is the implementation version; it bumps with
  every release and is informational.
- `api` (FOK_API_VERSION) is THE CONTRACT version of this document. It
  bumps only on breaking changes (fields removed, semantics changed);
  additive changes never bump it.

Clients MUST check `api` at startup (version.php, or the `api` field
that every hello response carries) and disable online features with a
friendly notice when it is newer than what they were built against,
rather than misbehave against an incompatible server.

## Conventions

- All endpoints speak JSON. POST bodies are JSON documents
  (`Content-Type: application/json`), responses are JSON objects.
- Every response contains `"ok": true` or `"ok": false`. On failure the
  object is `{"ok": false, "error": "<short reason>"}` with an HTTP status
  of 400 (bad input), 401 (auth), 404 (unknown), 405 (wrong method),
  429 (rate cap, see below) or 500 (server fault). Clients must treat any
  non-`ok` answer as a soft failure: log it, back off, never crash
  gameplay.
- Abuse caps returning 429 (defaults, admin-configurable): a recipient's
  signal mailbox holds at most 128 pending messages, and a player may
  submit at most 10 scores per 5 minutes. Normal play never reaches
  either; on 429, stop and retry later instead of hammering.
- Player identity is the FOK-snake player ID: a 32-bit value encoded as
  exactly 8 lowercase hex chars, e.g. `"c0ffee42"` (regex
  `^[0-9a-f]{8}$`). It is a PUBLIC identity, not a secret. A per-session
  secret token is planned but not part of this version.
- CORS: browsers may call the API from `https://poeggi.github.io` and
  `http://localhost:8000` / `http://127.0.0.1:8000`. Other origins are
  not sent CORS headers.
- Clients must gate ALL calls on the user's offline setting
  (`!cfg.offline` in FOK-snake): when offline is ON, never contact the
  server.
- Timestamps: ALL timing/sync values are unix MILLISECONDS - `pts`,
  time.php's `t`, and hello's `now` (the same PTS clock everywhere).
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

### GET /api/time.php - clock sync (REQUIRED before starting a game)

    GET /api/time.php  ->  {"ok":true, "t": 1784190295123}

The endpoint touches no database, so its latency is minimal and stable.
Clients MUST sync when initiating an online game (and should re-sync
periodically during long sessions):

    1. Record local time t0 (performance.now()).
    2. GET /api/time.php -> t. Record local time t1 on arrival.
    3. rtt = t1 - t0;  offset = t + rtt/2 - t1_wallclock
    4. Repeat ~5 times, keep the offset from the sample with the
       LOWEST rtt. localToPts(x) = x + offset.

Both clients now share a PTS base accurate to roughly rtt/2 (a few ms
on typical connections) - enough for frame- and audio-level sync.

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

    1. Take at least THREE samples: rtt of GET /api/time.php each
       (reuse the clock-sync samples - same requests).
    2. If the FIRST value is an extreme outlier (cold connection: DNS,
       TCP and TLS setup make it much larger), discard it.
    3. Report the AVERAGE of the remaining samples, rounded to ms -
       a stable value, not a single noisy reading.

Report with the next hello after measuring; re-measure at least when
entering the multiplayer screen and every few minutes while online.
Valid range 0..60000; omit the field between measurements (the server
keeps the last value).

### POST /api/start.php - server-issued level start

    POST {"id": "c0ffee42", "peer": "deadbeef"}
      -> {"ok":true, "start_pts": 1784190295323, "now": 1784190295123}

When both peers are ready (DataChannel open, level loaded), EACH calls
this endpoint; both receive the IDENTICAL absolute `start_pts` for
their pair and start the level exactly then. Both peers MUST request
promptly and near-simultaneously once ready (right when the
DataChannel opens) - the second peer has to fetch the schedule before
the start moment passes. The server chooses the lead time: at least
200 ms (start_lead_min_ms setting), scaled up by the pair's reported
latencies (150 + 2 x worst latency when that exceeds the minimum),
capped at 3 s. Once a start has
passed, the next request issues a fresh one (next level, rematch).
`now` is included for a free clock re-check.

### Server-side PTS validation

Client PTS can NEVER be in the future - no tolerance. Endpoints that
accept a `pts` field (signal.php, scores.php) reject any value ahead
of the server clock with 400 `bogus pts: in the future`; the incident
is counted and logged as a bogus-client alert in the admin UI. If an
honest client gets this rejection its clock sync has drifted: re-sync
via time.php immediately (min-RTT sampling keeps the offset error at a
few ms, comfortably below any real network transit time).

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
      "friends": ["deadbeef"]     optional, up to 64 IDs to check (send the
                                  friend list when the multiplayer screen
                                  is open)
    }

Response:

    {
      "ok": true,
      "api": 2,                   contract version, see Versioning
      "now": 1784182417123,       server PTS clock, unix MILLISECONDS
                                  (free coarse re-sync on every heartbeat)
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

## GET /api/poll.php - fast signal poll (matchmaking window only)

    GET /api/poll.php?id=c0ffee42[&wait=8]

    -> 204 No Content                          nothing pending
    -> 200 {"ok":true,"signals":[...]}         pending messages, drained

With `wait` (seconds, capped server-side at 8) this is a LONG POLL: the
server holds the request open and answers the moment a signal arrives,
checking every 150 ms. This is the lowest-latency delivery path -
during an active handshake, loop `wait=8` requests back-to-back and a
relayed signal reaches you in ~150 ms plus network, instead of a full
poll interval. Without `wait` it degrades to the plain cheap poll (one
indexed read, 204).

Same drain semantics as hello's `signals`. Use it ONLY while waiting
for or performing matchmaking/signaling; stop when the DataChannel
opens or the attempt is abandoned. Remember the server is never in the
in-game path at all - once the DataChannel is up, peer packets flow
directly and no server hop exists to optimize.

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
      "name": "KAI",              required, display name (trimmed, max 15
                                  chars = MAX_NAME in the client)
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
    accept    accept an invite                    payload: JSON {"profile": <profile>}
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
              (clients cannot send it: 400)         "request"|"accepted",
                                                    "from": "8-hex"}

The 'friend' signal is the friendship NOTIFICATION: the server delivers
it into the peer's mailbox when a friend request is created for them or
their request gets accepted. It arrives like any other signal (hello or
poll.php, long-poll included), so an online client learns of a request
within its poll cadence; an offline client finds the pending entry via
friend.php list on next start (mailbox signals expire after 30 s).

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
                                            requested me (auto-match)
    POST {"id":..., "action":"accept", "peer":...}
      -> {"ok":true,"state":"accepted"}     404 without a pending request
    POST {"id":..., "action":"remove", "peer":...}
      -> {"ok":true}                        declines a request or removes
                                            an existing friendship
    POST {"id":..., "action":"list"}
      -> {"ok":true,"friends":[{"id":"deadbeef","state":"accepted",
          "outgoing":false,"name":"KAI","online":true,"latency":31}]}
          name/online/latency filled only for accepted entries; a
          pending entry with "outgoing":false is a request awaiting MY
          acceptance.

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
    4. B sets the remote description, answers: B -> signal answer.
    5. Both sides exchange ice messages as candidates arrive.
    6. When the DataChannel opens on both ends, BOTH clients stop
       polling poll.php, and EACH calls POST /api/start.php {id, peer}:
       the server answers both with the identical absolute start_pts,
       and the level begins exactly then (music, READY/GO, first tick).
       From here ALL game traffic flows peer-to-peer (see FOK-snake
       docs/multiplayer-server-prompt.md for the tick sync protocol).
       Clients keep the normal slow hello heartbeat (~30 s) with
       duel_with set, so the server can count running games. For each
       further level or a rematch, both call start.php again.
    7. Either side sends bye (via the DataChannel if open, and via
       signal as fallback) to end the session.

## Relay fallback - when P2P cannot connect

P2P fails for some pairs (symmetric NAT, UDP-blocking firewalls). When
the DataChannel does not open within 5 s of signaling (the default
fallback timeout; both peers must use the same value), BOTH clients
fall back to relaying through the server. Expect ~200-400 ms one-way
latency: relay INPUT events, state hashes and control messages - never
high-rate state. The local snake stays instant; the remote side trails
and the prediction/correction model absorbs it. Show a "relay mode"
indicator so latency self-explains.

    POST /api/relay.php {"id":me, "peer":opponent, "payload":"...",
                         "pts": ms?}
      -> {"ok":true}
      -> 429 "relay backlog full"   receiver stopped fetching; back off
      -> 503 "relay busy"           concurrent relayed-duel cap reached:
                                    tell the user the server is full and
                                    end the match attempt

    GET /api/relay.php?id=me&peer=opponent&wait=8
      -> {"ok":true,"messages":[{"seq":n,"payload":"...","created":s}]}
         oldest first, delivered exactly once
      -> 204 after the hold when nothing arrived (loop wait=8 requests
         back-to-back while in relay mode, like poll.php)

payload is opaque to the server (max 2 KB, defaults admin-configurable);
seq is a server-assigned increasing number for ordering. Keep sending
hello with duel_with during relayed games too. The concurrent-duel cap
exists because every relayed duel holds server workers with its long
polls - a capped, honest "busy" beats degrading the server for everyone.

## In-game liveness (no server involved)

The server is NOT polled during gameplay. The DataChannel itself is the
session:

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

- Undelivered signals expire after 30 s - after that they are gone for
  good. An unanswered invite is stale after 30 s, and signals only
  reliably arrive while the recipient is actively polling (multiplayer
  screen open); an idle client on the 30 s hello cadence can miss them.
- Use a public STUN server (e.g. stun:stun.l.google.com:19302) in the
  RTCPeerConnection config. There is no TURN relay; if P2P fails, report
  "connection failed" to the user.
- Signaling payloads fit the 16 KB limit; send one signal per ICE
  candidate rather than batching.

## Admin

`/admin/` is a human web UI (session login), not part of the client API.
Game clients never call it.
