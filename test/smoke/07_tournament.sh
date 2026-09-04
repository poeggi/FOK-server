# Tournament mode (API 4.1): the server runs the tournament, the players run
# the games. Nothing here goes near a match - a tournament match is an ordinary
# P2P duel between the two players the server names, and no match or spectator
# traffic ever passes through the server - so what this walks is the
# orchestration only: the lobby, the schedule, the roles sheet, the result
# ladder, the bracket and the events.
#
# Deliberately kept to ID1/ID2 for the same reason 05_items.sh is: the admin
# section that follows asserts an exact registered count, and tournament.php
# registers whoever calls it. Both tournaments below are walked to a TERMINAL
# state (done, abandoned) so a remote run leaves nothing in flight; the rows
# themselves outlive the player cleanup, which is harmless - a terminal
# tournament is never read again.
#
# The deep math (seating, the sparse first round, the tie-break ladder, the
# knockout fold) is unit-tested in test/unit.php against Bracket directly.
# What only real HTTP can show is what this file checks: the wire.

tourney() { # tourney <json-body> : POST to api/tournament.php, print the body
    curl -s -X POST -H 'Content-Type: application/json' -d "$1" "$BASE/api/tournament.php"
}
tcode() { # like tourney, but prints the HTTP status instead
    curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
        -d "$1" "$BASE/api/tournament.php"
}
# A no-match grep exits 1, which would abort the run under `set -e` instead of
# failing an assertion - so the extractor swallows it (see 05_items.sh).
tfield() { echo "$1" | grep -oE "\"$2\":\"[0-9A-Za-z]+\"" | head -1 | cut -d'"' -f4 || true; }
act() { # act <id> <action> <tid>
    tourney "{\"id\":\"$1\",\"action\":\"$2\",\"tid\":\"$3\"}"
}
result() { # result <id> <tid> <nid> <outcome> <mine> <theirs>
    tourney "{\"id\":\"$1\",\"action\":\"result\",\"tid\":\"$2\",\"nid\":\"$3\",\"outcome\":\"$4\",\"score\":[$5,$6]}"
}
hellot() { # hello asking for the lobbies announced on the caller's address
    curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$1\",\"tourneys\":true}" "$BASE/api/hello.php"
}
# A well-formed tid that names nothing.
NOTID=00000000000000000000000000000000

R=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/tournament.php")
expect "tournaments are POST only" '405' "$R"
R=$(tourney "{\"id\":\"nothex\",\"action\":\"state\"}")
expect "a malformed id is rejected" '"error":"invalid id"' "$R"
R=$(tourney "{\"id\":\"$ID1\",\"action\":\"nope\"}")
expect "an unknown action is rejected" '"error":"invalid action"' "$R"
R=$(tourney "{\"id\":\"$ID1\",\"action\":\"state\",\"tid\":\"nothex\"}")
expect "a malformed tid is rejected" '"error":"invalid tid"' "$R"
R=$(act "$ID1" state "$NOTID")
expect "a tid that names nothing is a 404" '"error":"no such tournament"' "$R"
R=$(tourney "{\"id\":\"$ID1\",\"action\":\"join\",\"code\":\"ZZZZZZ\"}")
expect "so is a join code nobody minted" '"error":"no such tournament"' "$R"

# --- The lobby. The code is the capability: it is read off the host's screen
# and typed back in, so it is the way in from anywhere the announcement does
# not reach.
R=$(tourney "{\"id\":\"$ID1\",\"action\":\"create\"}")
expect "a host opens a lobby" '"ok":true' "$R"
T1=$(tfield "$R" tid)
CODE=$(tfield "$R" code)
if [ "${#T1}" -eq 32 ] && [ "${#CODE}" -eq 6 ]; then
    echo "ok   the lobby has a 32-hex tid and a 6-character join code"
else
    echo "FAIL create returned no usable tid/code: $R"
    fail=1
fi
R=$(tcode "{\"id\":\"$ID1\",\"action\":\"create\"}")
expect "a host may hold one tournament at a time" '409' "$R"

R=$(act "$ID1" start "$T1")
expect "a lobby of one cannot start" '"error":"need 2"' "$R"
R=$(tourney "{\"id\":\"$ID2\",\"action\":\"join\",\"code\":\"$CODE\"}")
expect "the second player joins by code" "\"host\":\"$ID1\"" "$R"
expect "and the lobby names both players" "\"id\":\"$ID2\"" "$R"
R=$(act "$ID2" join "$T1")
expect "joining twice is a no-op, not an error" '"ok":true' "$R"

R=$(hellot "$ID2")
expect "an open lobby is announced to the host's own address" "\"tid\":\"$T1\"" "$R"
expect "with the seats it still has free" '"max":' "$R"

R=$(act "$ID2" start "$T1")
expect "only the host may start" '"error":"host only"' "$R"
R=$(act "$ID1" start "$T1")
expect "the host starts it" '"ok":true' "$R"
R=$(act "$ID1" join "$T1")
expect "and a late joiner is turned away" '"error":"already started"' "$R"

