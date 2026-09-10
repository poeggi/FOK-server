# Tournament mode (API 4.3): the server runs the tournament, the players run
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
expect "and at level 1, which is where the ladder starts" '"lvl":1' "$R"
expect "the group stage being what round 1 is called" '"stage":"group"' "$R"
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

# --- The break between rounds. A finished round does not roll straight into
# the next one: the server stops on a scoreboard everybody gets to read and
# waits for the host to press on. This is asserted FIRST, one hop after the
# round ended, because the refusal below is a deadline in milliseconds.
R=$(act "$ID1" continue "$T1")
expect "a continue that beats the minimum wait is refused" '"error":"too early"' "$R"
expect "and is told how long is left" '"retry_ms":' "$R"
R=$(act "$ID2" continue "$T1")
expect "only the host may press on" '"error":"host only"' "$R"

R=$(act "$ID1" state "$T1")
expect "round 1 is over and the knockout is drawn" '"nid":"final"' "$R"
expect "the standings are published with it" '"rank":1' "$R"
expect "the winner of the only match leads them" "\"id\":\"$ID2\",\"pts\":1" "$R"
expect "but nothing is dealt while the board is up" '"cursor":null' "$R"
expect "the board naming the stage about to start" '"stage":"final"' "$R"
expect "who is through to it" '"advancers":[' "$R"
expect "and how long it must stay up before continue" '"wait":' "$R"
expect "and the tournament is on its second round" '"round":2' "$R"

sleep 1.1
R=$(act "$ID1" continue "$T1")
expect "the host presses on once the board has been up long enough" '"ok":true' "$R"
R=$(act "$ID1" continue "$T1")
expect "and pressing again is a no-op, not an error" '"ok":true' "$R"
R=$(hellot "$ID2")
expect "every participant was sent the board" 'advancers' "$R"

R=$(act "$ID1" state "$T1")
expect "the cursor has moved to the final" '"cursor":"final"' "$R"
expect "which is a normal 3-heart duel" '"hm":3' "$R"
expect "played one level deeper than round 1" '"lvl":2' "$R"
expect "and no board is up any more" '"break":null' "$R"

# A held result settles on a participant's plain poll once the grace has
# passed: nothing runs on a timer, and the mailbox drain a client makes
# anyway is what carries the deadlines. What the settle produces - here the
# result AND the podium, since this is the final - rides in that same
# answer. The grace is a setting, so the deadline is crossed by shortening
# it rather than by waiting; the lone loss that settles on the spot is
# walked over the wire in test/live-protocol.sh.
R=$(result "$ID2" "$T1" final win 6 4)
expect "a lone win on the final is held, waiting for the other side" '"state":"held"' "$R"
curl -s "$BASE/api/poll.php?id=$ID1" > /dev/null
R=$(act "$ID1" state "$T1")
expect "a participant's poll inside the grace changes nothing" '"state":"held"' "$R"
if [ "$ADMIN" -eq 1 ]; then
    setting tournament_result_ms 0
    R=$(curl -s "$BASE/api/poll.php?id=$ID1")
    expect "past the grace, a plain poll from a participant settles it" 'event\":\"result' "$R"
    expect "and carries what the settle produced, the podium included" 'podium' "$R"
    setting tournament_result_ms 15000
    CLOSED=settled
else
    echo "skip the poll-settle check: shortening the grace needs admin"
    R=$(result "$ID1" "$T1" final loss 4 6)
    expect "the loser's report completes the pair" '"state":"confirmed"' "$R"
    CLOSED=confirmed
fi
R=$(result "$ID1" "$T1" final loss 4 6)
expect "a report on a closed node is answered with its state" "\"state\":\"$CLOSED\"" "$R"
# The winner now claims the opposite, far too late. Applied, it would freeze
# a node nobody was disputing.
R=$(result "$ID2" "$T1" final loss 6 4)
expect "a contradicting late report cannot reopen a closed node" "\"state\":\"$CLOSED\"" "$R"

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

