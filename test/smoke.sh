#!/usr/bin/env bash
# Smoke test over real HTTP.
#
# Local mode (default): boots the app on PHP's built-in server with a
# throwaway data dir and fixed test IDs.
#
# Remote mode: SMOKE_BASE=https://host[/staging] runs the same tests
# against a deployed instance with random test IDs. Admin checks and the
# test-data cleanup need FOK_ADMIN_USER/FOK_ADMIN_PASS in the env; they
# are skipped (with a warning) otherwise. Used to verify staging before
# a live deploy.
set -euo pipefail
cd "$(dirname "$0")/.."

DATA="$(mktemp -d)"
COOKIES="$DATA/cookies.txt"

if [ -n "${SMOKE_BASE:-}" ]; then
    REMOTE=1
    BASE="${SMOKE_BASE%/}"
    ID1=$(od -An -N4 -tx1 /dev/urandom | tr -d ' \n')
    ID2=$(od -An -N4 -tx1 /dev/urandom | tr -d ' \n')
    ID3=$(od -An -N4 -tx1 /dev/urandom | tr -d ' \n')
    ID4=$(od -An -N4 -tx1 /dev/urandom | tr -d ' \n')
    ADMIN_USER="${FOK_ADMIN_USER:-}"
    ADMIN_PASS="${FOK_ADMIN_PASS:-}"
    cleanup() { rm -rf "$DATA"; }
else
    REMOTE=0
    # Random port: a stale server from an aborted run must never be able
    # to answer this run's requests.
    PORT=$((8300 + RANDOM % 500))
    BASE="http://127.0.0.1:$PORT"
    ID1=deadbeef
    ID2=cafe0001
    ID3=f00df00d
    ID4=b0a710ad
    ADMIN_USER=smoke
    ADMIN_PASS=test
    export FOK_DATA_DIR="$DATA"
    php -r 'file_put_contents(getenv("FOK_DATA_DIR")."/admin.hash", password_hash("smoke:test", PASSWORD_DEFAULT));'
    php -S "127.0.0.1:$PORT" -t public > "$DATA/server.log" 2>&1 &
    SERVER_PID=$!
    cleanup() {
        kill "$SERVER_PID" 2>/dev/null || true
        rm -rf "$DATA"
    }
    sleep 1
fi
trap cleanup EXIT

fail=0
expect() { # expect <name> <needle> <actual>
    if [[ "$3" == *"$2"* ]]; then
        echo "ok   $1"
    else
        echo "FAIL $1: expected '$2' in: $3"
        fail=1
    fi
}
# Player/count assertions are exact locally; on a shared remote instance
# other clients may exist, so only the field's presence is asserted.
strict() { if [ "$REMOTE" -eq 0 ]; then echo "$1"; else echo "${1%%:*}:"; fi; }

# Shorthands for the connection endpoints. Payloads are inserted into the
# JSON verbatim, so keep them free of quotes and backslashes.
sig() { # sig <from> <to> <type> <payload>
    curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$1\",\"to\":\"$2\",\"type\":\"$3\",\"payload\":\"$4\"}" "$BASE/api/signal.php"
}
sigcode() { # like sig, but prints the HTTP status instead of the body
    curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$1\",\"to\":\"$2\",\"type\":\"$3\",\"payload\":\"$4\"}" "$BASE/api/signal.php"
}
rly() { # rly <from> <peer> <payload>
    curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$1\",\"peer\":\"$2\",\"payload\":\"$3\"}" "$BASE/api/relay.php"
}
rlycode() { # like rly, but prints the HTTP status instead of the body
    curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$1\",\"peer\":\"$2\",\"payload\":\"$3\"}" "$BASE/api/relay.php"
}
hello() { # hello <id>
    curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$1\"}" "$BASE/api/hello.php"
}
setting() { # setting <key> <value>
    curl -s -b "$COOKIES" -X POST -d "$1=$2" "$BASE/admin/api.php?action=settings_save" > /dev/null
}
# Asserts <needle-a> appears before <needle-b>: order is part of the
# contract for both mailboxes.
ordered() { # ordered <name> <first> <second> <actual>
    if [[ "$4" == *"$2"* && "$4" == *"$3"* && "${4%%$3*}" == *"$2"* ]]; then
        echo "ok   $1"
    else
        echo "FAIL $1: expected '$2' before '$3' in: $4"
        fail=1
    fi
}

