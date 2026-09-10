# Debug reports: submit a bundle, get a 4-digit PIN (retrieved by the admin
# section below via $DPIN).
R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d '{"logs":["boom"],"images":[]}' "$BASE/debug/submit.php")
expect "debug submit accepted" '"ok":true' "$R"
DPIN=$(echo "$R" | grep -oE '"pin":"[0-9]{4}"' | cut -d'"' -f4)
if [ "${#DPIN}" -eq 4 ]; then echo "ok   debug submit returns a 4-digit pin"; else echo "FAIL debug pin not 4 digits: $DPIN"; fail=1; fi
R=$(curl -s -X POST -H 'Content-Type: application/json' -d 'not json' "$BASE/debug/submit.php")
expect "debug rejects a non-JSON bundle" '"error":"dataset must be a non-empty JSON object"' "$R"
R=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/debug/submit.php")
expect "debug submit via GET rejected" '405' "$R"

R=$(curl -s "$BASE/api/time.php")
expect "time sync endpoint" '"t":' "$R"
NOW_MS=$(echo "$R" | grep -oE '"t":[0-9]+' | cut -d: -f2)
if [ "${#NOW_MS}" -eq 13 ]; then echo "ok   time is in milliseconds"; else echo "FAIL time not ms: $NOW_MS"; fail=1; fi

R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"to\":\"$ID2\",\"type\":\"ice\",\"payload\":\"synced\",\"pts\":$NOW_MS}" "$BASE/api/signal.php")
expect "signal with valid pts" '"ok":true' "$R"
curl -s "$BASE/api/poll.php?id=$ID2" > /dev/null

# A pts that reads ahead: silent inside half the margin, a warning past
# that but still answered, refused past the whole of it. The last one is
# what puts the 'bogus' row in the alerts list the admin part asserts.
SRV_MS=$(curl -s "$BASE/api/time.php" | grep -oE '"t":[0-9]+' | cut -d: -f2)
NEAR_MS=$((SRV_MS + 50))
R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"to\":\"$ID2\",\"type\":\"ice\",\"payload\":\"synced\",\"pts\":$NEAR_MS}" "$BASE/api/signal.php")
expect "a pts inside half the margin is accepted" '"ok":true' "$R"

# The warning rung is the band between half the margin and the whole of it
# - 100 ms wide at the default, which a round trip can spend on its own. So
# widen it for this one request and put the default back after.
if [ "$ADMIN" -eq 1 ]; then
    setting pts_ahead_max_ms 4000
    WARN_MS=$(( $(curl -s "$BASE/api/time.php" | grep -oE '"t":[0-9]+' | cut -d: -f2) + 3000 ))
    R=$(curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$ID1\",\"to\":\"$ID2\",\"type\":\"ice\",\"payload\":\"early\",\"pts\":$WARN_MS}" "$BASE/api/signal.php")
    expect "a pts past half the margin is accepted too" '"ok":true' "$R"
    setting pts_ahead_max_ms 200
fi

FUTURE_MS=$((SRV_MS + 60000))
R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"to\":\"$ID2\",\"type\":\"ice\",\"payload\":\"cheat\",\"pts\":$FUTURE_MS}" "$BASE/api/signal.php")
expect "a pts past the whole margin is refused" 'bogus pts' "$R"
curl -s "$BASE/api/poll.php?id=$ID2" > /dev/null

R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"name\":\"CHEAT\",\"score\":9,\"level\":1,\"diff\":1,\"pts\":$FUTURE_MS}" "$BASE/api/scores.php")
expect "the same margin refuses a score" 'bogus pts' "$R"

curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\",\"name\":\"SMOKE ONE\"}" "$BASE/api/hello.php" > /dev/null
curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID2\",\"name\":\"SMOKE TWO\"}" "$BASE/api/hello.php" > /dev/null

R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\",\"friends_since\":0}" "$BASE/api/hello.php")
if [[ "$R" != *"\"$ID2\""* ]]; then echo "ok   an id with no friendship is absent from the delta entirely"; else echo "FAIL a non-friend appeared in the delta: $R"; fail=1; fi

