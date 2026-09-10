# A start carries a sync proof (pts) in server-clock ms. Rather than read
# /api/time.php for every one, learn the client<->server skew ONCE and then
# compute pts locally - the server tolerates minutes of drift, so second
# resolution is ample. (The pts logic itself is exercised in unit.php.)
# Millisecond-resolution local clock; the round-trip latency biases SKEW
# slightly negative, so a computed pts lands just in the PAST - never the
# future the sync gate rejects.
_srv_ms=$(curl -s "$BASE/api/time.php" | grep -oE '"t":[0-9]+' | cut -d: -f2)
SKEW=$(( _srv_ms - $(date +%s%3N) ))
now_ms() { echo $(( $(date +%s%3N) + SKEW )); }
start_req_private() { # id peer epoch reason pts
    curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$1\",\"peer\":\"$2\",\"epoch\":$3,\"reason\":\"$4\",\"pts\":$5,\"duel_private\":true}" \
        "$BASE/api/start.php"
}
start_req() { # id peer epoch reason pts
    curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$1\",\"peer\":\"$2\",\"epoch\":$3,\"reason\":\"$4\",\"pts\":$5}" \
        "$BASE/api/start.php"
}

# Both peers request the start near-simultaneously, like real clients.
# Wait ONLY for the two curls - a bare wait would also wait for the
# backgrounded php server and hang forever.
start_req "$ID1" "$ID2" 0 first "$(now_ms)" > "$DATA/s1.json" &
C1=$!
start_req "$ID2" "$ID1" 0 first "$(now_ms)" > "$DATA/s2.json" &
C2=$!
wait "$C1" "$C2"
S1=$(grep -oE '"start_pts":[0-9]+' "$DATA/s1.json" | cut -d: -f2)
S2=$(grep -oE '"start_pts":[0-9]+' "$DATA/s2.json" | cut -d: -f2)
if [ -n "$S1" ] && [ "$S1" = "$S2" ]; then echo "ok   server-issued start identical for both peers"; else echo "FAIL start pts differ: $S1 vs $S2"; fail=1; fi
if [ "${#S1}" -eq 13 ]; then echo "ok   start pts is milliseconds"; else echo "FAIL start pts not ms: $S1"; fail=1; fi

# The start is what ANNOUNCES the duel. No hello has carried duel_with for
# this pair, so anything seen here came from the two start.php calls above.
hello_friends() { # id (the peer is whoever the delta names)
    curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$1\",\"friends_since\":0}" "$BASE/api/hello.php"
}
R=$(hello_friends "$ID1" "$ID2")
expect "the start announced the duel, with no heartbeat involved" \
    "$(strict "\"$ID2\":{\"online\":true,\"playing\":true")" "$R"
expect "and both peers announced their own side" "$(strict '"playing":2')" "$R"

# Private ON THE START, so the very first record of the duel is private
# rather than public until the first beat.
curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID2\",\"duel_end\":\"$ID1\"}" "$BASE/api/hello.php" > /dev/null
R=$(start_req_private "$ID2" "$ID1" 0 first "$(now_ms)")
expect "a private start still issues the start" '"start_pts":' "$R"
R=$(hello_friends "$ID1" "$ID2")
expect "a duel made private on the start is never attributed" \
    "$(strict "\"$ID2\":{\"online\":true,\"playing\":false")" "$R"
expect "while still being counted" "$(strict '"playing":2')" "$R"

# The flag is a property of the duel, not a latch: the next request that
# holds the duel up and omits it makes the duel public again.
curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID2\",\"duel_with\":\"$ID1\"}" "$BASE/api/hello.php" > /dev/null
R=$(hello_friends "$ID1" "$ID2")
expect "and a beat without the flag makes it public again" \
    "$(strict "\"$ID2\":{\"online\":true,\"playing\":true")" "$R"

R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID2\",\"peer\":\"$ID1\",\"epoch\":0,\"reason\":\"first\",\"pts\":$(now_ms),\"duel_private\":\"yes\"}" \
    "$BASE/api/start.php")
expect "a non-boolean duel_private is refused" '"error":"invalid duel_private"' "$R"

# The epoch is what makes the answer independent of WHEN a peer asks: the
# same epoch must return the same moment however late the second one is.
R=$(start_req "$ID2" "$ID1" 0 first "$(now_ms)")
expect "a late peer re-asking the same epoch gets the same start" "\"start_pts\":$S1" "$R"

