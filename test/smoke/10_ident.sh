# The identity token: the id is public, `tok` proves it. What only real
# HTTP can show is the wire - the member on a body, the mint riding a
# hello answer, the refusals and the pair throttle's 429. The decisions
# themselves (who binds, who is counted) are unit-tested against Ident
# directly.
#
# Its own two ids, bound and unbound here and touched by no other part:
# binding is the one thing the rest of the suite does exactly once per id,
# and this part has to do it wrong on purpose. Both are removed again at
# the end, so the admin part's exact registered count still holds.
if [ "$REMOTE" -eq 1 ]; then
    IA=$(od -An -N4 -tx1 /dev/urandom | tr -d ' \n')
    IB=$(od -An -N4 -tx1 /dev/urandom | tr -d ' \n')
else
    IA=1de40001
    IB=1de40002
fi
WRONG=00000000000000000000000000000000
hellotok() { # hellotok <id> <tok-json> : a hello carrying the member as given
    curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$1\",\"tok\":$2}" "$BASE/api/hello.php"
}
hellocode() { # like hellotok, but prints the HTTP status
    curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$1\",\"tok\":$2}" "$BASE/api/hello.php"
}
pollb() { # pollb <json-body> : prints the HTTP status
    curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
        -d "$1" "$BASE/api/poll.php"
}

# An id nothing binds proves nothing: everything but a binding hello is
# refused, and a hello without the member binds nothing.
R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$IA\"}" "$BASE/api/hello.php")
expect "a hello without the member is 401 on an unbound id" '"error":"bad token"' "$R"
refute "and binds nothing" '"tok":"' "$R"
R=$(pollb "{\"id\":\"$IA\"}")
expect "and so is a poll" '401' "$R"
R=$(sig "$IA" "$IB" ice 'unbound')
expect "and a signal" '"error":"bad token"' "$R"

# The bind: the first hello carrying the member - null, the client has none
# - is answered the token, once.
bind "$IA"
expect "the first hello carrying tok:null binds and answers the token" '"tok":"' "$R"
R=$(hello "$IA")
expect "a hello with the token passes" '"ok":true' "$R"
refute "and answers no token again" '"tok":"' "$R"
R=$(hellocode "$IA" null)
expect "a second device asking to bind the same id is 401" '401' "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$IA\"}" "$BASE/api/hello.php")
expect "a hello without the member is 401 once the id is bound" '"error":"bad token"' "$R"

# The poll is a POST, the token a member of its body: never on a request
# line, which the web server's access log records.
R=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/poll.php?id=$IA$(qt "$IA")")
expect "a GET poll is refused" '405' "$R"
R=$(pollb "{\"id\":\"$IA\"$(jt "$IA")}")
expect "the poll with the token holds as usual" '204' "$R"
R=$(pollb "{\"id\":\"$IA\"}")
expect "and one without is 401" '401' "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$IA\"$(jt "$IA"),\"fl\":true,\"aa\":1,\"wait\":5}" "$BASE/api/poll.php")
expect "a body carries the flags as booleans or numbers" '"friends":[' "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$IA\"$(jt "$IA"),\"aa\":\"1\"}" "$BASE/api/poll.php")
expect "and refuses a flag given as a string" '"error":"invalid aa"' "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$IA\"$(jt "$IA"),\"wait\":\"5\"}" "$BASE/api/poll.php")
expect "and a wait that is not a number" '"error":"invalid wait"' "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$IA\"$(jt "$IA"),\"fs\":-1}" "$BASE/api/poll.php")
expect "as is a negative cursor" '"error":"invalid fs"' "$R"

