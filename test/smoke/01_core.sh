
R=$(curl -s "$BASE/")
expect "landing page" "FOK" "$R"
expect "landing shows public stats" 'client ids' "$R"
expect "landing header carries the server version" 'class="version' "$R"

# The exact path a browser at the game origin takes: CORS must be open. One
# fetch with -i carries the ACAO header AND the version body (same response).
EXPECT_ENV=live
[[ "$BASE" == */staging ]] && EXPECT_ENV=staging
R=$(curl -s -i -H 'Origin: https://poeggi.github.io' "$BASE/api/version.php")
expect "game origin allowed by CORS" 'poeggi.github.io' "$R"
expect "version endpoint" '"server":"' "$R"
expect "api contract version" '"api":' "$R"
expect "environment reported" "\"env\":\"$EXPECT_ENV\"" "$R"
# Resource Timing blanks the connection breakdown of a cross-origin response -
# protocol, connect and TLS marks, transfer sizes - unless the server allows
# it, and a client reads those to tell a cold connection from a warm one. The
# allowlist governs it too, because the breakdown includes the response size.
expect "timing allowed for the game origin"     'timing-allow-origin: https://poeggi.github.io' "$(echo "$R" | tr 'A-Z' 'a-z')"
R=$(curl -s -i -H 'Origin: https://not.allowed.example' "$BASE/api/version.php" | tr 'A-Z' 'a-z')
if echo "$R" | grep -q 'timing-allow-origin'; then
    echo "FAIL an unlisted origin is told the timing"; fail=1
else
    echo "ok   an unlisted origin is not told the timing"
fi
# The preflight is the other half of every cross-origin POST, so it sits on
# the critical path of a duel forming. Apache answers it without starting
# PHP (public/.htaccess). Header names are lowercased before matching: HTTP/2
# sends them that way and php -S does not.
R=$(curl -s -o /dev/null -D - -w 'code=%{http_code}' -X OPTIONS \
    -H 'Origin: https://poeggi.github.io' \
    -H 'Access-Control-Request-Method: POST' "$BASE/api/hello.php" | tr 'A-Z' 'a-z')
expect "CORS preflight passes" 'code=204' "$R"
expect "preflight names an origin" 'access-control-allow-origin' "$R"
expect "preflight allows the method" 'access-control-allow-methods' "$R"
expect "preflight allows the content type" 'access-control-allow-headers' "$R"
expect "preflight answer is cacheable" 'access-control-max-age: 86400' "$R"
# Only real Apache reads .htaccess, so only a remote run can prove where the
# answer came from. PHP writes Cache-Control on every reply it sends
# (Util::cors), so a preflight without one never reached a worker.
if [ "$REMOTE" -eq 1 ]; then
    if echo "$R" | grep -q 'cache-control'; then
        echo "FAIL preflight was answered by PHP, not by Apache"; fail=1
    else
        echo "ok   preflight bypasses PHP"
    fi
    # The clock source is a static file, so its headers come from .htaccess
    # and only a real Apache can answer for them.
    R=$(curl -s -o /dev/null -D - "$BASE/api/t.txt" | tr 'A-Z' 'a-z')
    expect "clock source stamps the arrival time" 'x-fok-t: t=' "$R"
    expect "clock source exposes its stamp" 'access-control-expose-headers: x-fok-t' "$R"
    expect "clock source is never cached" 'cache-control: no-store' "$R"
    expect "clock source allows timing" 'timing-allow-origin: *' "$R"
fi

# The field diagnostic behind "my phone cannot see the lobby on my PC": it
# reports the network the announce matches on, so the two devices can be
# compared without an admin login. Locally the caller is 127.0.0.1, which is
# its own network key, so the shape is what is asserted here.
R=$(curl -s "$BASE/api/net.php")
expect "net diagnostic answers" '"ok":true' "$R"
expect "net diagnostic names the family" '"family":' "$R"
expect "net diagnostic names the network" '"net":' "$R"