# Log in to the admin API up front (when creds are available) so the rate
# and ban tests below can lower their caps via `setting` - a couple of
# requests instead of a flood. Without creds (a bare remote diagnostic run)
# they fall back to flooding at the default caps. The admin section later
# re-tests the login flow in full; this early login does not disturb it.
ADMIN=0
if [ -n "$ADMIN_USER" ]; then
    curl -s -o /dev/null -c "$COOKIES" -X POST \
        --data-urlencode "do=login" --data-urlencode "user=$ADMIN_USER" --data-urlencode "pass=$ADMIN_PASS" \
        "$BASE/admin/index.php"
    ADMIN=1
fi

R=$(curl -s "$BASE/")
expect "landing page" "FOK" "$R"
expect "landing shows public stats" 'client ids' "$R"

# The exact path a browser at the game origin takes: CORS must be open. One
# fetch with -i carries the ACAO header AND the version body (same response).
EXPECT_ENV=live
[[ "$BASE" == */staging ]] && EXPECT_ENV=staging
R=$(curl -s -i -H 'Origin: https://poeggi.github.io' "$BASE/api/version.php")
expect "game origin allowed by CORS" 'poeggi.github.io' "$R"
expect "version endpoint" '"server":"' "$R"
expect "api contract version" '"api":' "$R"
expect "environment reported" "\"env\":\"$EXPECT_ENV\"" "$R"
R=$(curl -s -o /dev/null -w '%{http_code}' -X OPTIONS -H 'Origin: https://poeggi.github.io' \
    -H 'Access-Control-Request-Method: POST' "$BASE/api/hello.php")
expect "CORS preflight passes" '204' "$R"

R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\"}" "$BASE/api/hello.php")
expect "hello registers" "$(strict '"registered":1')" "$R"
expect "hello online" "$(strict '"online":1')" "$R"
expect "hello carries api version" '"api":' "$R"
HN=$(echo "$R" | grep -oE '"now":[0-9]+' | cut -d: -f2)
if [ "${#HN}" -eq 13 ]; then echo "ok   hello now is milliseconds"; else echo "FAIL hello now not ms: $HN"; fail=1; fi

R=$(curl -s -X POST -H 'Content-Type: application/json' -d '{"id":"XYZ"}' "$BASE/api/hello.php")
expect "hello rejects bad id" '"error":"invalid id"' "$R"

# An oversized body must be refused loudly, not buffered into a worker.
# The cap is FOK_MAX_BODY (replay material + slack); this clears it.
{ printf '{"id":"%s","pad":"' "$ID1"; head -c 300000 /dev/zero | tr '\0' 'x'; printf '"}'; } > "$DATA/big.json"
R=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
    --data-binary "@$DATA/big.json" "$BASE/api/hello.php")
expect "oversized request body rejected with 413" '413' "$R"

R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"name\":\"SMOKE\",\"score\":4200,\"level\":7,\"diff\":2,\"color\":3,\"shopItems\":{\"hat\":1},\"seed\":42,\"inputs\":[[1,2]]}" \
    "$BASE/api/scores.php")
expect "score submit" '"rank":1' "$R"

R=$(curl -s "$BASE/api/scores.php")
expect "db seeded with default entry" 'SNAKE PLISSKEN' "$R"
expect "score listed" '"name":"SMOKE"' "$R"
expect "score has color" '"color":3' "$R"
expect "score has shopItems" '"shopItems":{"hat":1}' "$R"
expect "score has date" '"date":"' "$R"

R=$(curl -s "$BASE/api/scores.php?limit=1")
expect "scores limit works" '"scores":[{' "$R"

# Trip the per-player submit throttle. With admin, lower the cap so a few
# submits suffice; otherwise flood to the default cap (10).
subs=9
if [ "$ADMIN" -eq 1 ]; then setting score_rate_max 2; subs=3; fi
for i in $(seq 1 "$subs"); do
    curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$ID1\",\"name\":\"S$i\",\"score\":$i,\"level\":1,\"diff\":1}" \
        "$BASE/api/scores.php" > /dev/null
done
R=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"name\":\"SPAM\",\"score\":1,\"level\":1,\"diff\":1}" "$BASE/api/scores.php")
expect "score submissions throttled" '429' "$R"
[ "$ADMIN" -eq 1 ] && setting score_rate_max 10

