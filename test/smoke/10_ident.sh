# The identity token (API 4.20): the id is public, `tok` proves it. What
# only real HTTP can show is the wire - the member on a body, the
# parameter on a GET, the mint riding a hello answer, the one refusal - and
# the paths a client from before the token still walks. The decisions
# themselves (who binds, who confirms, who is counted) are unit-tested
# against Ident directly, where the cutoff can be moved.
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

# TEMPORARY(ident): a client from before the token sends no member at all,
# and until the cutoff an id nothing binds lets it through everywhere.
R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$IA\"}" "$BASE/api/hello.php")
expect "a hello without the member passes an unbound id" '"ok":true' "$R"
refute "and binds nothing: no token is answered" '"tok":"' "$R"
R=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/poll.php?id=$IA")
expect "a poll without the parameter passes an unbound id" '204' "$R"
R=$(sig "$IA" "$IB" ice 'legacy')
expect "and so does a signal" '"ok":true' "$R"

# The bind: the first hello carrying the member - null, the client has none
# - is answered the token, once.
bind "$IA"
expect "the first hello carrying tok:null binds and answers the token" '"tok":"' "$R"
R=$(hello "$IA")
expect "a hello with the token passes" '"ok":true' "$R"
refute "and answers no token again" '"tok":"' "$R"
R=$(hellocode "$IA" null)
expect "a second device asking to bind the same id is 401" '401' "$R"
R=$(hellocode "$IA" "\"$WRONG\"")
expect "a wrong token on a hello is 401" '401' "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$IA\"}" "$BASE/api/hello.php")
expect "a hello without the member is 401 once the id is bound" '"error":"bad token"' "$R"
R=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/poll.php?id=$IA")
expect "a poll without the parameter is 401 on a bound id" '401' "$R"
R=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/poll.php?id=$IA&tok=$WRONG")
expect "a poll with a wrong token is 401" '401' "$R"
R=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/poll.php?id=$IA$(qt "$IA")")
expect "a poll with the token holds as usual" '204' "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$IA\",\"tok\":\"$WRONG\",\"to\":\"$IB\",\"type\":\"ice\",\"payload\":\"x\"}" "$BASE/api/signal.php")
expect "a signal with a wrong token is refused" '"error":"bad token"' "$R"
R=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$IA\",\"tok\":\"$WRONG\",\"action\":\"list\"}" "$BASE/api/items.php")
expect "and so is an item list" '401' "$R"
R=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$IA\",\"tok\":\"$WRONG\",\"action\":\"list\"}" "$BASE/api/friend.php")
expect "and a friend list" '401' "$R"
R=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$IA\",\"tok\":\"$WRONG\",\"action\":\"seek\"}" "$BASE/api/match.php")
expect "and a quick-match seek" '401' "$R"
R=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$IA\",\"tok\":\"$WRONG\",\"action\":\"state\"}" "$BASE/api/tournament.php")
expect "and a tournament read" '401' "$R"
R=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$IA\",\"tok\":\"$WRONG\",\"action\":\"state\",\"eid\":\"AAAA\"}" "$BASE/api/event.php")
expect "and an event read" '401' "$R"
R=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$IA\",\"tok\":\"$WRONG\",\"name\":\"SRV-CI-X\",\"score\":1,\"level\":1,\"diff\":1}" "$BASE/api/scores.php")
expect "and a score submit" '401' "$R"
R=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$IA\",\"tok\":\"$WRONG\",\"peer\":\"$IB\",\"epoch\":0,\"reason\":\"first\",\"pts\":1}" "$BASE/api/start.php")
expect "and a start" '401' "$R"
R=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/relay.php?id=$IA&peer=$IB&tok=$WRONG")
expect "and the relay" '401' "$R"
# The wrong token is put on record per (id, address) pair: the line lands
# as the pair crosses the cap, which the tries above did at the default.
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
expect "and drains the candidate the legacy signal above queued" '"payload":"legacy"' "$R"
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