# A rematch names epoch 0 exactly as the first start did, so the REASON is
# what says "a new game, not the one you already issued us".
R=$(start_req "$ID1" "$ID2" 0 rematch "$(now_ms)")
expect "a rematch at the same epoch gets a moment of its own" '"start_pts":' "$R"
if [ "$(echo "$R" | grep -oE '"start_pts":[0-9]+' | cut -d: -f2)" != "$S1" ]; then
    echo "ok   which is not the first start's"
else
    echo "FAIL a rematch was handed the first start's moment"; fail=1
fi
R=$(start_req "$ID2" "$ID1" 0 rematch "$(now_ms)")
expect "and the peer joins that one, not the first" '"start_pts":' "$R"

# The in-run halts are settled peer-to-peer and the server no longer knows
# the words for them.
R=$(start_req "$ID1" "$ID2" 0 level "$(now_ms)")
expect "an in-run reason is not a reason any more" 'invalid reason' "$R"

# The sync gate: a start is a moment on the shared clock. pts is required,
# may not be stale, and may not read further ahead than pts_ahead_max_ms -
# every start begins play. Inside that margin an anchor that runs a little
# fast is a warning in the log and nothing the client is told about.
R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"peer\":\"$ID2\",\"epoch\":3,\"reason\":\"first\"}" "$BASE/api/start.php")
expect "a start without a sync proof is refused" 'pts required' "$R"
R=$(start_req "$ID1" "$ID2" 3 first "$(( $(now_ms) + 60000 ))")
expect "a start with a future pts is bogus" 'bogus pts' "$R"
R=$(start_req "$ID1" "$ID2" 3 rematch "$(( $(now_ms) - 120000 ))")
expect "a stale sync proof is refused" 'stale pts' "$R"
R=$(start_req "$ID1" "$ID2" 3 nonsense "$(now_ms)")
expect "an unknown start reason is refused" 'invalid reason' "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"peer\":\"$ID2\",\"epoch\":-1,\"reason\":\"first\",\"pts\":$(now_ms)}" "$BASE/api/start.php")
expect "a negative epoch is refused" 'invalid epoch' "$R"

R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"peer\":\"$ID2\",\"payload\":\"IN:12:up\"}" "$BASE/api/relay.php")
expect "relay accepts message" '"ok":true' "$R"
curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"peer\":\"$ID2\",\"payload\":\"IN:14:left\"}" "$BASE/api/relay.php" > /dev/null
R=$(curl -s "$BASE/api/relay.php?id=$ID2&peer=$ID1&wait=2")
expect "relay delivers in order" '"payload":"IN:12:up"' "$R"
expect "relay delivers second message" '"payload":"IN:14:left"' "$R"
R=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/relay.php?id=$ID2&peer=$ID1")
expect "relay drained to 204" '204' "$R"
BIGPAY=$(printf 'x%.0s' $(seq 1 2049))
R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"peer\":\"$ID2\",\"payload\":\"$BIGPAY\"}" "$BASE/api/relay.php")
expect "oversized relay payload rejected" '"error":"invalid payload"' "$R"

# Directional isolation: a message A->B must never come back to A.
curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"peer\":\"$ID2\",\"payload\":\"IN:20:up\"}" "$BASE/api/relay.php" > /dev/null
R=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/relay.php?id=$ID1&peer=$ID2")
expect "relay does not echo to sender" '204' "$R"
curl -s "$BASE/api/relay.php?id=$ID2&peer=$ID1" > /dev/null

# Long-poll times out to 204 and actually holds the request.
T0=$(date +%s)
R=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/relay.php?id=$ID2&peer=$ID1&wait=2")
T1=$(date +%s)
expect "relay long-poll times out to 204" '204' "$R"
if [ $((T1 - T0)) -ge 1 ]; then echo "ok   relay long-poll held the request"; else echo "FAIL relay long-poll returned too fast"; fail=1; fi

# =====================================================================
# Connection edge cases. An invite or a relayed connection must ALWAYS
# go through or fail loudly - never a silent ok:true that goes nowhere.
# Variations first, then aborts, then a normal connection again: the
# server has to be sane after everything above.
# =====================================================================