# Client config backup / restore, token-secured (see docs/API.md). ID2 is a
# registered player, so the admin client view finds its backup below.
R=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/backup.php?id=$ID2&token=nope")
expect "restore with no backup is 404" '404' "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID2\",\"payload\":\"config-blob-1\"}" "$BASE/api/backup.php")
expect "first backup stored" '"ok":true' "$R"
expect "first backup mints a token" '"token":"' "$R"
BTOKEN=$(echo "$R" | grep -oE '"token":"[a-f0-9]+"' | cut -d'"' -f4)
R=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/backup.php?id=$ID2")
expect "restore without a token is refused" '400' "$R"
R=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/backup.php?id=$ID2&token=00000000000000000000000000000000")
expect "restore with a wrong token is 403" '403' "$R"
R=$(curl -s "$BASE/api/backup.php?id=$ID2&token=$BTOKEN")
expect "restore with the token returns the config" 'config-blob-1' "$R"
R=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID2\",\"payload\":\"take-over\"}" "$BASE/api/backup.php")
expect "overwrite without the token is 403" '403' "$R"
curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID2\",\"payload\":\"config-blob-2\",\"token\":\"$BTOKEN\"}" "$BASE/api/backup.php" > /dev/null
R=$(curl -s "$BASE/api/backup.php?id=$ID2&token=$BTOKEN")
expect "a tokened overwrite replaces the config" 'config-blob-2' "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"nothex\",\"payload\":\"x\"}" "$BASE/api/backup.php")
expect "backup rejects a malformed id" '"error":"invalid id"' "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID4\"}" "$BASE/api/backup.php")
expect "backup rejects a missing payload" '"error":"invalid payload"' "$R"
{ printf '{"id":"%s","payload":"' "$ID4"; head -c 70000 /dev/zero | tr '\0' 'x'; printf '"}'; } > "$DATA/bigbak.json"
R=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
    --data-binary "@$DATA/bigbak.json" "$BASE/api/backup.php")
expect "oversized backup rejected with 413" '413' "$R"

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

# The epoch is what makes the answer independent of WHEN a peer asks: the
# same epoch must return the same moment however late the second one is.
R=$(start_req "$ID2" "$ID1" 0 first "$(now_ms)")
expect "a late peer re-asking the same epoch gets the same start" "\"start_pts\":$S1" "$R"

# Every halt of the run is its own epoch.
R=$(start_req "$ID1" "$ID2" 1 respawn "$(now_ms)")
expect "a respawn issues a new start" '"start_pts":' "$R"
expect "the new start echoes its epoch" '"epoch":1' "$R"
R=$(start_req "$ID1" "$ID2" 2 resume "$(now_ms)")
expect "a resume from pause issues a start" '"epoch":2' "$R"

# A peer left behind is told loudly, not handed a start it would misplace.
R=$(start_req "$ID2" "$ID1" 0 first "$(now_ms)")
expect "a stale epoch is refused" 'stale epoch' "$R"

# The sync gate: a start is a moment on the shared clock. pts is required
# for every reason and can never be in the future.
R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"peer\":\"$ID2\",\"epoch\":3,\"reason\":\"level\"}" "$BASE/api/start.php")
expect "a start without a sync proof is refused" 'pts required' "$R"
R=$(start_req "$ID1" "$ID2" 3 level "$(( $(now_ms) + 60000 ))")
expect "a start with a future pts is bogus" 'bogus pts' "$R"
# A STALE proof is refused only where play BEGINS (first/rematch): the
# pair must enter the run aligned there.
R=$(start_req "$ID1" "$ID2" 3 rematch "$(( $(now_ms) - 120000 ))")
expect "a stale sync proof is refused where play begins" 'stale pts' "$R"
# The in-run halts are permissive: the pair is already synced, so a stale
# proof does NOT block a resume - the client resyncs as it goes.
R=$(start_req "$ID1" "$ID2" 3 level "$(( $(now_ms) - 120000 ))")
expect "a stale sync proof does not block an in-run start" '"start_pts":' "$R"
R=$(start_req "$ID1" "$ID2" 3 nonsense "$(now_ms)")
expect "an unknown start reason is refused" 'invalid reason' "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"peer\":\"$ID2\",\"epoch\":-1,\"reason\":\"level\",\"pts\":$(now_ms)}" "$BASE/api/start.php")
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

