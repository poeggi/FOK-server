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

FUTURE_MS=$((NOW_MS + 60000))
R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"to\":\"$ID2\",\"type\":\"ice\",\"payload\":\"cheat\",\"pts\":$FUTURE_MS}" "$BASE/api/signal.php")
expect "future pts rejected as bogus" 'bogus pts' "$R"

NEAR_MS=$((NOW_MS + 2000))
R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"to\":\"$ID2\",\"type\":\"ice\",\"payload\":\"early\",\"pts\":$NEAR_MS}" "$BASE/api/signal.php")
expect "near-future pts also rejected (zero tolerance)" 'bogus pts' "$R"

R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"name\":\"CHEAT\",\"score\":9,\"level\":1,\"diff\":1,\"pts\":$FUTURE_MS}" "$BASE/api/scores.php")
expect "future pts rejected on scores" 'bogus pts' "$R"

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