# --- Replacing the one you host (4.8), and the operator's view of one.
# The client offers "end that one and start a new one" where the plain create
# is answered 409, so the server does both halves in one call: a client that
# left and then created could lose the second half and hold neither. Kept to
# ID1/ID2 like the rest of this file - the admin section that follows asserts
# an exact registered count, and tournament.php registers whoever calls it.
if [ "$ADMIN" -eq 1 ]; then
    setting tournament_create_cooldown 0
    R=$(tourney "{\"id\":\"$ID1\",\"action\":\"create\"}")
    expect "the host opens one more lobby" '"tid":' "$R"
    T3=$(tfield "$R" tid)
    R=$(tcode "{\"id\":\"$ID1\",\"action\":\"create\"}")
    expect "and a plain second create is refused" '409' "$R"
    # The cooldown is charged before anything is ended, so a replace inside
    # it is answered 429 with the tournament it would have replaced still
    # there. The create above is what it charges against: the stamp is
    # written whatever the cooldown is set to (see markCreate), so raising
    # it here has that create's own moment to measure from.
    setting tournament_create_cooldown 10
    R=$(tcode "{\"id\":\"$ID1\",\"action\":\"create\",\"replace\":true}")
    expect "a replace inside the create cooldown is refused too" '429' "$R"
    R=$(act "$ID1" state "$T3")
    expect "and the one it would have replaced is untouched" '"state":"open"' "$R"
    setting tournament_create_cooldown 0
    R=$(tourney "{\"id\":\"$ID1\",\"action\":\"create\",\"replace\":true}")
    expect "replace opens a new lobby over the one held" '"tid":' "$R"
    T4=$(tfield "$R" tid)
    if [ "$T4" = "$T3" ]; then echo "FAIL replace returned the same tid"; fail=1; fi
    R=$(act "$ID2" join "$T3")
    expect "and the one it replaced is gone" '"error":"no such tournament"' "$R"
    setting tournament_create_cooldown 10

    # The popup the Matches card opens: who is seated, what it is waiting on,
    # and the button that ends one nobody is asking about any more.
    R=$(act "$ID2" join "$T4")
    expect "a guest joins the lobby the operator will look at" '"ok":true' "$R"
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=duels")
    expect "the admin card lists the tournament by its id" "\"tid\":\"$T4\"" "$R"
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=tourney&tid=$T4")
    expect "an operator reads one tournament in full" '"ok":true' "$R"
    expect "the read names its seats" '"players":[{' "$R"
    expect "and says whether each is playing" '"playing":false' "$R"
    expect "an open lobby is waiting on nothing" '"wait":null' "$R"
    if [[ "$R" != *secret* ]]; then
        echo "ok   and the read carries no match secret"
    else
        echo "FAIL a match secret leaked into the admin read: $R"; fail=1
    fi
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=tourney&tid=nothex")
    expect "a malformed tid is refused" '"error":"invalid tid"' "$R"
    R=$(curl -s -o /dev/null -w '%{http_code}' -b "$COOKIES" \
        "$BASE/admin/api.php?action=tourney&tid=$NOTID")
    expect "a tid the store never held is a 404" '404' "$R"
    R=$(curl -s -o /dev/null -w '%{http_code}' -b "$COOKIES" \
        "$BASE/admin/api.php?action=tourney_abort&tid=$T4")
    expect "ending one via GET rejected" '405' "$R"
    R=$(curl -s -b "$COOKIES" -X POST -d "tid=$T4" "$BASE/admin/api.php?action=tourney_abort")
    expect "the operator ends the tournament" '"ok":true' "$R"
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=tourney&tid=$T4")
    expect "which stops it where it stood" '"state":"abandoned"' "$R"
    R=$(act "$ID2" join "$T4")
    expect "and nobody can join it again" '"error":"no such tournament"' "$R"
    R=$(curl -s -b "$COOKIES" -X POST -d "tid=$T4" "$BASE/admin/api.php?action=tourney_abort")
    expect "ending it twice is a no-op, not an error" '"ok":true' "$R"