# --- The schedule. Two players is one round-1 match, and both of them advance
# into a one-node bracket, so this walks the whole shape in two matches.
R=$(act "$ID1" state "$T1")
expect "the tournament is running" '"state":"running"' "$R"
expect "on its first round" '"round":1' "$R"
expect "with the first match dealt" '"cursor":"r1.1"' "$R"
expect "a round-1 match is played at 2 hearts" '"hm":2' "$R"
expect "the bracket stays empty until round 1 is over" '"bracket":[]' "$R"
expect "the caller gets its own roles sheet" '"you":"play"' "$R"
expect "which places the match within its stage" '"match":1' "$R"
FEEDER=$(tfield "$R" feeder)
if [ "$FEEDER" = "$ID1" ] || [ "$FEEDER" = "$ID2" ]; then
    echo "ok   the feeder is one of the two players, never a spectator"
else
    echo "FAIL the roles sheet named no usable feeder: $R"
    fail=1
fi
R=$(hellot "$ID2")
expect "every participant is told the match is up" '"type":"tourney"' "$R"
expect "by a roles event" 'roles' "$R"

R=$(tourney "{\"id\":\"$ID1\",\"action\":\"result\",\"tid\":\"$T1\",\"nid\":\"r1.oops\",\"outcome\":\"win\",\"score\":[1,0]}")
expect "a malformed node id is rejected" '"error":"invalid nid"' "$R"
R=$(tourney "{\"id\":\"$ID1\",\"action\":\"result\",\"tid\":\"$T1\",\"nid\":\"r1.1\",\"outcome\":\"victory\",\"score\":[1,0]}")
expect "an outcome that is not win/loss/draw is rejected" '"error":"invalid outcome"' "$R"
R=$(tourney "{\"id\":\"$ID1\",\"action\":\"result\",\"tid\":\"$T1\",\"nid\":\"r1.1\",\"outcome\":\"win\",\"score\":[1]}")
expect "so is a score that is not a pair" '"error":"invalid score"' "$R"
R=$(result "$ID1" "$T1" r1.2 win 1 0)
expect "and a node the server never dealt" '"error":"no such node"' "$R"

# --- The result ladder. The server never watched the match: a result is what
# the two people who played it report, and its whole job is deciding when one
# report is enough and when two agree.
R=$(result "$ID2" "$T1" r1.1 win 12 9)
expect "a lone win is held, waiting for the other side" '"state":"held"' "$R"
R=$(result "$ID1" "$T1" r1.1 loss 9 12)
expect "the loser's report completes the pair" '"state":"confirmed"' "$R"

R=$(act "$ID1" state "$T1")
expect "round 1 is over and the knockout is drawn" '"nid":"final"' "$R"
expect "the standings are published with it" '"rank":1' "$R"
expect "the winner of the only match leads them" "\"id\":\"$ID2\",\"pts\":1" "$R"
expect "the cursor has moved to the final" '"cursor":"final"' "$R"
expect "which is a normal 3-heart duel" '"hm":3' "$R"
expect "and the tournament is on its second round" '"round":2' "$R"

# Nobody lies to lose, so a reported loss settles on the spot.
R=$(result "$ID1" "$T1" final loss 4 6)
expect "a reported loss settles at once" '"state":"settled"' "$R"
R=$(result "$ID1" "$T1" final loss 4 6)
expect "and re-sending it is idempotent" '"state":"settled"' "$R"
# The other side now claims the opposite, far too late. Applied, it would
# freeze a node nobody was disputing - and the loser's own admission is the
# one report that cannot be a lie in the reporter's favour.
R=$(result "$ID2" "$T1" final loss 6 4)
expect "a contradicting late report cannot reopen a settled node" '"state":"settled"' "$R"

R=$(act "$ID2" state "$T1")
expect "the final settles the tournament" '"state":"done"' "$R"
expect "and there is no match left in flight" '"cursor":null' "$R"
R=$(hellot "$ID2")
expect "the winner is told it is over" 'podium' "$R"

R=$(tcode "{\"id\":\"$ID1\",\"action\":\"create\"}")
expect "a host may not open lobbies back to back" '429' "$R"
R=$(tourney "{\"id\":\"$ID1\",\"action\":\"create\"}")
expect "and is told how long to wait" '"retry_after":' "$R"

# --- The lobby the host walks away from. The host owns the LOBBY and only the
# lobby: leaving one that never started ends it, where leaving a running
# tournament is a forfeit and the bracket carries on without them.
R=$(tourney "{\"id\":\"$ID2\",\"action\":\"create\",\"stakes\":true}")
expect "a second host opens a lobby for stakes" '"stakes":true' "$R"
T2=$(tfield "$R" tid)
R=$(act "$ID1" join "$T2")
expect "the other player joins it" '"ok":true' "$R"
R=$(act "$ID2" leave "$T2")
expect "the host leaves" '"ok":true' "$R"
R=$(act "$ID1" join "$T2")
expect "and the lobby is gone with them" '"error":"no such tournament"' "$R"
R=$(act "$ID1" leave "$T2")
expect "leaving an abandoned lobby is a harmless no-op" '"ok":true' "$R"
R=$(hellot "$ID1")
expect "an abandoned lobby is no longer announced" '"tourneys":[]' "$R"
