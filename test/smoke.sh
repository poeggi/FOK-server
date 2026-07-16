#!/usr/bin/env bash
# Smoke test: boots the app on PHP's built-in server with a throwaway
# data dir and exercises every endpoint over real HTTP.
set -euo pipefail
cd "$(dirname "$0")/.."

PORT=8199
BASE="http://127.0.0.1:$PORT"
DATA="$(mktemp -d)"
COOKIES="$DATA/cookies.txt"

export FOK_DATA_DIR="$DATA"
mkdir -p "$DATA"
php -r 'file_put_contents(getenv("FOK_DATA_DIR")."/admin.hash", password_hash("smoke:test", PASSWORD_DEFAULT));'

php -S "127.0.0.1:$PORT" -t public > "$DATA/server.log" 2>&1 &
SERVER_PID=$!
cleanup() {
    kill "$SERVER_PID" 2>/dev/null || true
    rm -rf "$DATA"
}
trap cleanup EXIT
sleep 1

fail=0
expect() { # expect <name> <needle> <actual>
    if [[ "$3" == *"$2"* ]]; then
        echo "ok   $1"
    else
        echo "FAIL $1: expected '$2' in: $3"
        fail=1
    fi
}

R=$(curl -s "$BASE/")
expect "landing page" "FOK" "$R"

R=$(curl -s -X POST -H 'Content-Type: application/json' -d '{"id":"deadbeef"}' "$BASE/api/hello.php")
expect "hello registers" '"registered":1' "$R"
expect "hello online" '"online":1' "$R"

R=$(curl -s -X POST -H 'Content-Type: application/json' -d '{"id":"XYZ"}' "$BASE/api/hello.php")
expect "hello rejects bad id" '"error":"invalid id"' "$R"

R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d '{"id":"deadbeef","name":"SMOKE","score":4200,"level":7,"diff":2,"color":3,"shopItems":{"hat":1},"seed":42,"inputs":[[1,2]]}' \
    "$BASE/api/scores.php")
expect "score submit" '"rank":1' "$R"

R=$(curl -s "$BASE/api/scores.php")
expect "score listed" '"name":"SMOKE"' "$R"
expect "score has color" '"color":3' "$R"
expect "score has shopItems" '"shopItems":{"hat":1}' "$R"
expect "score has date" '"date":"' "$R"

R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d '{"id":"deadbeef","to":"cafe0001","type":"invite","payload":"play?"}' "$BASE/api/signal.php")
expect "signal send" '"ok":true' "$R"

R=$(curl -s -X POST -H 'Content-Type: application/json' -d '{"id":"cafe0001"}' "$BASE/api/hello.php")
expect "signal delivered" '"type":"invite"' "$R"

R=$(curl -s -X POST -H 'Content-Type: application/json' -d '{"id":"cafe0001"}' "$BASE/api/hello.php")
expect "signal drained" '"signals":[]' "$R"

R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d '{"id":"deadbeef","to":"cafe0001","type":"hack","payload":""}' "$BASE/api/signal.php")
expect "signal rejects bad type" '"error":"invalid type"' "$R"

R=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/poll.php?id=cafe0001")
expect "poll empty is 204" '204' "$R"

curl -s -X POST -H 'Content-Type: application/json' \
    -d '{"id":"deadbeef","to":"cafe0001","type":"ice","payload":"cand"}' "$BASE/api/signal.php" > /dev/null
R=$(curl -s "$BASE/api/poll.php?id=cafe0001")
expect "poll delivers signal" '"type":"ice"' "$R"

R=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/poll.php?id=cafe0001")
expect "poll drained back to 204" '204' "$R"

R=$(curl -s -X POST -H 'Content-Type: application/json' -d '{"id":"deadbeef","duel_with":"cafe0001"}' "$BASE/api/hello.php")
expect "duel counted" '"playing":2' "$R"

R=$(curl -s "$BASE/admin/api.php?action=stats")
expect "admin api needs login" '"error":"not logged in"' "$R"

R=$(curl -s -o /dev/null -w '%{redirect_url}' -c "$COOKIES" -X POST \
    -d 'do=login&user=smoke&pass=wrong' "$BASE/admin/index.php")
expect "admin rejects bad password" 'failed=1' "$R"

R=$(curl -s -o /dev/null -w '%{redirect_url}' -c "$COOKIES" -X POST \
    -d 'do=login&user=smoke&pass=test' "$BASE/admin/index.php")
expect "admin login" 'index.php' "$R"
if [[ "$R" == *"failed"* ]]; then echo "FAIL admin login redirected to failed"; fail=1; fi

R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=stats")
expect "admin stats" '"ok":true' "$R"
expect "admin stats registered" '"registered":2' "$R"

R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=backup_create")
expect "admin backup" '"name":"fok-' "$R"

if [ "$fail" -ne 0 ]; then
    echo "SMOKE FAILED"
    exit 1
fi
echo "OK"