else
    echo "skip the replace and operator checks: both need admin"
fi

# --- The start level (4.9). The host picks the level round 1 is played at;
# the ladder still climbs one per round from there, and a level the game does
# not have is clamped rather than refused.
if [ "$ADMIN" -eq 1 ]; then
    setting tournament_create_cooldown 0
    R=$(tourney "{\"id\":\"$ID1\",\"action\":\"create\",\"lvl\":4}")
    expect "a create names the level its first round is played at" '"lvl":4' "$R"
    TL=$(tfield "$R" tid)
    R=$(act "$ID2" join "$TL")
    expect "a guest joins the lobby that starts deeper" '"ok":true' "$R"
    R=$(act "$ID1" start "$TL")
    expect "the host starts it" '"ok":true' "$R"
    R=$(act "$ID1" state "$TL")
    expect "and the first match is dealt at the chosen level" '"lvl":4' "$R"
    R=$(act "$ID1" leave "$TL")
    setting tournament_max_level 6
    R=$(tourney "{\"id\":\"$ID1\",\"action\":\"create\",\"lvl\":99}")
    expect "a level past the last one the game has is clamped" '"lvl":6' "$R"
    TL2=$(tfield "$R" tid)
    R=$(act "$ID1" leave "$TL2")
    setting tournament_max_level 10
    setting tournament_create_cooldown 10
fi

# --- A speed tournament (4.10). The flag is the host's and the server only
# carries it: onto the lobby, which is where a player decides whether to join
# one, and onto every roles sheet the tournament deals.
if [ "$ADMIN" -eq 1 ]; then
    setting tournament_create_cooldown 0
    R=$(tourney "{\"id\":\"$ID1\",\"action\":\"create\",\"speed\":true}")
    expect "a create can declare every round a speed round" '"speed":true' "$R"
    TS=$(tfield "$R" tid)
    R=$(act "$ID2" join "$TS")
    expect "a guest reads it off the lobby before joining" '"speed":true' "$R"
    R=$(act "$ID1" start "$TS")
    expect "the host starts it" '"ok":true' "$R"
    R=$(act "$ID1" state "$TS")
    expect "and every match it deals is one" '"speed":true,"stakes":false' "$R"
    R=$(act "$ID1" leave "$TS")
    R=$(tourney "{\"id\":\"$ID1\",\"action\":\"create\"}")
    expect "a create that says nothing plays the ordinary mix" '"speed":false' "$R"
    TS2=$(tfield "$R" tid)
    R=$(act "$ID1" leave "$TS2")
    setting tournament_create_cooldown 10
fi

# --- The sweep for a tournament nobody is at. Its logic (who counts as gone,
# which seat keeps it alive) is unit-tested against the presence entries; what
# only real HTTP can show is the two things asserted here: that an ordinary
# client request carries the sweep at all, and that an admin one does NOT -
# reading the dashboard must never be what ends a tournament.
if [ "$ADMIN" -eq 1 ]; then
    setting tournament_create_cooldown 0
    R=$(tourney "{\"id\":\"$ID1\",\"action\":\"create\"}")
    T5=$(tfield "$R" tid)
    expect "one more lobby, to be swept" '"tid":' "$R"
    setting tournament_sweep_secs 0
    R=$(hellot "$ID2")
    expect "a client request with nobody idle sweeps nothing" '"ok":true' "$R"
    R=$(act "$ID1" state "$T5")
    expect "and the lobby is still open" '"state":"open"' "$R"
    # Everyone counts as gone from here on, so only the next request decides.
    setting tournament_idle_ttl 0
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=duels")
    expect "the dashboard still lists it" "\"tid\":\"$T5\"" "$R"
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=tourney&tid=$T5")
    expect "so reading the card is not what ends one" '"state":"open"' "$R"
    R=$(hellot "$ID2")
    expect "a client request is" '"ok":true' "$R"
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=tourney&tid=$T5")
    expect "and the tournament nobody was at is ended" '"state":"abandoned"' "$R"
    setting tournament_idle_ttl 180
    setting tournament_sweep_secs 30
    setting tournament_create_cooldown 10