# Aborting a relayed duel takes its undelivered backlog with it: a stale
# input from the finished duel must never land in the next one.
rly "$ID1" "$ID2" 'IN:stale' > /dev/null
sig "$ID1" "$ID2" bye '' > /dev/null
R=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/relay.php?id=$ID2&peer=$ID1")
expect "bye drops the pair's relay backlog" '204' "$R"
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
# bye travels over it and never reaches the server (docs/API.md, the 1:1
# flow), so nothing here says the duel ended. The pair's finished epoch
# line must not survive to refuse their next match: without the handshake
# reset in signal.php this 409s for a full five minutes.
start_req "$ID1" "$ID2" 0 first "$(now_ms)" > /dev/null
start_req "$ID1" "$ID2" 1 level "$(now_ms)" > /dev/null
sig "$ID1" "$ID2" invite 'rematch please' > /dev/null
curl -s "$BASE/api/poll.php?id=$ID2" > /dev/null
R=$(start_req "$ID1" "$ID2" 0 first "$(now_ms)")
expect "a rematch after a peer-to-peer bye still gets a start" '"start_pts":' "$R"
# Quick match has no invite at all: the offer is what opens that pairing.
start_req "$ID1" "$ID2" 1 level "$(now_ms)" > /dev/null
sig "$ID1" "$ID2" offer 'sdp-rematch' > /dev/null
curl -s "$BASE/api/poll.php?id=$ID2" > /dev/null
R=$(start_req "$ID1" "$ID2" 0 first "$(now_ms)")
expect "an offer opens a fresh epoch line too (quick match)" '"start_pts":' "$R"
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
curl -s "$BASE/api/poll.php?id=$ID2" > /dev/null
curl -s "$BASE/api/poll.php?id=$ID1" > /dev/null

# Mass friend requests: alert + timed ban + purge of the spammer's pendings.
# With admin, lower the cap so a handful trips the ban; otherwise flood (15).
freqs=$(seq 10 26)
if [ "$ADMIN" -eq 1 ]; then setting friend_req_max 3; freqs=$(seq 10 16); fi
for i in $freqs; do
    R=$(curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$ID1\",\"action\":\"request\",\"peer\":\"aa0000$i\"}" "$BASE/api/friend.php")
done
expect "friend-request spam banned" 'banned' "$R"
[ "$ADMIN" -eq 1 ] && setting friend_req_max 15
R=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"action\":\"request\",\"peer\":\"aa000099\"}" "$BASE/api/friend.php")
expect "banned client stays banned" '429' "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\",\"action\":\"list\"}" "$BASE/api/friend.php")
if echo "$R" | grep -q '"state":"pending"'; then
    echo "FAIL spammer pendings not purged: $R"; fail=1
else
    echo "ok   spammer pending requests purged"
fi
R=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"to\":\"$ID2\",\"type\":\"invite\",\"payload\":\"again?\"}" "$BASE/api/signal.php")
expect "invite blocked again after removal" '403' "$R"

R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\",\"action\":\"seek\"}" "$BASE/api/match.php")
expect "first seeker waits" '"waiting":true' "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID2\",\"action\":\"seek\"}" "$BASE/api/match.php")
expect "second seeker matched" "\"matched\":\"$ID1\"" "$R"
expect "second seeker answers" '"role":"answerer"' "$R"
expect "match carries peer name" '"peer_name":"SMOKE ONE"' "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\",\"action\":\"seek\"}" "$BASE/api/match.php")
expect "first seeker offers" '"role":"offerer"' "$R"

if [ -z "$ADMIN_USER" ]; then
    echo "WARN admin checks and cleanup skipped: set FOK_ADMIN_USER/FOK_ADMIN_PASS"