R=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"to\":\"$ID2\",\"type\":\"invite\",\"payload\":\"play?\"}" "$BASE/api/signal.php")
expect "invite blocked without friendship" '403' "$R"

R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\",\"action\":\"request\",\"peer\":\"$ID2\"}" "$BASE/api/friend.php")
expect "friend request recorded" '"state":"pending"' "$R"
R=$(curl -s "$BASE/api/poll.php?id=$ID2")
expect "peer notified of friend request" '"type":"friend"' "$R"
expect "notification names the requester" "\"from\":\"$ID1\"" "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID2\",\"action\":\"list\"}" "$BASE/api/friend.php")
expect "peer sees incoming request" '"outgoing":false' "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID2\",\"action\":\"accept\",\"peer\":\"$ID1\"}" "$BASE/api/friend.php")
expect "friend request accepted" '"state":"accepted"' "$R"
R=$(curl -s "$BASE/api/poll.php?id=$ID1")
expect "requester notified of acceptance" 'accepted' "$R"
expect "acceptance is a friend signal" '"type":"friend"' "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"to\":\"$ID2\",\"type\":\"friend\",\"payload\":\"spoof\"}" "$BASE/api/signal.php")
expect "clients cannot send friend signals" '"error":"invalid type"' "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\",\"action\":\"list\"}" "$BASE/api/friend.php")
expect "friend list carries name" '"name":"SMOKE TWO"' "$R"

# Full invite round-trip between the two (now-friend) players.
R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"to\":\"$ID2\",\"type\":\"invite\",\"payload\":\"{\\\"profile\\\":{\\\"name\\\":\\\"SMOKE ONE\\\"}}\"}" "$BASE/api/signal.php")
expect "invite sent between friends" '"ok":true' "$R"

R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID2\"}" "$BASE/api/hello.php")
expect "invite delivered to peer" '"type":"invite"' "$R"
expect "invite carries the profile payload" 'SMOKE ONE' "$R"
expect "invite names the sender" "\"from\":\"$ID1\"" "$R"

R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID2\"}" "$BASE/api/hello.php")
expect "invite drained after delivery" '"signals":[]' "$R"

R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID2\",\"to\":\"$ID1\",\"type\":\"accept\",\"payload\":\"{\\\"profile\\\":{\\\"name\\\":\\\"SMOKE TWO\\\"}}\"}" "$BASE/api/signal.php")
expect "accept reply sent" '"ok":true' "$R"
R=$(curl -s "$BASE/api/poll.php?id=$ID1")
expect "accept reply reaches the inviter" '"type":"accept"' "$R"
expect "accept carries the peer profile" 'SMOKE TWO' "$R"
# The accept just confirmed a P2P pairing, so both sides also get a
# peer-net hint (delivered alongside the accept for the inviter).
expect "accept hands the inviter a peer-net hint" '"type":"peer-net"' "$R"
expect "the peer-net carries an address family" 'family' "$R"
expect "the peer-net carries the recipient own address" 'self_ip' "$R"
R=$(curl -s "$BASE/api/poll.php?id=$ID2")
expect "the accepter also gets a peer-net hint" '"type":"peer-net"' "$R"

# Decline is delivered too.
curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"to\":\"$ID2\",\"type\":\"invite\",\"payload\":\"{}\"}" "$BASE/api/signal.php" > /dev/null
curl -s "$BASE/api/poll.php?id=$ID2" > /dev/null
R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID2\",\"to\":\"$ID1\",\"type\":\"decline\",\"payload\":\"\"}" "$BASE/api/signal.php")
expect "decline sent" '"ok":true' "$R"
R=$(curl -s "$BASE/api/poll.php?id=$ID1")
expect "decline reaches the inviter" '"type":"decline"' "$R"

R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"to\":\"$ID2\",\"type\":\"hack\",\"payload\":\"\"}" "$BASE/api/signal.php")
expect "signal rejects bad type" '"error":"invalid type"' "$R"