# --- Invite variations
R=$(sig "$ID1" "$ID1" invite 'me')
expect "invite to self rejected" '"error":"invalid id' "$R"
R=$(sig "$ID1" 'nothex!!' invite 'x')
expect "invite to a malformed id rejected" '"error":"invalid id' "$R"

R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"to\":\"$ID2\",\"type\":\"invite\"}" "$BASE/api/signal.php")
expect "invite without a payload accepted" '"ok":true' "$R"
curl -s "$BASE/api/poll.php?id=$ID2" > /dev/null

R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"to\":\"$ID2\",\"type\":\"invite\",\"payload\":123}" "$BASE/api/signal.php")
expect "invite with a non-string payload rejected" '"error":"invalid payload"' "$R"

MAXPAY=$(head -c 16384 /dev/zero | tr '\0' 'x')
R=$(sig "$ID1" "$ID2" invite "$MAXPAY")
expect "invite at the 16 KB payload cap accepted" '"ok":true' "$R"
curl -s "$BASE/api/poll.php?id=$ID2" > /dev/null
R=$(sig "$ID1" "$ID2" invite "${MAXPAY}x")
expect "invite one byte over the cap rejected" '"error":"invalid payload"' "$R"

FUTURE=$((($(date +%s) + 60) * 1000))
R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"to\":\"$ID2\",\"type\":\"invite\",\"payload\":\"{}\",\"pts\":$FUTURE}" "$BASE/api/signal.php")
expect "future-dated invite rejected as bogus" '"error":"bogus pts' "$R"

# Two invites before any answer: both arrive, oldest first.
sig "$ID1" "$ID2" invite 'first' > /dev/null
sig "$ID1" "$ID2" invite 'second' > /dev/null
R=$(curl -s "$BASE/api/poll.php?id=$ID2")
ordered "repeated invites both delivered, in order" 'first' 'second' "$R"

# --- Aborts
# The inviter gives up before the peer ever answers.
sig "$ID1" "$ID2" invite 'gone?' > /dev/null
R=$(sig "$ID1" "$ID2" bye '')
expect "inviter can abort with bye" '"ok":true' "$R"
R=$(curl -s "$BASE/api/poll.php?id=$ID2")
ordered "peer sees the invite and the abort" '"type":"invite"' '"type":"bye"' "$R"

# The peer declines: the inviter must learn it, and re-inviting must work.
sig "$ID1" "$ID2" invite 'again' > /dev/null
curl -s "$BASE/api/poll.php?id=$ID2" > /dev/null
sig "$ID2" "$ID1" decline '' > /dev/null
R=$(curl -s "$BASE/api/poll.php?id=$ID1")
expect "decline reaches the inviter" '"type":"decline"' "$R"
R=$(sig "$ID1" "$ID2" invite 'once more')
expect "inviting again after a decline works" '"ok":true' "$R"
curl -s "$BASE/api/poll.php?id=$ID2" > /dev/null

# An accept for an invite that was never sent is free-form signaling: it
# is delivered, and the client correlates it (docs/API.md).
R=$(sig "$ID2" "$ID1" accept 'unsolicited')
expect "unsolicited accept still delivered" '"ok":true' "$R"
curl -s "$BASE/api/poll.php?id=$ID1" > /dev/null

# --- Relay variations
R=$(rly "$ID1" "$ID1" 'x')
expect "relay to self rejected" '"error":"invalid id' "$R"
R=$(rly "$ID1" "$ID2" '')
expect "empty relay payload rejected" '"error":"invalid payload"' "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"peer\":\"$ID2\",\"payload\":42}" "$BASE/api/relay.php")
expect "non-string relay payload rejected" '"error":"invalid payload"' "$R"
MAXR=$(head -c 2048 /dev/zero | tr '\0' 'y')
R=$(rly "$ID1" "$ID2" "$MAXR")
expect "relay at the payload cap accepted" '"ok":true' "$R"
curl -s "$BASE/api/relay.php?id=$ID2&peer=$ID1" > /dev/null

# A slow receiver must lose nothing and see the backlog in order.
rly "$ID1" "$ID2" 'IN:1' > /dev/null
rly "$ID1" "$ID2" 'IN:2' > /dev/null
rly "$ID1" "$ID2" 'IN:3' > /dev/null
R=$(curl -s "$BASE/api/relay.php?id=$ID2&peer=$ID1")
ordered "slow receiver gets the whole backlog, oldest first" 'IN:1' 'IN:3' "$R"
expect "backlog keeps the middle message" 'IN:2' "$R"
expect "relayed messages carry an age (ms on the server)" '"age":' "$R"