else
    R=$(curl -s "$BASE/admin/api.php?action=stats")
    expect "admin api needs login" '"error":"not logged in"' "$R"

    R=$(curl -s -o /dev/null -w '%{redirect_url}' -c "$COOKIES" -X POST \
        -d "do=login&user=$ADMIN_USER&pass=definitely-wrong" "$BASE/admin/index.php")
    expect "admin rejects bad password" 'failed=1' "$R"

    R=$(curl -s -o /dev/null -w '%{redirect_url}' -c "$COOKIES" -X POST \
        --data-urlencode "do=login" --data-urlencode "user=$ADMIN_USER" --data-urlencode "pass=$ADMIN_PASS" \
        "$BASE/admin/index.php")
    expect "admin login" 'index.php' "$R"
    if [[ "$R" == *"failed"* ]]; then echo "FAIL admin login redirected to failed"; fail=1; fi

    VER=$(grep -oE "FOK_SERVER_VERSION = '[^']+'" public/src/Config.php | cut -d"'" -f2)
    R=$(curl -s -i -b "$COOKIES" "$BASE/admin/index.php")
    H=$(echo "$R" | grep -i '^cache-control' || true)
    expect "admin page is no-store" 'no-store' "$H"
    expect "admin has gear button" 'id="viewtoggle"' "$R"
    expect "admin has settings view" 'id="settings"' "$R"
    expect "admin assets cache-busted" "admin.js?v=$VER" "$R"

    # Fetch each static asset ONCE and run every assertion against the saved
    # copy (they are byte-identical at both check sites; ?v= is the version).
    CSS_ASSET=$(curl -s "$BASE/assets/admin.css?v=$VER")
    expect "hidden class wins the cascade" 'display: none !important' "$CSS_ASSET"

    JS_ASSET=$(curl -s "$BASE/assets/admin.js?v=$VER")
    N=$(echo "$JS_ASSET" | grep -c "view: 'settings'" || true)
    if [ "$N" -ge 2 ]; then
        echo "ok   config and backup modules live in the settings view ($N)"
    else
        echo "FAIL expected >=2 modules with view: 'settings', found $N"
        fail=1
    fi
    expect "gear toggles the views" 'toggle.onclick' "$JS_ASSET"

    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=props")
    expect "props tile has pts anchor" '1970-01-01T00:00:00.000Z' "$R"
    PN=$(echo "$R" | grep -oE '"pts_now":[0-9]+' | cut -d: -f2)
    if [ "${#PN}" -eq 13 ]; then echo "ok   props pts is milliseconds"; else echo "FAIL props pts not ms: $PN"; fail=1; fi
    # The host capabilities each decide whether an optimisation is even
    # available; shared hosting has no other way to ask.
    expect "props reports the php sapi" '"sapi":' "$R"
    expect "props reports opcache availability" '"opcache":' "$R"
    expect "props reports apcu availability" '"apcu":' "$R"
    expect "props reports deferred-flush availability" '"deferred_flush":' "$R"
    expect "props reports what opening the db cost" '"db_boot_us":' "$R"

    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=stats")
    expect "admin stats" '"ok":true' "$R"
    expect "admin stats registered" "$(strict '"registered":2')" "$R"
    expect "admin stats db rows" '"db_rows":' "$R"
    expect "admin stats friendships" '"friendships":' "$R"
    expect "admin stats pending friendships" '"friendships_pending":' "$R"
    expect "admin stats carry the live load gauges" '"load_live":' "$R"
    expect "live load gauges include db writes" '"db_writes":' "$R"

    # Connection tracker: the admin sees the state the signaling implies.
    # These types need no friendship, so they work after the unfriend above.
    curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$ID1\",\"to\":\"$ID2\",\"type\":\"offer\",\"payload\":\"sdp\"}" "$BASE/api/signal.php" > /dev/null
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=duels")
    expect "duels listed" '"duels":' "$R"
    expect "a client in a handshake is on the Duels card" "$(strict "\"id\":\"$ID1\"")" "$R"
    expect "handshake tracked as connecting" '"state":"connecting"' "$R"
    expect "duel peer tracked" "$(strict "\"peer\":\"$ID2\"")" "$R"
    expect "duel mode tracked as p2p" '"mode":"p2p"' "$R"

    curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$ID2\",\"to\":\"$ID1\",\"type\":\"accept-relay\",\"payload\":\"{}\"}" "$BASE/api/signal.php" > /dev/null
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=duels")
    expect "no-p2p declaration tracked as relay" '"mode":"relay"' "$R"

    curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$ID1\",\"duel_with\":\"$ID2\"}" "$BASE/api/hello.php" > /dev/null
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=duels")
    expect "running duel tracked as playing" '"state":"playing"' "$R"
    expect "playing keeps the relay mode" '"mode":"relay"' "$R"
    # Presence is the full picture now: a client in a duel is on Connections
    # too, and only the Duels card breaks out the duel phase.
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=conns")
    expect "a dueling client also shows on Connections (online)" "$(strict "\"id\":\"$ID1\"")" "$R"

    # A decline leaves the decliner on a short-lived 'declined' row naming
    # who it turned down, so the Duels card shows the rejection and who
    # made it; the inviter drops back to idle (the Connections card).
    curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$ID2\",\"to\":\"$ID1\",\"type\":\"decline\",\"payload\":\"\"}" "$BASE/api/signal.php" > /dev/null
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=duels")
    expect "a decline shows as declined" '"state":"declined"' "$R"
    expect "the declined row names the decliner" "$(strict "\"id\":\"$ID2\"")" "$R"
    expect "the declined row names who was turned down" "$(strict "\"peer\":\"$ID1\"")" "$R"

    curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$ID1\",\"to\":\"$ID2\",\"type\":\"bye\",\"payload\":\"\"}" "$BASE/api/signal.php" > /dev/null
    # A clean bye no longer wipes the pair: the duel lingers as 'ended' on
    # the Duels card for FOK_DUEL_LINGER seconds instead of vanishing.
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=duels")
    expect "an ended duel lingers on the Duels card" '"state":"ended"' "$R"
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=conns")
    expect "connections (presence) listed" '"conns":' "$R"
    expect "after bye a client is back on the Connections card" "$(strict "\"id\":\"$ID1\"")" "$R"

    # Debug flag lives per registered user now: the admin sets a wish, the
    # client honours it on its next hello and reports what it actually did.
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=users")
    expect "a client is not debugging by default" '"debug":false' "$R"
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=set_debug" -d "id=$ID1&on=1")
    expect "admin sets a client to debug" '"ok":true' "$R"
    R=$(curl -s -b "$COOKIES" -o /dev/null -w '%{http_code}' "$BASE/admin/api.php?action=set_debug&id=$ID1&on=1")
    expect "set_debug via GET rejected" '405' "$R"
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=set_debug" -d "id=nothex&on=1")
    expect "set_debug rejects a malformed id" '"error":"invalid id"' "$R"
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=users")
    expect "the wish shows before the client picks it up" '"debug":true,"debug_active":false' "$R"

    R=$(curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$ID1\"}" "$BASE/api/hello.php")
    expect "hello hands the debug wish to the client" '"debug":true' "$R"
    curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$ID1\",\"debug\":true}" "$BASE/api/hello.php" > /dev/null
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=users")
    expect "the client reports it honoured the wish" '"debug":true,"debug_active":true' "$R"

    R=$(curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$ID1\",\"debug\":\"yes\"}" "$BASE/api/hello.php")
    expect "a non-boolean debug report is rejected" '"error":"invalid debug"' "$R"

    curl -s -b "$COOKIES" "$BASE/admin/api.php?action=set_debug" -d "id=$ID1&on=0" > /dev/null
    R=$(curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$ID1\",\"debug\":true}" "$BASE/api/hello.php")
    expect "the wish can be withdrawn" '"debug":false' "$R"
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=users")
    expect "a client debugging by itself still reports active" '"debug":false,"debug_active":true' "$R"

    # Client details popup: one condensed view of everything about an id.
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=client&id=$ID1")
    expect "client details returned" '"client":' "$R"
    expect "client details name the client" "$(strict "\"id\":\"$ID1\"")" "$R"
    expect "client details include presence" '"last_seen":' "$R"
    expect "client details include the 1:1 state" '"duel":' "$R"
    expect "client details include the mailbox" '"mailbox":' "$R"
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=client&id=nothex")
    expect "client details reject a malformed id" '"error":"invalid id"' "$R"
    R=$(curl -s -b "$COOKIES" -o /dev/null -w '%{http_code}' "$BASE/admin/api.php?action=client&id=12345678")
    expect "client details 404 for an unknown id" '404' "$R"

    # Config backup surfaced in the details popup + operator download (no
    # token). ID2 was backed up in the public-API section above.
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=client&id=$ID2")
    expect "client details include the config backup" '"backup":' "$R"
    expect "the backup reports its size" '"bytes":' "$R"
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=vault_export&id=$ID2")
    expect "operator exports a config without the token" 'config-blob-2' "$R"
    R=$(curl -s -b "$COOKIES" -i "$BASE/admin/api.php?action=vault_export&id=$ID2" | grep -i '^content-disposition' || true)
    expect "the export downloads as a snake-fok-backup file" 'snake-fok-backup' "$R"
    R=$(curl -s -b "$COOKIES" -o /dev/null -w '%{http_code}' "$BASE/admin/api.php?action=vault_export&id=$ID1")
    expect "export 404s for a client with no backup" '404' "$R"
    expect "the details popup offers a backup download" "action=vault_export" "$JS_ASSET"

    expect "connections card on the dashboard" "id: 'conns'" "$JS_ASSET"
    expect "duels card on the dashboard" "id: 'duels'" "$JS_ASSET"
    expect "connections card has its own interval" "every: 'admin_conns_refresh_secs'" "$JS_ASSET"
    expect "duels card has its own interval" "every: 'admin_duels_refresh_secs'" "$JS_ASSET"
    expect "global refresh interval sits in the top bar" "prepend(intervalControl('admin_refresh_secs'" "$JS_ASSET"
    expect "the users card can toggle debug" "api('set_debug'" "$JS_ASSET"
    expect "tables can be sorted by column" "function sortable(" "$JS_ASSET"
    expect "sorting survives the refresh (delegated on the card body)" "_sortBound" "$JS_ASSET"
    expect "an id opens the client details popup" "function showClient(" "$JS_ASSET"
    expect "the details popup is wired to the client endpoint" "api('client&id='" "$JS_ASSET"
    expect "ipv6 is truncated to first and last group" "function ipCell(" "$JS_ASSET"
    expect "registered users has a live id/name filter" "Filter by ID or name" "$JS_ASSET"
    expect "the details popup has its own auto-refresh" "clientRefreshSecs" "$JS_ASSET"
    expect "statistics show the live load gauges" "Msgs in/min" "$JS_ASSET"
    expect "header controls share one architecture-wide size" "--ctl-w" "$CSS_ASSET"

    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=settings")
    expect "global refresh interval defaults to 30 s" '"key":"admin_refresh_secs","value":30' "$R"
    expect "connections refresh interval defaults to 1 s" '"key":"admin_conns_refresh_secs","value":1' "$R"
    expect "duels refresh interval defaults to 1 s" '"key":"admin_duels_refresh_secs","value":1' "$R"

    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=backup_create")
    expect "backup via GET rejected" '"error":"POST only"' "$R"

    R=$(curl -s -b "$COOKIES" -X POST "$BASE/admin/api.php?action=backup_create")
    expect "admin backup" '"name":"fok-' "$R"

    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=alerts")
    expect "alerts list" '"ok":true' "$R"
    expect "failed login raised alert" '"type":"admin-fail"' "$R"
    expect "bogus client event logged" '"type":"bogus"' "$R"
    expect "friend spam logged" '"type":"friend-spam"' "$R"

    R=$(curl -s -b "$COOKIES" -X POST "$BASE/admin/api.php?action=alerts_seen")
    expect "alerts mark seen" '"ok":true' "$R"
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=alerts")
    expect "unseen count cleared" '"unseen":0' "$R"

    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=settings")
    expect "settings listed" '"key":"admin_max_fails"' "$R"

    R=$(curl -s -b "$COOKIES" -X POST -d 'chat_max_len=10' "$BASE/admin/api.php?action=settings_save")
    expect "settings saved" '"ok":true' "$R"
    R=$(curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$ID2\",\"to\":\"$ID1\",\"type\":\"chat\",\"payload\":\"12345678901\"}" "$BASE/api/signal.php")
    expect "lowered chat cap applies live" '"error":"invalid payload"' "$R"
    curl -s -b "$COOKIES" -X POST -d 'chat_max_len=120' "$BASE/admin/api.php?action=settings_save" > /dev/null

    R=$(curl -s -b "$COOKIES" -X POST -d 'admin_max_fails=notanumber' "$BASE/admin/api.php?action=settings_save")
    expect "bad setting value rejected" '"error":"invalid value' "$R"

    # --- Loud failures. Every cap must answer a distinct status the
    # client can act on, never a silent ok:true. Driven through the
    # runtime settings so the test does not have to flood the real caps.
    setting mailbox_cap 2
    sig "$ID1" "$ID2" ice 'm1' > /dev/null
    sig "$ID1" "$ID2" ice 'm2' > /dev/null
    R=$(sigcode "$ID1" "$ID2" ice 'm3')
    expect "a full mailbox fails loudly with 429" '429' "$R"
    setting mailbox_cap 64
    curl -s "$BASE/api/poll.php?id=$ID2" > /dev/null

    setting relay_pending_cap 2
    rly "$ID1" "$ID2" 'p1' > /dev/null
    rly "$ID1" "$ID2" 'p2' > /dev/null
    R=$(rlycode "$ID1" "$ID2" 'p3')
    expect "a full relay backlog fails loudly with 429" '429' "$R"
    setting relay_pending_cap 128
    curl -s "$BASE/api/relay.php?id=$ID2&peer=$ID1" > /dev/null

    # A client SUSTAINING too high a send rate (over relay_rate_max for
    # more than a timeslice) is blocked, not just backpressured. Throwaway
    # ids so the 30s block touches nothing else; needs a >1s window.
    setting relay_rate_max 1
    for i in 1 2 3 4 5; do rly aa11aa11 bb22bb22 "f$i" > /dev/null; done
    sleep 3
    rly aa11aa11 bb22bb22 'trips the rate check' > /dev/null
    # The block is SET by a deferred (post-response) write; under FPM it
    # lands a beat after the tripping message returns, so let it commit
    # before the enforcement read - otherwise the check can race it.
    sleep 2
    R=$(rlycode aa11aa11 bb22bb22 'now blocked')
    expect "a sustained relay flood is blocked with 429" '429' "$R"
    setting relay_rate_max 128

    # A full hub rejects a NEW relayed duel loudly - but a duel that is
    # already relaying must never be cut off by it.
    setting relay_max_duels 1
    rly "$ID1" "$ID2" 'holding the slot' > /dev/null
    R=$(rlycode "$ID3" "$ID4" 'may i')
    expect "relay cap rejects a new duel with 503" '503' "$R"
    R=$(sigcode "$ID3" "$ID4" accept-relay '{}')
    expect "relay cap rejects a no-p2p declaration with 503" '503' "$R"
    R=$(rly "$ID1" "$ID2" 'still here')
    expect "a duel already relaying is never cut off" '"ok":true' "$R"

    # Only real hub traffic may hold a slot. accept-relay is not
    # friendship-gated, so if a bare declaration counted, a few invented
    # pairs would deny the relay to the whole server.
    setting relay_max_duels 2
    curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$ID3\",\"to\":\"$ID4\",\"type\":\"accept-relay\",\"payload\":\"{}\"}" \
        "$BASE/api/signal.php" > /dev/null
    R=$(rly "$ID1" "$ID2" 'still mine')
    expect "a claimed relay duel cannot squeeze out a real one" '"ok":true' "$R"

    # A stranger must not be able to end a duel it has nothing to do with.
    sig "$ID3" "$ID1" bye '' > /dev/null
    R=$(rly "$ID1" "$ID2" 'stranger cannot end this')
    expect "a stranger's bye cannot kill a live relayed duel" '"ok":true' "$R"
    setting relay_max_duels 3
    curl -s "$BASE/api/relay.php?id=$ID2&peer=$ID1" > /dev/null
    curl -s "$BASE/api/poll.php?id=$ID1" > /dev/null
    curl -s "$BASE/api/poll.php?id=$ID4" > /dev/null

    # An invite nobody picks up must not evaporate behind its ok:true:
    # the sender is told. (The sweep runs on the next mailbox read, so
    # the inviter's own heartbeat both raises and delivers the receipt.)
    # Uses the fresh pair: ID1/ID2 unfriended above, and ID1 is still
    # friend-request banned by the spam test.
    curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$ID3\",\"action\":\"request\",\"peer\":\"$ID4\"}" "$BASE/api/friend.php" > /dev/null
    curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$ID4\",\"action\":\"accept\",\"peer\":\"$ID3\"}" "$BASE/api/friend.php" > /dev/null
    curl -s "$BASE/api/poll.php?id=$ID3" > /dev/null
    curl -s "$BASE/api/poll.php?id=$ID4" > /dev/null
    setting signal_ttl 1
    R=$(sig "$ID3" "$ID4" invite 'anyone?')
    expect "invite to the fresh friend accepted" '"ok":true' "$R"
    sleep 2
    R=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/poll.php?id=$ID4")
    expect "an expired invite is not delivered" '204' "$R"
    R=$(hello "$ID3")
    expect "the inviter is told the invite went undelivered" '"type":"undelivered"' "$R"
    expect "the receipt names the peer" "\"from\":\"$ID4\"" "$R"
    expect "the receipt names the lost message type" 'invite' "$R"
    setting signal_ttl 30
    curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$ID3\",\"action\":\"remove\",\"peer\":\"$ID4\"}" "$BASE/api/friend.php" > /dev/null

    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=config_export")
    expect "config export" '"chat_max_len"' "$R"
    R=$(curl -s -b "$COOKIES" -X POST --data-urlencode "config=$R" "$BASE/admin/api.php?action=config_import")
    expect "config import roundtrip" '"ok":true' "$R"
    R=$(curl -s -b "$COOKIES" -X POST --data-urlencode 'config={"nope":1}' "$BASE/admin/api.php?action=config_import")
    expect "config import rejects unknown key" '"error":"unknown setting' "$R"

    if [ "$REMOTE" -eq 1 ]; then
        # Remove this run's test data from the remote instance.
        for sid in $(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=scores" \
                | grep -o "\"id\":[0-9]*,\"player_id\":\"$ID1\"" | grep -oE '"id":[0-9]+' | cut -d: -f2); do
            curl -s -b "$COOKIES" -X POST -d "id=$sid" "$BASE/admin/api.php?action=delete_score" > /dev/null
        done
        curl -s -X POST -H 'Content-Type: application/json' \
            -d "{\"id\":\"$ID1\",\"action\":\"remove\",\"peer\":\"$ID2\"}" "$BASE/api/friend.php" > /dev/null
        for pid in "$ID1" "$ID2" "$ID3" "$ID4"; do
            curl -s -b "$COOKIES" -X POST -d "id=$pid" "$BASE/admin/api.php?action=delete_player" > /dev/null
        done
        curl -s -b "$COOKIES" -X POST "$BASE/admin/api.php?action=alerts_seen" > /dev/null
        echo "ok   remote test data cleaned up"
    fi
fi

if [ "$fail" -ne 0 ]; then
    echo "SMOKE FAILED"
    exit 1
fi
echo "OK"
