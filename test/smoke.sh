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
    ADMIN_USER="${FOK_ADMIN_USER:-}"
    ADMIN_PASS="${FOK_ADMIN_PASS:-}"
    cleanup() { rm -rf "$DATA"; }
else
    REMOTE=0
    PORT=8199
    BASE="http://127.0.0.1:$PORT"
    ID1=deadbeef
    ID2=cafe0001
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

R=$(curl -s "$BASE/")
expect "landing page" "FOK" "$R"

EXPECT_ENV=live
[[ "$BASE" == */staging ]] && EXPECT_ENV=staging
R=$(curl -s "$BASE/api/version.php")
expect "version endpoint" '"server":"' "$R"
expect "api contract version" '"api":' "$R"
expect "environment reported" "\"env\":\"$EXPECT_ENV\"" "$R"

R=$(curl -s "$BASE/api/scores.php")
expect "db seeded with default entry" 'SNAKE PLISSKEN' "$R"

R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\"}" "$BASE/api/hello.php")
expect "hello registers" "$(strict '"registered":1')" "$R"
expect "hello online" "$(strict '"online":1')" "$R"
expect "hello carries api version" '"api":' "$R"
HN=$(echo "$R" | grep -oE '"now":[0-9]+' | cut -d: -f2)
if [ "${#HN}" -eq 13 ]; then echo "ok   hello now is milliseconds"; else echo "FAIL hello now not ms: $HN"; fail=1; fi

R=$(curl -s -X POST -H 'Content-Type: application/json' -d '{"id":"XYZ"}' "$BASE/api/hello.php")
expect "hello rejects bad id" '"error":"invalid id"' "$R"

R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"name\":\"SMOKE\",\"score\":4200,\"level\":7,\"diff\":2,\"color\":3,\"shopItems\":{\"hat\":1},\"seed\":42,\"inputs\":[[1,2]]}" \
    "$BASE/api/scores.php")
expect "score submit" '"rank":1' "$R"

R=$(curl -s "$BASE/api/scores.php")
expect "score listed" '"name":"SMOKE"' "$R"
expect "score has color" '"color":3' "$R"
expect "score has shopItems" '"shopItems":{"hat":1}' "$R"
expect "score has date" '"date":"' "$R"

R=$(curl -s "$BASE/api/scores.php?limit=1")
expect "scores limit works" '"scores":[{' "$R"

for i in $(seq 1 9); do
    curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$ID1\",\"name\":\"S$i\",\"score\":$i,\"level\":1,\"diff\":1}" \
        "$BASE/api/scores.php" > /dev/null
done
R=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"name\":\"SPAM\",\"score\":1,\"level\":1,\"diff\":1}" "$BASE/api/scores.php")
expect "score submissions throttled" '429' "$R"

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

R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"to\":\"$ID2\",\"type\":\"invite\",\"payload\":\"play?\"}" "$BASE/api/signal.php")
expect "signal send" '"ok":true' "$R"

R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID2\"}" "$BASE/api/hello.php")
expect "signal delivered" '"type":"invite"' "$R"

R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID2\"}" "$BASE/api/hello.php")
expect "signal drained" '"signals":[]' "$R"

R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"to\":\"$ID2\",\"type\":\"hack\",\"payload\":\"\"}" "$BASE/api/signal.php")
expect "signal rejects bad type" '"error":"invalid type"' "$R"

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

R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\",\"latency\":99999}" "$BASE/api/hello.php")
expect "absurd latency rejected" '"error":"invalid latency"' "$R"

R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\",\"peer\":\"$ID2\"}" "$BASE/api/start.php")
S1=$(echo "$R" | grep -oE '"start_pts":[0-9]+' | cut -d: -f2)
R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID2\",\"peer\":\"$ID1\"}" "$BASE/api/start.php")
S2=$(echo "$R" | grep -oE '"start_pts":[0-9]+' | cut -d: -f2)
if [ -n "$S1" ] && [ "$S1" = "$S2" ]; then echo "ok   server-issued start identical for both peers"; else echo "FAIL start pts differ: $S1 vs $S2"; fail=1; fi
if [ "${#S1}" -eq 13 ]; then echo "ok   start pts is milliseconds"; else echo "FAIL start pts not ms: $S1"; fail=1; fi

R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\",\"action\":\"seek\"}" "$BASE/api/match.php")
expect "first seeker waits" '"waiting":true' "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID2\",\"action\":\"seek\"}" "$BASE/api/match.php")
expect "second seeker matched" "\"matched\":\"$ID1\"" "$R"
expect "second seeker answers" '"role":"answerer"' "$R"
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

    R=$(curl -s "$BASE/assets/admin.css?v=$VER")
    expect "hidden class wins the cascade" 'display: none !important' "$R"

    R=$(curl -s "$BASE/assets/admin.js?v=$VER")
    N=$(echo "$R" | grep -c "view: 'settings'" || true)
    if [ "$N" -ge 2 ]; then
        echo "ok   config and backup modules live in the settings view ($N)"
    else
        echo "FAIL expected >=2 modules with view: 'settings', found $N"
        fail=1
    fi
    expect "gear toggles the views" 'toggle.onclick' "$R"

    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=props")
    expect "props tile has pts anchor" '1970-01-01T00:00:00.000Z' "$R"
    PN=$(echo "$R" | grep -oE '"pts_now":[0-9]+' | cut -d: -f2)
    if [ "${#PN}" -eq 13 ]; then echo "ok   props pts is milliseconds"; else echo "FAIL props pts not ms: $PN"; fail=1; fi

    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=stats")
    expect "admin stats" '"ok":true' "$R"
    expect "admin stats registered" "$(strict '"registered":2')" "$R"
    expect "admin stats db rows" '"db_rows":' "$R"
    expect "admin stats avg latency" '"avg_latency":' "$R"

    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=backup_create")
    expect "backup via GET rejected" '"error":"POST only"' "$R"

    R=$(curl -s -b "$COOKIES" -X POST "$BASE/admin/api.php?action=backup_create")
    expect "admin backup" '"name":"fok-' "$R"

    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=alerts")
    expect "alerts list" '"ok":true' "$R"
    expect "failed login raised alert" '"type":"admin-fail"' "$R"
    expect "bogus client event logged" '"type":"bogus"' "$R"

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
        curl -s -b "$COOKIES" -X POST -d "id=$ID1" "$BASE/admin/api.php?action=delete_player" > /dev/null
        curl -s -b "$COOKIES" -X POST -d "id=$ID2" "$BASE/admin/api.php?action=delete_player" > /dev/null
        curl -s -b "$COOKIES" -X POST "$BASE/admin/api.php?action=alerts_seen" > /dev/null
        echo "ok   remote test data cleaned up"
    fi
fi

if [ "$fail" -ne 0 ]; then
    echo "SMOKE FAILED"
    exit 1
fi
echo "OK"