R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID2\",\"to\":\"$ID1\",\"type\":\"accept-relay\",\"payload\":\"{}\"}" "$BASE/api/signal.php")
expect "relay-first accept allowed" '"ok":true' "$R"
R=$(curl -s "$BASE/api/poll.php?id=$ID1")
expect "relay-first accept delivered" '"type":"accept-relay"' "$R"

R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"to\":\"$ID2\",\"type\":\"invite-relay\",\"payload\":\"{}\"}" "$BASE/api/signal.php")
expect "no-p2p invite allowed" '"ok":true' "$R"
R=$(curl -s "$BASE/api/poll.php?id=$ID2")
expect "no-p2p invite delivered" '"type":"invite-relay"' "$R"

R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"to\":\"$ID2\",\"type\":\"chat\",\"payload\":\"gone\"}" "$BASE/api/signal.php")
expect "a retired signal type is refused" '"error":"invalid type"' "$R"

# An SDP offer is the reason the payload cap is 16 KB at all.
LONG=$(printf 'x%.0s' $(seq 1 4096))
R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"to\":\"$ID2\",\"type\":\"offer\",\"payload\":\"$LONG\"}" "$BASE/api/signal.php")
expect "offer allows large payload" '"ok":true' "$R"
curl -s "$BASE/api/poll.php?id=$ID2" > /dev/null

# 'ices' (4.4): a side's whole ICE trickle in ONE request. The cost on this
# host is per request, not per byte, so this is the type that pays. The
# server stays opaque to what a candidate looks like and checks only that the
# payload IS a bounded list - the count is the entire point of the type.
CANDS='[{\"candidate\":\"cand-a\"},{\"candidate\":\"cand-b\"}]'
R=$(sig "$ID1" "$ID2" ices "$CANDS")
expect "batched ice candidates accepted" '"ok":true' "$R"
R=$(curl -s "$BASE/api/poll.php?id=$ID2")
expect "batched ice candidates delivered" '"type":"ices"' "$R"
expect "the whole batch arrives, not just the last of it" 'cand-b' "$R"
R=$(sig "$ID1" "$ID2" ices 'cand-a')
expect "ices rejects a payload that is not an array" '"error":"invalid payload"' "$R"
R=$(sig "$ID1" "$ID2" ices '[]')
expect "ices rejects an empty batch" '"error":"invalid payload"' "$R"
R=$(sig "$ID1" "$ID2" ices "[$(printf '1,%.0s' $(seq 1 23))1]")
expect "a full batch at the cap is accepted" '"ok":true' "$R"
curl -s "$BASE/api/poll.php?id=$ID2" > /dev/null
R=$(sig "$ID1" "$ID2" ices "[$(printf '1,%.0s' $(seq 1 24))1]")
expect "one candidate over the cap is refused" '"error":"invalid payload"' "$R"
curl -s "$BASE/api/poll.php?id=$ID2" > /dev/null

R=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/poll.php?id=$ID2")
expect "poll empty is 204" '204' "$R"

curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"to\":\"$ID2\",\"type\":\"ice\",\"payload\":\"cand\"}" "$BASE/api/signal.php" > /dev/null
R=$(curl -s "$BASE/api/poll.php?id=$ID2")
expect "poll delivers signal" '"type":"ice"' "$R"

R=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/poll.php?id=$ID2")
expect "poll drained back to 204" '204' "$R"

curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"to\":\"$ID2\",\"type\":\"ice\",\"payload\":\"c2\"}" "$BASE/api/signal.php" > /dev/null
T0=$(date +%s)
R=$(curl -s "$BASE/api/poll.php?id=$ID2&wait=5")
T1=$(date +%s)
expect "long poll returns pending signal" '"type":"ice"' "$R"
if [ $((T1 - T0)) -le 1 ]; then echo "ok   long poll answers immediately"; else echo "FAIL long poll took $((T1 - T0))s with pending signal"; fail=1; fi

T0=$(date +%s)
R=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/poll.php?id=$ID2&wait=2")
T1=$(date +%s)
expect "long poll times out to 204" '204' "$R"
if [ $((T1 - T0)) -ge 1 ]; then echo "ok   long poll held the request"; else echo "FAIL long poll returned too fast ($((T1 - T0))s)"; fail=1; fi

