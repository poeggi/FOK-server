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
    -d "{\"id\":\"$ID1\",\"to\":\"$ID2\",\"type\":\"chat\",\"payload\":\"synced\",\"pts\":$NOW_MS}" "$BASE/api/signal.php")
expect "signal with valid pts" '"ok":true' "$R"
curl -s "$BASE/api/poll.php?id=$ID2" > /dev/null

FUTURE_MS=$((NOW_MS + 60000))
R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"to\":\"$ID2\",\"type\":\"chat\",\"payload\":\"cheat\",\"pts\":$FUTURE_MS}" "$BASE/api/signal.php")
expect "future pts rejected as bogus" 'bogus pts' "$R"

NEAR_MS=$((NOW_MS + 2000))
R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"to\":\"$ID2\",\"type\":\"chat\",\"payload\":\"early\",\"pts\":$NEAR_MS}" "$BASE/api/signal.php")
expect "near-future pts also rejected (zero tolerance)" 'bogus pts' "$R"

R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"name\":\"CHEAT\",\"score\":9,\"level\":1,\"diff\":1,\"pts\":$FUTURE_MS}" "$BASE/api/scores.php")
expect "future pts rejected on scores" 'bogus pts' "$R"

curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\",\"name\":\"SMOKE ONE\"}" "$BASE/api/hello.php" > /dev/null
curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID2\",\"name\":\"SMOKE TWO\"}" "$BASE/api/hello.php" > /dev/null

R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\",\"friends\":[\"$ID2\"]}" "$BASE/api/hello.php")
expect "friend status gated before friendship" "\"$ID2\":false" "$R"

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
    -d "{\"id\":\"$ID1\",\"to\":\"$ID2\",\"type\":\"chat\",\"payload\":\"gl hf\"}" "$BASE/api/signal.php")
expect "chat signal accepted" '"ok":true' "$R"
curl -s "$BASE/api/poll.php?id=$ID2" > /dev/null

LONG=$(printf 'x%.0s' $(seq 1 121))
R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"to\":\"$ID2\",\"type\":\"chat\",\"payload\":\"$LONG\"}" "$BASE/api/signal.php")
expect "chat over 120 bytes rejected" '"error":"invalid payload"' "$R"

R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"to\":\"$ID2\",\"type\":\"offer\",\"payload\":\"$LONG\"}" "$BASE/api/signal.php")
expect "offer allows large payload" '"ok":true' "$R"
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

R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\",\"duel_with\":\"$ID2\"}" "$BASE/api/hello.php")
expect "duel counted" "$(strict '"playing":2')" "$R"

curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID2\",\"latency\":31}" "$BASE/api/hello.php" > /dev/null
R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\",\"friends\":[\"$ID2\",\"aaaa0000\"]}" "$BASE/api/hello.php")
expect "friends online reported" "\"$ID2\":true" "$R"
expect "unknown friend offline" '"aaaa0000":false' "$R"
FL=$(echo "$R" | grep -o '"friends_latency":{[^}]*}')
expect "friend latency reported" "\"$ID2\":31" "$FL"
FN=$(echo "$R" | grep -o '"friends_name":{[^}]*}')
expect "friend name reported" "\"$ID2\":\"SMOKE TWO\"" "$FN"

R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\",\"latency\":99999}" "$BASE/api/hello.php")
expect "absurd latency rejected" '"error":"invalid latency"' "$R"