# A wrong token is refused on every endpoint that names an id. These are
# the first ten wrong tokens of the pair (this id, this address), which is
# the default ident_fails_per_min: each one is a 401.
R=$(hellocode "$IA" "\"$WRONG\"")
expect "a wrong token on a hello is 401" '401' "$R"
R=$(pollb "{\"id\":\"$IA\",\"tok\":\"$WRONG\"}")
expect "and on a poll" '401' "$R"
R=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$IA\",\"tok\":\"$WRONG\",\"restore\":true}" "$BASE/api/backup.php")
expect "and on a backup read" '401' "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$IA\",\"tok\":\"$WRONG\",\"to\":\"$IB\",\"type\":\"ice\",\"payload\":\"x\"}" "$BASE/api/signal.php")
expect "and on a signal" '"error":"bad token"' "$R"
R=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$IA\",\"tok\":\"$WRONG\",\"action\":\"list\"}" "$BASE/api/items.php")
expect "and on an item list" '401' "$R"
R=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$IA\",\"tok\":\"$WRONG\",\"action\":\"list\"}" "$BASE/api/friend.php")
expect "and on a friend list" '401' "$R"
R=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$IA\",\"tok\":\"$WRONG\",\"action\":\"seek\"}" "$BASE/api/match.php")
expect "and on a quick-match seek" '401' "$R"
R=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$IA\",\"tok\":\"$WRONG\",\"action\":\"state\"}" "$BASE/api/tournament.php")
expect "and on a tournament read" '401' "$R"
R=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$IA\",\"tok\":\"$WRONG\",\"action\":\"state\",\"eid\":\"AAAA\"}" "$BASE/api/event.php")
expect "and on an event read" '401' "$R"
R=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$IA\",\"tok\":\"$WRONG\",\"name\":\"SRV-CI-X\",\"score\":1,\"level\":1,\"diff\":1}" "$BASE/api/scores.php")
expect "and on a score submit" '401' "$R"

# Past the cap the pair is answered 429 with retry_after - and the right
# token still passes from the same address.
R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$IA\",\"tok\":\"$WRONG\",\"peer\":\"$IB\",\"epoch\":0,\"reason\":\"first\",\"pts\":1}" "$BASE/api/start.php")
expect "the eleventh wrong token is too many attempts" '"error":"too many attempts"' "$R"
expect "with retry_after" '"retry_after":60' "$R"
R=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$IA\",\"tok\":\"$WRONG\"}" "$BASE/api/turn.php")
expect "as an HTTP 429" '429' "$R"
R=$(pollb "{\"id\":\"$IA\"}")
expect "a missing token is still a plain 401" '401' "$R"
R=$(hello "$IA")
expect "and the right token passes from the same address" '"ok":true' "$R"
if [ "$ADMIN" -eq 1 ]; then
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=log")
    expect "wrong tokens are on record" "FOK warning ident: wrong token for $IA from" "$R"
fi

# A hello CARRYING a token on an id nothing binds mints too, and the client
# replaces its copy: a restored file whose id was reset, or was never
# bound, comes in with whatever it holds.
R=$(hellotok "$IB" "\"$WRONG\"")
expect "a hello carrying a token binds an unbound id" '"tok":"' "$R"
refute "with a token of the server's own" "\"tok\":\"$WRONG\"" "$R"
TOK[$IB]=$(echo "$R" | grep -oE '"tok":"[a-f0-9]{32}"' | cut -d'"' -f4 || true)
R=$(hellocode "$IB" "\"$WRONG\"")
expect "and the one it carried is refused from then on" '401' "$R"
R=$(hello "$IB")
expect "while the answered one proves the id" '"ok":true' "$R"

# The two ids go again, binding and all (see delete_player).
if [ "$ADMIN" -eq 1 ]; then
    for pid in "$IA" "$IB"; do
        curl -s -b "$COOKIES" -X POST -d "id=$pid" "$BASE/admin/api.php?action=delete_player" > /dev/null
    done
    R=$(hellocode "$IA" null)
    expect "a removed player's id binds afresh" '200' "$R"
    curl -s -b "$COOKIES" -X POST -d "id=$IA" "$BASE/admin/api.php?action=delete_player" > /dev/null
    unset "TOK[$IA]" "TOK[$IB]"
fi