R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\"}" "$BASE/api/hello.php")
expect "hello registers" "$(strict '"registered":1')" "$R"
expect "hello online" "$(strict '"online":1')" "$R"
# A poll is a beat too (4.5): a client that only ever polls is online.
curl -s -o /dev/null "$BASE/api/poll.php?id=$ID2"
R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\"}" "$BASE/api/hello.php")
expect "a poll counts as a beat" "$(strict '"online":2')" "$R"
expect "hello carries api version" '"api":' "$R"
# 4.4, both additive. q_ms is this request's own queue wait - the client
# reads it to know not to anchor its clock against a busy moment. pace
# carries the one thing only the server can decide: whether a long poll may
# be held right now. The beat itself is a contract constant, not a field.
expect "hello carries the queue figure" '"q_ms":' "$R"
expect "hello carries the pacing object" '"pace":' "$R"
expect "pacing says whether a hold is allowed" '"hold":' "$R"
if echo "$R" | grep -q '"hello_ms"'; then echo "FAIL pace still hands the beat over"; fail=1; else echo "ok   pace hands over nothing the contract already states"; fi
HN=$(echo "$R" | grep -oE '"now":[0-9]+' | cut -d: -f2)
if [ "${#HN}" -eq 13 ]; then echo "ok   hello now is milliseconds"; else echo "FAIL hello now not ms: $HN"; fail=1; fi

R=$(curl -s -X POST -H 'Content-Type: application/json' -d '{"id":"XYZ"}' "$BASE/api/hello.php")
expect "hello rejects bad id" '"error":"invalid id"' "$R"

# The client tells us its OWN public addresses (STUN), which is the only way
# the family this request did not arrive over can be known. Locally every
# address here is either private or the loopback the request came from, so
# what the endpoint can be held to is that it ACCEPTS the field and refuses
# a malformed one - the recording rules are pinned in test/unit.php.
R=$(curl -s -X POST -H 'Content-Type: application/json' \n    -d "{\"id\":\"$ID1\",\"nets\":[\"198.51.100.4\",\"2a01:db8:5:5::9\"]}" "$BASE/api/hello.php")
expect "hello accepts self-reported networks" '"ok":true' "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' \n    -d "{\"id\":\"$ID1\",\"nets\":\"198.51.100.4\"}" "$BASE/api/hello.php")
expect "hello rejects nets that are not a list" '"error":"invalid nets"' "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' \n    -d "{\"id\":\"$ID1\",\"nets\":[1,2]}" "$BASE/api/hello.php")
expect "hello rejects a net that is not a string" '"error":"invalid nets"' "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' \n    -d "{\"id\":\"$ID1\",\"nets\":[\"1\",\"2\",\"3\",\"4\",\"5\"]}" "$BASE/api/hello.php")
expect "hello rejects more networks than a device can have" '"error":"invalid nets"' "$R"

# An oversized body must be refused loudly, not buffered into a worker.
# The cap is FOK_MAX_BODY (replay material + slack); this clears it.
{ printf '{"id":"%s","pad":"' "$ID1"; head -c 300000 /dev/zero | tr '\0' 'x'; printf '"}'; } > "$DATA/big.json"
R=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
    --data-binary "@$DATA/big.json" "$BASE/api/hello.php")
expect "oversized request body rejected with 413" '413' "$R"

R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"name\":\"SMOKE\",\"score\":4200,\"level\":7,\"diff\":2,\"color\":3,\"shopItems\":{\"hat\":1},\"seed\":42,\"inputs\":[[1,2]],\"platform\":\"mobile\"}" \
    "$BASE/api/scores.php")
expect "score submit" '"rank":1' "$R"

R=$(curl -s "$BASE/api/scores.php")
expect "db seeded with default entry" 'SNAKE PLISSKEN' "$R"
expect "score listed" '"name":"SMOKE"' "$R"
expect "score has color" '"color":3' "$R"
expect "score has shopItems" '"shopItems":{"hat":1}' "$R"
expect "score has date" '"date":"' "$R"
expect "score carries platform" '"platform":"mobile"' "$R"

R=$(curl -s "$BASE/api/scores.php?limit=1")
expect "scores limit works" '"scores":[{' "$R"

R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"name\":\"BADP\",\"score\":5,\"level\":1,\"diff\":1,\"platform\":5}" \
    "$BASE/api/scores.php")
expect "non-string platform rejected" '"error":"invalid platform"' "$R"

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