# Piggyback (v3.2): a POST with "pull" returns the poster's OWN pending
# inbound, so delivery does not hang on the held GET alone. ID1 -> ID2, then
# ID2 posts (to ID1) and pulls: it must get IN:pull back, drained exactly once.
rly "$ID1" "$ID2" 'IN:pull' > /dev/null
R=$(rlypull "$ID2" "$ID1" 'ack')
expect "a POST with pull piggybacks the poster's inbound" 'IN:pull' "$R"
expect "a piggybacked message carries an age" '"age":' "$R"
R=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/relay.php?id=$ID2&peer=$ID1")
expect "a pulled message is not then delivered again by the GET" '204' "$R"
# A POST without pull must NOT drain the poster's inbound (old-client safety).
rly "$ID1" "$ID2" 'IN:keep' > /dev/null
R=$(rly "$ID2" "$ID1" 'ack2')
expect "a POST without pull returns no messages" '"ok":true}' "$R"
R=$(curl -s "$BASE/api/relay.php?id=$ID2&peer=$ID1")
expect "and the inbound is still there for the GET" 'IN:keep' "$R"
# Clean up ID1's inbound (the acks ID2 sent) so later tests start clean.
curl -s "$BASE/api/relay.php?id=$ID1&peer=$ID2" > /dev/null

# Aborting a relayed duel: its undelivered backlog dies with it (a stale input
# must never reach the next duel), AND the peer's held GET is told the pair is
# gone (v3.3) instead of being left to time out - the relay's answer to a P2P
# DataChannel close. 'accept' first: the bye needs a tracked connection to mark.
sig "$ID1" "$ID2" accept '' > /dev/null
R=$(curl -s -w '\n%{http_code}' "$BASE/api/relay.php?id=$ID2&peer=$ID1")
expect "a live pairing is not reported gone" '204' "$R"
rly "$ID1" "$ID2" 'IN:stale' > /dev/null
sig "$ID1" "$ID2" bye '' > /dev/null
R=$(curl -s "$BASE/api/relay.php?id=$ID2&peer=$ID1")
expect "after a bye the relay GET reports the peer gone" '"gone":true' "$R"
# The stale input did not leak: a gone reply carries no messages, and the
# backlog was dropped with the pair (forgetPair).
curl -s "$BASE/api/poll.php?id=$ID2" > /dev/null

# --- ... and now a normal connection again, start to finish.
R=$(sig "$ID1" "$ID2" invite 'lets play')
expect "normal invite after all the aborts" '"ok":true' "$R"
R=$(curl -s "$BASE/api/poll.php?id=$ID2")
expect "normal invite delivered" '"type":"invite"' "$R"
R=$(sig "$ID2" "$ID1" accept 'sure')
expect "normal accept sent" '"ok":true' "$R"
R=$(curl -s "$BASE/api/poll.php?id=$ID1")
expect "normal accept delivered" '"type":"accept"' "$R"
sig "$ID1" "$ID2" offer 'sdp-offer' > /dev/null
R=$(curl -s "$BASE/api/poll.php?id=$ID2")
expect "normal offer delivered" '"type":"offer"' "$R"
sig "$ID2" "$ID1" answer 'sdp-answer' > /dev/null
R=$(curl -s "$BASE/api/poll.php?id=$ID1")
expect "normal answer delivered" '"type":"answer"' "$R"
sig "$ID1" "$ID2" ice 'cand-1' > /dev/null
R=$(curl -s "$BASE/api/poll.php?id=$ID2")
expect "normal ice delivered" '"type":"ice"' "$R"
R=$(start_req "$ID1" "$ID2" 0 first "$(now_ms)")
expect "normal start issued" '"start_pts":' "$R"
R=$(rly "$ID1" "$ID2" 'IN:42:up')
expect "normal relay accepted" '"ok":true' "$R"
R=$(curl -s "$BASE/api/relay.php?id=$ID2&peer=$ID1")
expect "normal relay delivered" 'IN:42:up' "$R"
R=$(sig "$ID1" "$ID2" bye '')
expect "normal duel ends with bye" '"ok":true' "$R"
curl -s "$BASE/api/poll.php?id=$ID2" > /dev/null