fi

# The gate: at most one sweep every tournament_sweep_secs across the server,
# so a busy minute cannot turn this into per-request work. Held first by an
# ordinary request, then proven to hold by a lobby that survives a sweep it
# would otherwise not have.
if [ "$ADMIN" -eq 1 ]; then
    setting tournament_sweep_secs 300
    R=$(hellot "$ID2")
    expect "a client request takes the sweep gate" '"ok":true' "$R"
    setting tournament_create_cooldown 0
    R=$(tourney "{\"id\":\"$ID1\",\"action\":\"create\"}")
    T6=$(tfield "$R" tid)
    expect "a lobby opened behind the held gate" '"tid":' "$R"
    setting tournament_idle_ttl 0
    R=$(hellot "$ID2")
    expect "a second client request inside the gate" '"ok":true' "$R"
    R=$(act "$ID1" state "$T6")
    expect "sweeps nothing, however idle everyone is" '"state":"open"' "$R"
    setting tournament_sweep_secs 0
    R=$(hellot "$ID2")
    expect "and the request past the gate" '"ok":true' "$R"
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=tourney&tid=$T6")
    expect "is the one that ends it" '"state":"abandoned"' "$R"
    setting tournament_idle_ttl 180
    setting tournament_sweep_secs 30
    setting tournament_create_cooldown 10
fi

# The match neither player can connect. Presence cannot see this one - both
# are online and asking - so it is told from the fact that NOBODY EVER
# PLAYED the node: no duel between the two seats since it was dealt. The
# first deadline re-deals it, which is a real second attempt at the link;
# the second voids it, because both players turned up and there is nobody
# to name as the winner. Crossed by shortening the setting, the way every
# other deadline here is, and the pair never calls start.php - which is
# exactly the condition being tested.
if [ "$ADMIN" -eq 1 ]; then
    setting tournament_create_cooldown 0
    R=$(tourney "{\"id\":\"$ID1\",\"action\":\"create\"}")
    T7=$(tfield "$R" tid)
    C7=$(tfield "$R" code)
    expect "a lobby for two players who will never connect" '"tid":' "$R"
    R=$(tourney "{\"id\":\"$ID2\",\"action\":\"join\",\"code\":\"$C7\"}")
    expect "the second player joins it" '"event":"lobby"' "$R"
    R=$(tourney "{\"id\":\"$ID1\",\"action\":\"start\",\"tid\":\"$T7\"}")
    expect "and the host starts it" '"ok":true' "$R"
    R=$(act "$ID1" state "$T7")
    expect "a node is dealt and in flight" '"cursor":"' "$R"
    R=$(curl -s "$BASE/api/poll.php?id=$ID2")
    expect "a poll inside the deadline changes nothing" '"ok":true' "$R"
    R=$(act "$ID1" state "$T7")
    expect "and the node is still open" '"state":"running"' "$R"
    setting tournament_deadlock_ms 0
    R=$(curl -s "$BASE/api/poll.php?id=$ID2")
    expect "past it, a participant's own poll re-deals the node" 'event\":\"roles' "$R"
    R=$(act "$ID1" state "$T7")
    expect "which is a second attempt, not a result" '"state":"running"' "$R"
    R=$(curl -s "$BASE/api/poll.php?id=$ID2")
    expect "and the attempt after that voids it" 'event\":\"result' "$R"
    R=$(act "$ID1" state "$T7")
    expect "with no winner named, both players having turned up" '"winner":null' "$R"
    expect "the node reading as one that was not played" '"state":"void"' "$R"
    expect "and saying WHICH void it is, both players having been there" '"why":"unplayed"' "$R"
    setting tournament_deadlock_ms 150000
    R=$(tourney "{\"id\":\"$ID1\",\"action\":\"leave\",\"tid\":\"$T7\"}")
    expect "the host clears it away" '"ok":true' "$R"
    setting tournament_create_cooldown 10
fi