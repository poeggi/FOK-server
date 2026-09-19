# What a store build needs (API 4.23): the client's version on hello and
# the upgrade word, account.php's delete, and moderation - block, report,
# the name mask. The decisions are unit-tested against Clients, Friends,
# Words and Account; this is the wire: the members, the answers, the
# refusals, the gates, and that a delete really removes the id from the
# count the admin part asserts.
#
# Its own two ids, touched by no other part: both are deleted here on
# purpose, by their owners.
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
fr() { # fr <id> <action> <peer> [,"member":value] : friend.php, prints the body
    curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$1\"$(jt "$1"),\"action\":\"$2\",\"peer\":\"$3\"${4:-}}" "$BASE/api/friend.php"
}
hellofl() { # hellofl <id> : a hello asking for the roster
    curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$1\"$(jt "$1"),\"friends_list\":true}" "$BASE/api/hello.php"
}

# The version on hello: recorded, and answered nothing while no floor is set.
bind "$XA" ',"name":"srv-CI-acct","client":"4.5.12","platform":"ios"'
refute "a hello naming a version is told nothing while the floors are off" '"upgrade"' "$R"
refute "and a name the empty word list leaves alone is not answered back" '"name"' "$R"
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

# Block: the friendship ends, the pair is held apart, the list rides the
# roster, unblock lifts it.
bind "$XB" ',"name":"srv-CI-gone"'
R=$(fr "$XA" request "$XB")
expect "a friend request goes out" '"state":"pending"' "$R"
R=$(fr "$XB" accept "$XA")
expect "and is accepted" '"state":"accepted"' "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$XA\",\"action\":\"block\",\"peer\":\"$XB\"}" "$BASE/api/friend.php")
expect "block without the token is refused" '"error":"bad token"' "$R"
R=$(fr "$XA" block "$XB")
expect "block answers ok" '"ok":true' "$R"
R=$(hellofl "$XA")
expect "the blocker's roster lists the blocked id" "\"blocked\":[\"$XB\"]" "$R"
refute "and no longer the friendship" "\"state\":\"accepted\"" "$R"
R=$(hello "$XB")
expect "the blocked side is told the friendship expired" 'expired' "$R"
R=$(fr "$XB" request "$XA")
expect "a request from the blocked side is answered as if sent" '"state":"pending"' "$R"
R=$(hellofl "$XA")
refute "and never arrives" "\"id\":\"$XB\",\"state\":\"pending\"" "$R"
R=$(sig "$XB" "$XA" ice 'blocked')
expect "a signal from the blocked side is accepted" '"ok":true' "$R"
R=$(hello "$XA")
refute "and dropped" 'blocked' "$R"
R=$(sigcode "$XA" "$XB" ice 'blocked')
expect "and so is one from the blocker" '200' "$R"
R=$(hello "$XB")
refute "in the other direction too" '"payload":"blocked"' "$R"
R=$(fr "$XA" unblock "$XB")
expect "unblock answers ok" '"ok":true' "$R"
R=$(hellofl "$XA")
expect "and the list is empty again" '"blocked":[]' "$R"
R=$(fr "$XA" remove "$XB")

# Report: recorded for the operator, never answered to a client. It runs
# through the friend-request throttle (one per second), hence the pauses.
R=$(fr "$XB" report "$XA" ',"reason":"spam"')
expect "an unknown reason is refused" '"error":"invalid reason"' "$R"
sleep 1.1
R=$(fr "$XB" report "$XA" ',"reason":"name"')
expect "a report answers ok" '"ok":true' "$R"
sleep 1.1
R=$(fr "$XB" report "$XA" ',"reason":"abuse"')
expect "and so does a second one" '"ok":true' "$R"
if [ "$ADMIN" -eq 1 ]; then
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=reports")
    expect "the operator sees the report" "\"reporter\":\"$XB\",\"target\":\"$XA\",\"name\":\"srv-CI-acct\",\"reason\":\"abuse\"" "$R"
    expect "with the names" '"srv-CI-gone"' "$R"
    N=$(echo "$R" | grep -o "\"target\":\"$XA\"" | wc -l | tr -d ' ')
    expect "as one row, the second folded in" '1' "$N"
    RID=$(echo "$R" | grep -oE '"rid":[0-9]+' | head -1 | cut -d: -f2)
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=alerts")
    expect "and was alerted" "\"type\":\"report\",\"message\":\"$XB " "$R"
    expect "naming the target and the reason" "reported $XA \\\"srv-CI-acct\\\" for name" "$R"
    R=$(curl -s -b "$COOKIES" -X POST -d "rid=$RID" "$BASE/admin/api.php?action=report_dismiss")
    expect "dismiss answers ok" '"ok":true' "$R"
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=reports")
    refute "and the row is gone" "\"target\":\"$XA\"" "$R"
fi

# The owner's delete: the id and what hangs off it, gone.
curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$XB\"$(jt "$XB"),\"score\":7,\"level\":1,\"diff\":1,\"name\":\"srv-CI-gone\"}" "$BASE/api/scores.php" > /dev/null
R=$(curl -s "$BASE/api/scores.php")
expect "the score is on the list before" 'srv-CI-gone' "$R"
R=$(acct "{\"id\":\"$XB\",\"tok\":\"00000000000000000000000000000000\",\"action\":\"delete\"}")
expect "delete with a wrong token is refused" '"error":"bad token"' "$R"
R=$(acct "{\"id\":\"$XB\"$(jt "$XB"),\"action\":\"transfer\"}")
expect "there is no other action" '"error":"invalid action"' "$R"
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
R=$(acct "{\"id\":\"$XA\"$(jt "$XA"),\"action\":\"delete\"}")
expect "and the other id is deleted by its owner too" '"ok":true' "$R"
unset "TOK[$XA]"