# A duel is stated per player: each peer announces itself, so one peer having
# said so counts one. The figure is cached, and every edge drops that cache -
# so these assertions read back-to-back without waiting a window out.
R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\",\"duel_with\":\"$ID2\"}" "$BASE/api/hello.php")
expect "the peer that announced is counted" "$(strict '"playing":1')" "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID2\",\"duel_with\":\"$ID1\"}" "$BASE/api/hello.php")
expect "duel counted" "$(strict '"playing":2')" "$R"

# Each of them may be watched, and the answer is the same either way it is
# asked - the delta and the older map cannot disagree about a private duel.
R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\",\"friends_since\":0}" "$BASE/api/hello.php")
expect "a friend in a duel may be watched" "$(strict "\"$ID2\":{\"online\":true,\"playing\":true")" "$R"

# Private: counted, never attributed.
R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID2\",\"duel_with\":\"$ID1\",\"duel_private\":true}" "$BASE/api/hello.php")
expect "a private duel still counts" "$(strict '"playing":2')" "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\",\"friends_since\":0}" "$BASE/api/hello.php")
expect "but is never attributed to the player" "$(strict "\"$ID2\":{\"online\":true,\"playing\":false")" "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID2\",\"duel_with\":\"$ID1\"}" "$BASE/api/hello.php")

# The announced end, and the guard that keeps a late one from cancelling the
# pairing that replaced it.
R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID2\",\"duel_end\":\"aaaa0000\"}" "$BASE/api/hello.php")
expect "an end naming another peer is ignored" "$(strict '"playing":2')" "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID2\",\"duel_end\":\"$ID1\"}" "$BASE/api/hello.php")
expect "an announced end drops the player at once" "$(strict '"playing":1')" "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\",\"friends_since\":0}" "$BASE/api/hello.php")
expect "and takes the spectate offer with it" "$(strict "\"$ID2\":{\"online\":true,\"playing\":false")" "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\",\"duel_end\":\"$ID2\"}" "$BASE/api/hello.php")
expect "both ends leave nobody playing" "$(strict '"playing":0')" "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\",\"duel_end\":\"nothex\"}" "$BASE/api/hello.php")
expect "a malformed duel_end is refused" '"error":"invalid duel_end"' "$R"

# 4.9: the same end, stated on the poll the client is already holding.
curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\",\"duel_with\":\"$ID2\"}" "$BASE/api/hello.php" > /dev/null
R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID2\",\"duel_with\":\"$ID1\"}" "$BASE/api/hello.php")
expect "a duel announced again, for the poll to end" "$(strict '"playing":2')" "$R"
curl -s "$BASE/api/poll.php?id=$ID1&de=$ID2" > /dev/null
curl -s "$BASE/api/poll.php?id=$ID2&de=$ID1" > /dev/null
R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\"}" "$BASE/api/hello.php")
expect "the poll ends a duel as a hello does" "$(strict '"playing":0')" "$R"

curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID2\",\"latency\":31}" "$BASE/api/hello.php" > /dev/null
R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\",\"friends_since\":0}" "$BASE/api/hello.php")
expect "the friend reads online" "\"$ID2\":{\"online\":true" "$R"
expect "with the latency it reported" '"latency":31' "$R"
expect "and the name it goes by" '"name":"SMOKE TWO"' "$R"

# The whole roster on the heartbeat a screen showing it was sending anyway,
# byte for byte what friend.php `list` returns (4.4 re-release).
R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\",\"friends_list\":true}" "$BASE/api/hello.php")
expect "hello serves the whole roster on request" '"friends":[' "$R"
expect "the roster carries the peer state" '"state":"accepted"' "$R"
expect "the roster carries the peer name" '"name":"SMOKE TWO"' "$R"

R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\",\"latency\":99999}" "$BASE/api/hello.php")
expect "absurd latency rejected" '"error":"invalid latency"' "$R"

