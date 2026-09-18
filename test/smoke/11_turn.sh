# TURN credentials (API 4.22): the wire of turn.php and, against a fake
# relay, the cap behind it - the warn line, the stop, the operator's
# switch with its revocations. The fake is a second php -S serving
# test/smoke/turn-fake.php, which turn.json's rtc_base points the server
# at; mints.log and revokes.log in its directory are what the relay saw.
#
# Remote, no fake can run and the key file is the operator's: only the
# wire is asserted there, as the one answer or the other. Two ids, both
# the group's own; nothing here outlives the part - the key file goes,
# the credentials are revoked and the settings put back.
bound "$ID1" "$ID2"
fakeup=0
FAKE_PID=''
turn() { # turn <id> : POST turn.php, print the body
    curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$1\"$(jt "$1")}" "$BASE/api/turn.php"
}
turncode() { # like turn, but prints the HTTP status
    curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$1\"$(jt "$1")}" "$BASE/api/turn.php"
}

R=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/turn.php")
expect "turn.php GET is 405" '405' "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' -d '{"id":"nope"}' "$BASE/api/turn.php")
expect "turn.php refuses an invalid id" '"error":"invalid id"' "$R"
R=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"tok\":\"00000000000000000000000000000000\"}" "$BASE/api/turn.php")
expect "turn.php is behind the identity gate" '401' "$R"

if [ "$REMOTE" -eq 1 ]; then
    R=$(turn "$ID1")
    if [[ "$R" == *'"ice":'* ]]; then
        expect "remote: credentials carry the seconds left" '"ttl":' "$R"
        expect "remote: and a username" '"username":' "$R"
        refute "remote: and no port-53 url" ':53?' "$R"
    else
        expect "remote: or the one refusal" '"error":"turn_unavailable"' "$R"
    fi
