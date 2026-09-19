# The id itself (API 4.23): the client's version on hello and the upgrade
# word, and account.php - the owner's delete, the move to another device.
# The decisions are unit-tested against Clients and Account; this is the
# wire: the members, the answers, the refusals, and that a delete really
# removes the id from the count the admin part asserts.
#
# Its own two ids, touched by no other part: one is deleted here on
# purpose, the other moves. Both are gone by the end.
if [ "$REMOTE" -eq 1 ]; then
    XA=$(od -An -N4 -tx1 /dev/urandom | tr -d ' \n')
    XB=$(od -An -N4 -tx1 /dev/urandom | tr -d ' \n')
else
    XA=ac700001
    XB=ac700002
fi
acct() { # acct <json-body> : prints the body
    curl -s -X POST -H 'Content-Type: application/json' -d "$1" "$BASE/api/account.php"
}
acctcode() { # acctcode <json-body> : prints the HTTP status
    curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' -d "$1" "$BASE/api/account.php"
}

# The version on hello: recorded, and answered nothing while no floor is set.
bind "$XA" ',"name":"srv-CI-acct","client":"4.5.12","platform":"ios"'
refute "a hello naming a version is told nothing while the floors are off" '"upgrade"' "$R"
R=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$XA\"$(jt "$XA"),\"client\":\"v4.5.12\"}" "$BASE/api/hello.php")
expect "a version with a v is refused" '400' "$R"
R=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$XA\"$(jt "$XA"),\"platform\":\"iOS\"}" "$BASE/api/hello.php")
expect "and so is a platform with a capital" '400' "$R"
if [ "$ADMIN" -eq 1 ]; then
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=client&id=$XA")
    expect "the admin sees the build" '"client":"4.5.12","platform":"ios"' "$R"
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=clients")
    expect "and the spread lists it" '"client":"4.5.12","platform":"ios"' "$R"
    # The floors are string settings: saved from the card as x.y.z, and a
    # typo is refused rather than stored as a silent off.
    R=$(curl -s -b "$COOKIES" -X POST -d 'client_min_version=4.5.' "$BASE/admin/api.php?action=settings_save")
    expect "a floor that is not a version is refused" '"error":"invalid value' "$R"
    setting client_min_version 4.6.0
    R=$(curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$XA\"$(jt "$XA"),\"client\":\"4.5.12\"}" "$BASE/api/hello.php")
    expect "a build below the minimum is told upgrade: required" '"upgrade":"required"' "$R"
    R=$(hello "$XA")
    refute "a hello naming no version is told nothing" '"upgrade"' "$R"
    setting client_min_version 0
    setting client_advised_version 4.6.0
    R=$(curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$XA\"$(jt "$XA"),\"client\":\"4.5.12\"}" "$BASE/api/hello.php")
    expect "a build below the advised floor is told upgrade: advised" '"upgrade":"advised"' "$R"
    R=$(curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$XA\"$(jt "$XA"),\"client\":\"4.6.0\"}" "$BASE/api/hello.php")
    refute "and one at the floor is told nothing" '"upgrade"' "$R"
    setting client_advised_version 0
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=settings")
    expect "a floor saved at 0 reads 0" '"key":"client_advised_version","value":"0","default":"0"' "$R"
fi

# The move: a code from the old device, a fresh token for the new one, the
# old token retired.
R=$(acct "{\"id\":\"$XA\",\"action\":\"transfer\"}")
expect "transfer without the token is refused" '"error":"bad token"' "$R"
R=$(acct "{\"id\":\"$XA\"$(jt "$XA"),\"action\":\"transfer\"}")
expect "transfer answers a code" '"code":"' "$R"
expect "and how long it is valid" '"valid":300' "$R"
CODE=$(echo "$R" | grep -oE '"code":"[A-Z2-9]{8}"' | cut -d'"' -f4)
expect "the code is 8 characters of the poster alphabet" '8' "${#CODE}"
R=$(acctcode '{"action":"claim","code":"AAAAAAAA"}')
expect "a wrong code is 404" '404' "$R"
R=$(acctcode '{"action":"claim","code":"0O1IL"}')
expect "a code of the wrong shape is 400" '400' "$R"
OLD=${TOK[$XA]}
R=$(acct "{\"action\":\"claim\",\"code\":\"$CODE\"}")
expect "the claim answers the id" "\"id\":\"$XA\"" "$R"
expect "and a fresh token" '"tok":"' "$R"
TOK[$XA]=$(echo "$R" | grep -oE '"tok":"[a-f0-9]{32}"' | cut -d'"' -f4 || true)
[ "${TOK[$XA]}" != "$OLD" ] && echo "ok   the token is a new one" || { echo "FAIL the token is the old one"; fail=1; }
R=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$XA\",\"tok\":\"$OLD\"}" "$BASE/api/hello.php")
expect "the old device is 401 from then on" '401' "$R"
R=$(hello "$XA")
expect "while the new one proves the id" '"ok":true' "$R"
R=$(acctcode "{\"action\":\"claim\",\"code\":\"$CODE\"}")
expect "a code is used once" '404' "$R"
if [ "$ADMIN" -eq 1 ]; then
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=log")
    expect "the move is on record" "FOK account: id $XA srv-CI-acct moved to a new device from" "$R"
fi

# The owner's delete: the id and what hangs off it, gone.
bind "$XB" ',"name":"srv-CI-gone"'
curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$XB\"$(jt "$XB"),\"score\":7,\"level\":1,\"diff\":1,\"name\":\"srv-CI-gone\"}" "$BASE/api/scores.php" > /dev/null
R=$(curl -s "$BASE/api/scores.php")
expect "the score is on the list before" 'srv-CI-gone' "$R"
R=$(acct "{\"id\":\"$XB\",\"tok\":\"$OLD\",\"action\":\"delete\"}")
expect "delete with a wrong token is refused" '"error":"bad token"' "$R"
R=$(acct "{\"id\":\"$XB\"$(jt "$XB"),\"action\":\"delete\"}")
expect "the owner's delete answers ok" '"ok":true' "$R"
R=$(curl -s "$BASE/api/scores.php")
refute "the scores under the id are gone" 'srv-CI-gone' "$R"
if [ "$ADMIN" -eq 1 ]; then
    R=$(curl -s -o /dev/null -w '%{http_code}' -b "$COOKIES" "$BASE/admin/api.php?action=client&id=$XB")
    expect "the admin knows nothing of the id any more" '404' "$R"
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=log")
    expect "the delete is on record" "FOK account: id $XB srv-CI-gone deleted by its owner from" "$R"
fi
unset "TOK[$XB]"
# The moved id goes the same way, so the admin part's count still holds.
R=$(acct "{\"id\":\"$XA\"$(jt "$XA"),\"action\":\"delete\"}")
expect "and the moved id is deleted by its new device" '"ok":true' "$R"
unset "TOK[$XA]"
