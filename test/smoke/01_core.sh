
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