else
    R=$(turn "$ID1")
    expect "without a key file the answer is the one refusal" '"error":"turn_unavailable"' "$R"
    R=$(turncode "$ID1")
    expect "as a 503" '503' "$R"

    FAKE="$DATA/fake"
    mkdir -p "$FAKE"
    for attempt in 1 2 3; do
        FPORT=$((8800 + RANDOM % 500))
        FAKE_DIR="$FAKE" php -S "127.0.0.1:$FPORT" test/smoke/turn-fake.php > "$DATA/fake.log" 2>&1 &
        FAKE_PID=$!
        for _ in $(seq 100); do
            if [ "$(curl -s "http://127.0.0.1:$FPORT/")" = fake ]; then
                fakeup=1
                break
            fi
            kill -0 "$FAKE_PID" 2>/dev/null || break
            sleep 0.1
        done
        [ "$fakeup" -eq 1 ] && break
        kill "$FAKE_PID" 2>/dev/null || true
        FAKE_PID=''
    done
    if [ "$fakeup" -ne 1 ]; then
        echo "FAIL the fake relay never answered on 127.0.0.1:$FPORT"
        fail=1
    fi
fi

if [ "$REMOTE" -eq 0 ] && [ "$fakeup" -eq 1 ]; then
    printf '{"key_id":"kid","key_token":"ktok","rtc_base":"http://127.0.0.1:%s"}\n' "$FPORT" > "$DATA/turn.json"

    # The mint: the relay's list handed on, the credential named for the id.
    R=$(turn "$ID1")
    expect "with a key file credentials are minted" '"ok":true' "$R"
    expect "minted for the asking id" "\"username\":\"u1-$ID1\"" "$R"
    expect "with the whole ttl" '"ttl":1800' "$R"
    expect "the relay's urls handed on" 'turns:turn.cloudflare.com:5349?transport=tcp' "$R"
    refute "without the port-53 ones" ':53?' "$R"
    refute "the stun one included" 'com:53"' "$R"
    expect "the fake saw the id and the ttl" "1 $ID1 1800" "$(cat "$FAKE/mints.log")"
    R=$(turn "$ID1")
    expect "the same id asking again gets the same credential" "\"username\":\"u1-$ID1\"" "$R"
    expect "and the relay was not asked again" '1' "$(wc -l < "$FAKE/mints.log" | tr -d ' ')"
    R=$(turn "$ID2")
    expect "a second id is a second mint" "\"username\":\"u2-$ID2\"" "$R"

    # What the dashboard shows.
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=stats")
    expect "the stats carry the TURN bubble: two holders, two handed out, offered" \
        '"turn":{"live":2,"sessions":2,"recent":2,"cap":1000,"offered":true,"why":""}' "$R"
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=turn")
    expect "the popup says the key is there" '"configured":true' "$R"
    expect "and lists a holder" "\"id\":\"$ID1\"" "$R"
    expect "with names beside the ids" '"names":' "$R"
    expect "and the cap" '"cap":1000' "$R"
    refute "and never a username" '"u1-' "$R"
    refute "nor a credential" '"credential"' "$R"

    # The cap: the warn line alerts, the cap refuses, both once.
    setting turn_max_per_30d 4
    # ID1's credential has most of its life left, so its ask is answered
    # the same one and counts nothing; the fresh mints need new ids, and
    # those are removed again below. Locally only, so fixed.
    T3=7e57aaa1
    T4=7e57aaa2
    bound "$T3" "$T4"
    R=$(turn "$T3")
    expect "the third mint" "\"username\":\"u3-$T3\"" "$R"
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=alerts")
    refute "below the warn line no alert" '"type":"turn"' "$R"
    R=$(turn "$T4")
    expect "the fourth mints" "\"username\":\"u4-$T4\"" "$R"
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=alerts")
    expect "and reaching the cap raises the stop alert" '"type":"turn-stop"' "$R"
    expect "naming the cap" '4 credentials handed out in the last 30 days' "$R"
    setting turn_max_per_30d 6
    R=$(turn "$ID1")
    expect "an id holding a credential is answered it whatever the cap" "\"username\":\"u1-$ID1\"" "$R"
    curl -s -b "$COOKIES" -X POST -d "id=$T3" "$BASE/admin/api.php?action=delete_player" > /dev/null
    curl -s -b "$COOKIES" -X POST -d "id=$T4" "$BASE/admin/api.php?action=delete_player" > /dev/null
    unset "TOK[$T3]" "TOK[$T4]"
    setting turn_max_per_30d 4
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=stats")
    expect "the bubble shows the cap reached" '"turn":{"live":4,"sessions":4,"recent":4,"cap":4,"offered":false,"why":"cap"}' "$R"
    setting turn_max_per_30d 1000

    # The operator's switch: refused at once, revoked within the minute.
    setting turn_enabled 0
    R=$(turncode "$ID1")
    expect "turn_enabled 0 refuses at the next ask" '503' "$R"
    R=$(curl -s -b "$COOKIES" -o /dev/null -w '%{http_code}' "$BASE/admin/api.php?action=turn_revoke")
    expect "turn_revoke via GET rejected" '405' "$R"
    R=$(curl -s -b "$COOKIES" -X POST "$BASE/admin/api.php?action=turn_revoke")
    expect "the revoke-all takes every credential out" '"revoked":4' "$R"
    expect "and nobody holds one" '"live":[]' "$R"
    expect "revoked at the relay" "u1-$ID1" "$(cat "$FAKE/revokes.log")"
    expect "by their usernames" "u2-$ID2" "$(cat "$FAKE/revokes.log")"
    expect "all four" '4' "$(wc -l < "$FAKE/revokes.log" | tr -d ' ')"
    setting turn_enabled 1
    R=$(turn "$ID2")
    expect "turn_enabled 1 mints again" "\"username\":\"u5-$ID2\"" "$R"

    # The key going: refused, nothing left behind.
    curl -s -b "$COOKIES" -X POST "$BASE/admin/api.php?action=turn_revoke" > /dev/null
    rm -f "$DATA/turn.json"
    R=$(turncode "$ID1")
    expect "without the key file the refusal is back" '503' "$R"
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=stats")
    expect "the bubble keeps the count and says why" '"turn":{"live":0,"sessions":5,"recent":5,"cap":1000,"offered":false,"why":"unconfigured"}' "$R"
fi
if [ -n "${FAKE_PID:-}" ]; then
    kill "$FAKE_PID" 2>/dev/null || true
    FAKE_PID=''
fi