# The same status, asked as a delta (4.6): no ids on the wire, and the
# cursor comes back with the answer.
R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\",\"friends_since\":0}" "$BASE/api/hello.php")
expect "hello serves a friend delta" '"friends_delta":{' "$R"
expect "the delta carries the accepted friend" "\"$ID2\":{" "$R"
expect "the friend reads online in it" '"online":true' "$R"
expect "the delta says whether more is pending" '"friends_more":false' "$R"
FAT=$(echo "$R" | grep -oE '"friends_at":[0-9]+' | cut -d: -f2)
if [ "${#FAT}" -eq 13 ]; then echo "ok   the cursor is a millisecond stamp"; else echo "FAIL friends_at not ms: $FAT"; fail=1; fi
R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"friends_since\":$FAT}" "$BASE/api/hello.php")
expect "a second read finds nothing changed" '"friends_delta":{}' "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"friends\":[\"$ID2\",\"aaaa0000\"]}" "$BASE/api/hello.php")
if [[ "$R" != *'"friends_'* ]]; then echo "ok   naming ids is answered with nothing at all"; else echo "FAIL a friends list was still answered: $R"; fail=1; fi
R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\",\"friends_since\":-1}" "$BASE/api/hello.php")
expect "a negative cursor is refused" '"error":"invalid friends_since"' "$R"

# ...and on the poll, where the screens already are.
R=$(curl -s "$BASE/api/poll.php?id=$ID1&fs=0")
expect "the poll serves the delta too" '"friends_delta":{' "$R"
expect "with the presence counters" "$(strict '"online":2')" "$R"
expect "and the hold decision" '"pace":{' "$R"
PAT=$(echo "$R" | grep -oE '"friends_at":[0-9]+' | cut -d: -f2)
R=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/poll.php?id=$ID1&fs=$PAT")
expect "a poll with nothing pending still answers 204" '204' "$R"
R=$(curl -s "$BASE/api/poll.php?id=$ID1&fs=nonsense")
expect "a bogus cursor is refused" '"error":"invalid fs"' "$R"

# 4.9: the last three answers a screen holding this poll needed a hello for.
# fl and tl answer AT ONCE: the body proves the answer, the clock proves the
# poll did not wait out the hold it asked for.
T0=$(date +%s)
R=$(curl -s "$BASE/api/poll.php?id=$ID1&fl=1&wait=5")
expect "the poll serves the whole roster" '"friends":[' "$R"
expect "the counters ride the roster" '"online":' "$R"
expect "so does the hold decision" '"pace":{' "$R"
expect "and it answers instead of holding for the wait" 'fast' "$([ $(( $(date +%s) - T0 )) -lt 3 ] && echo fast || echo slow)"
T0=$(date +%s)
R=$(curl -s "$BASE/api/poll.php?id=$ID1&tl=1&wait=5")
expect "the poll serves the tournament announce" '"tourneys":' "$R"
expect "and answers at once for that too" 'fast' "$([ $(( $(date +%s) - T0 )) -lt 3 ] && echo fast || echo slow)"
R=$(curl -s "$BASE/api/poll.php?id=$ID1&fl=nonsense")
expect "a bogus flag is refused" '"error":"invalid fl"' "$R"
R=$(curl -s "$BASE/api/poll.php?id=$ID1&fl=1")
expect "every body carries the contract version" '"api":"' "$R"
expect "and the server's debug instruction" '"debug":' "$R"
R=$(curl -s "$BASE/api/poll.php?id=$ID1&de=nonsense")
expect "a bogus duel end is refused" '"error":"invalid de"' "$R"
R=$(curl -s "$BASE/api/poll.php?id=$ID1&db=nonsense")
expect "a bogus debug report is refused" '"error":"invalid db"' "$R"
R=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/poll.php?id=$ID1&wait=1")
expect "a poll asking for none of them still answers 204" '204' "$R"
# The contract promises any hold up to 9 s (the default ask is 5): a longer
# ask is served as the cap, measured in whole seconds with room on each side.
T0=$(date +%s)
R=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/poll.php?id=$ID1&wait=20")
expect "a hold past the cap still answers 204" '204' "$R"
expect "and is held for the cap, not the ask" 'capped' "$(D=$(( $(date +%s) - T0 )); [ $D -ge 8 ] && [ $D -le 12 ] && echo capped || echo "held ${D}s")"