# --- A rematch after a PEER-TO-PEER bye. Once the DataChannel is open the
# bye travels over it and never reaches the server (docs/API.md, the 1vs1
# flow), so nothing here says the duel ended. The pair's finished epoch
# line must not survive to refuse their next match: without the handshake
# reset in signal.php this 409s for a full five minutes.
start_req "$ID1" "$ID2" 0 first "$(now_ms)" > /dev/null
sig "$ID1" "$ID2" invite 'rematch please' > /dev/null
curl -s "$BASE/api/poll.php?id=$ID2" > /dev/null
R=$(start_req "$ID1" "$ID2" 0 first "$(now_ms)")
expect "a rematch after a peer-to-peer bye still gets a start" '"start_pts":' "$R"
# Quick match has no invite at all: the offer is what opens that pairing.
sig "$ID1" "$ID2" offer 'sdp-rematch' > /dev/null
curl -s "$BASE/api/poll.php?id=$ID2" > /dev/null
R=$(start_req "$ID1" "$ID2" 0 first "$(now_ms)")
expect "an offer opens a fresh epoch line too (quick match)" '"start_pts":' "$R"
# A RELAY rematch reuses the hub with NO new offer, so nothing clears the
# line for it. The REASON is what separates it from the first start the pair
# already has, and both peers land on the one moment.
PREV=$(echo "$R" | grep -oE '"start_pts":[0-9]+' | cut -d: -f2)
R=$(start_req "$ID1" "$ID2" 0 rematch "$(now_ms)")
expect "a relay rematch gets a start with no handshake at all" '"start_pts":' "$R"
if [ "$(echo "$R" | grep -oE '"start_pts":[0-9]+' | cut -d: -f2)" != "$PREV" ]; then
    echo "ok   and it is a new moment, not the one before it"
else
    echo "FAIL the relay rematch was handed the previous start"; fail=1
fi
R=$(start_req "$ID2" "$ID1" 0 rematch "$(now_ms)")
expect "and the peer joins the reset line" '"start_pts":' "$R"
sig "$ID1" "$ID2" bye '' > /dev/null
curl -s "$BASE/api/poll.php?id=$ID2" > /dev/null

R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\",\"action\":\"remove\",\"peer\":\"$ID2\"}" "$BASE/api/friend.php")
expect "friendship removed" '"ok":true' "$R"

curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID2\",\"auto_accept\":true}" "$BASE/api/hello.php" > /dev/null
R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\",\"action\":\"request\",\"peer\":\"$ID2\"}" "$BASE/api/friend.php")
expect "request auto-accepted on QR screen" '"state":"accepted"' "$R"
curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\",\"action\":\"remove\",\"peer\":\"$ID2\"}" "$BASE/api/friend.php" > /dev/null
curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID2\",\"auto_accept\":false}" "$BASE/api/hello.php" > /dev/null
R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\",\"action\":\"request\",\"peer\":\"$ID2\"}" "$BASE/api/friend.php")
expect "request pending after QR screen closed" '"state":"pending"' "$R"
curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\",\"action\":\"remove\",\"peer\":\"$ID2\"}" "$BASE/api/friend.php" > /dev/null

# 4.9: the poll arms it too, so the QR screen needs no hello beside the poll
# it is already holding.
curl -s "$BASE/api/poll.php?id=$ID2&aa=1" > /dev/null
R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\",\"action\":\"request\",\"peer\":\"$ID2\"}" "$BASE/api/friend.php")
expect "the poll arms auto-accept as a hello does" '"state":"accepted"' "$R"
curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\",\"action\":\"remove\",\"peer\":\"$ID2\"}" "$BASE/api/friend.php" > /dev/null
curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID2\",\"auto_accept\":false}" "$BASE/api/hello.php" > /dev/null
R=$(curl -s "$BASE/api/poll.php?id=$ID2&aa=nonsense")
expect "a bogus arm flag is refused" '"error":"invalid aa"' "$R"
curl -s "$BASE/api/poll.php?id=$ID2" > /dev/null
curl -s "$BASE/api/poll.php?id=$ID1" > /dev/null

# --- API 4.4 on the start answer: q_ms is this request's own queue wait.
R=$(start_req "$ID1" "$ID2" 900 first "$(now_ms)")
expect "a start carries the queue figure" '"q_ms":' "$R"
