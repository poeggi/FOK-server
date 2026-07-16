# FOK-server API

Definition of the HTTP API that game clients (currently FOK-snake) use.
This is the contract: anything not documented here is not part of the API
and may change without notice.

- Base URL: `https://fok-server.poggensee.it`
- Server source of truth: this repo, `public/api/`
- API version: matches FOK_VERSION in `public/src/Config.php`

## Conventions

- All endpoints speak JSON. POST bodies are JSON documents
  (`Content-Type: application/json`), responses are JSON objects.
- Every response contains `"ok": true` or `"ok": false`. On failure the
  object is `{"ok": false, "error": "<short reason>"}` with an HTTP status
  of 400 (bad input), 401 (auth), 404 (unknown), 405 (wrong method) or
  500 (server fault). Clients must treat any non-`ok` answer as a soft
  failure: log it, back off, never crash gameplay.
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
- Timestamps are unix seconds (UTC).

## POST /api/hello.php - heartbeat and poll

The single periodic request a client makes. It (a) registers/refreshes
presence, (b) refreshes an ongoing 1:1 duel, and (c) delivers any pending
matchmaking/signaling messages addressed to the caller.

Request:

    {
      "id": "c0ffee42",           required, player ID
      "duel_with": "deadbeef"     optional, peer ID while a 1:1 game runs
    }

Response:

    {
      "ok": true,
      "now": 1784182417,          server time, unix seconds
      "online": 3,                players seen in the last 60 s
      "playing": 2,               players currently in 1:1 games
      "registered": 17,           total known player IDs
      "signals": [                pending messages for "id", oldest first
        {"from": "deadbeef", "type": "invite", "payload": "", "created": 1784182410}
      ]
    }

Rules:

- Signals are DRAINED on delivery: each message is returned exactly once.
  The client must process every element of `signals` immediately.
- Cadence: send hello every ~30 s, always. It is the heartbeat, not a
  fast poll; use /api/poll.php for the fast signaling window.
- While a 1:1 game is running, keep sending `duel_with` at least every
  60 s (the duel counts as over when neither peer refreshed it within
  60 s).

## GET /api/poll.php - fast signal poll (matchmaking window only)

    GET /api/poll.php?id=c0ffee42

    -> 204 No Content                          nothing pending (common case)
    -> 200 {"ok":true,"signals":[...]}         pending messages, drained

The cheap high-frequency poll: a 204 answer has an empty body and costs
the server a single indexed read with no writes, so 1-2 Hz polling is
fine. Same drain semantics as hello's `signals`. Use it ONLY while
waiting for or performing matchmaking/signaling; stop when the
DataChannel opens or the attempt is abandoned.

## GET /api/scores.php - global top 100

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
      "name": "KAI",              required, display name (trimmed, max 16 chars)
      "score": 4200,              required, int 0..1000000000
      "level": 7,                 required, int 1..99
      "diff": 2,                  optional, int 0..3, default 1
      "color": 3,                 optional, int 0..255, default 0
      "shopItems": {"hat": 1},    optional, object, max 2 KB as JSON
      "seed": 305419896,          optional, the 32-bit game seed
      "inputs": [[12,1],[40,2]]   optional, tick-stamped input log, max 256 KB as JSON
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
recipient's next hello poll. The server never interprets `payload`.

Request:

    {
      "id": "c0ffee42",           required, sender
      "to": "deadbeef",           required, recipient (must differ from id)
      "type": "invite",           required, see below
      "payload": "..."            optional string, max 16 KB
    }

Response: `{"ok": true}`

Types (fixed set, anything else is rejected):

    invite    ask "to" for a 1:1 game            payload: free text or ""
    accept    accept an invite                    payload: ""
    decline   decline an invite                   payload: ""
    offer     WebRTC SDP offer                    payload: JSON-encoded RTCSessionDescription
    answer    WebRTC SDP answer                   payload: JSON-encoded RTCSessionDescription
    ice       ICE candidate                       payload: JSON-encoded RTCIceCandidate
    bye       leave / abort the session           payload: ""

## 1:1 game flow (the intended sequence)

Player A wants to play with player B (A knows B's ID, e.g. from the
friend list):

    1. A -> signal {type: "invite", to: B}; A starts polling poll.php (~1 s).
    2. B sees the invite in its hello poll. UI asks the user.
       B -> signal accept (or decline, which ends the flow).
    3. A (on accept) creates an RTCPeerConnection with a DataChannel
       (unreliable, unordered: maxRetransmits 0, ordered false),
       A -> signal offer with the local description.
    4. B sets the remote description, answers: B -> signal answer.
    5. Both sides exchange ice messages as candidates arrive.
    6. When the DataChannel opens on both ends, BOTH clients stop
       polling poll.php. Gameplay starts and ALL game traffic flows
       peer-to-peer (see FOK-snake docs/multiplayer-server-prompt.md for
       the tick sync protocol). Clients keep the normal slow hello
       heartbeat (~30 s) with duel_with set, so the server can count
       running games.
    7. Either side sends bye (via the DataChannel if open, and via
       signal as fallback) to end the session.

## In-game liveness (no server involved)

The server is NOT polled during gameplay. The DataChannel itself is the
session:

- Game state updates arrive at the net tick rate (4-15 Hz); every
  received packet proves the peer is alive.
- When no game packet is due, send a tiny in-band ping every 1 s and
  expect the peer's ping/traffic at the same rate. No packets for ~3 s
  means the session is dead: show "connection lost" and end the game.
- Also watch RTCPeerConnection.connectionState; "failed" or "closed"
  ends the session immediately.

This gives the required once-per-second alive check at zero server load
and much lower latency than any HTTP poll could.

Notes:

- Undelivered signals expire after 120 s; an unanswered invite can be
  considered stale after that.
- Use a public STUN server (e.g. stun:stun.l.google.com:19302) in the
  RTCPeerConnection config. There is no TURN relay; if P2P fails, report
  "connection failed" to the user.
- Signaling payloads fit the 16 KB limit; send one signal per ICE
  candidate rather than batching.

## Admin

`/admin/` is a human web UI (session login), not part of the client API.
Game clients never call it.
