# Events (API 4.11): a room an operator opens, entered by scanning its QR.
#
# What only real HTTP can show is what this walks: the door in both settings,
# the two codes, the throttle, who may read what, the reserved signal, and an
# event tournament's membership gate. The state machine itself (every cell of
# mode x schedule x now) and the pass slot edges are unit-tested against
# Events directly, where a clock can be handed in.
#
# The whole file needs the admin login: an event can only be OPENED by an
# operator, which is the point - an event is a room somebody with a key to the
# building opens. Without creds there is nothing here to test.

if [ "$ADMIN" -eq 0 ]; then
    echo "skip events (no admin credentials)"
else

ev() { # ev <json-body> : POST to api/event.php, print the body
    curl -s -X POST -H 'Content-Type: application/json' -d "$1" "$BASE/api/event.php"
}
evcode() { # like ev, but prints the HTTP status instead
    curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
        -d "$1" "$BASE/api/event.php"
}
evadmin() { # evadmin <action> <post-data...>
    local a="$1"; shift
    local args=()
    for kv in "$@"; do args+=(--data-urlencode "$kv"); done
    curl -s -b "$COOKIES" -X POST "${args[@]}" "$BASE/admin/api.php?action=$a"
}
# A no-match grep exits 1 under set -e, so the extractors swallow it.
evfield() { echo "$1" | grep -oE "\"$2\":\"[0-9A-Za-z]+\"" | head -1 | cut -d'"' -f4 || true; }
# A scan posts what it scanned and nothing else - a printed key names its
# own event, a pass carries the eid in front of its own dot.
evjoin() { # evjoin <id> <code>
    ev "{\"id\":\"$1\",\"action\":\"join\",\"code\":\"$2\"}"
}
evpass() { # evpass <id> <eid> <pass>
    evjoin "$1" "$2.$3"
}
evact() { # evact <id> <action> <eid>
    ev "{\"id\":\"$1\",\"action\":\"$2\",\"eid\":\"$3\"}"
}

R=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/event.php")
expect "events are POST only" '405' "$R"
R=$(ev "{\"id\":\"nothex\",\"action\":\"state\",\"eid\":\"AAAA\"}")
expect "a malformed id is rejected" '"error":"invalid id"' "$R"
R=$(ev "{\"id\":\"$ID1\",\"action\":\"nonsense\",\"eid\":\"AAAA\"}")
expect "an unknown action is rejected" '"error":"invalid action"' "$R"
R=$(ev "{\"id\":\"$ID1\",\"action\":\"state\",\"eid\":\"aa\"}")
expect "a malformed eid is rejected" '"error":"invalid eid"' "$R"
# An eid grants NOTHING on its own: one that exists and one that does not are
# the same answer to somebody with no row, so nothing here enumerates.
R=$(ev "{\"id\":\"$ID1\",\"action\":\"state\",\"eid\":\"ZZZZ\"}")
expect "an eid nobody minted is not an event" '"error":"no such event"' "$R"

# ---- the operator opens one, with the door open ----

R=$(evadmin event_create "name=srv-CI-open" "descr=smoke" "organizer=$ID1" "closed=0" "mode=active")
EID1=$(evfield "$R" eid)
expect "an operator opens an event" '"ok":true' "$R"
expect "and it gets a four-character id" '"eid":"' "$R"
R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=events")
expect "the card lists it" "\"eid\":\"$EID1\"" "$R"
expect "with the state it was opened in" '"state":"active"' "$R"
expect "and its door" '"closed":false' "$R"
refute "the key is in no answer this dashboard gives" '"ekey"' "$R"
R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=event&eid=$EID1")
expect "one event reads back in full" '"roster":' "$R"
expect "with the counts beside it" '"counts":' "$R"
refute "and no key in it either" '"ekey"' "$R"
refute "and no secret" '"secret"' "$R"

# The KEY is on the print page and nowhere else. That page is the only way to
# read one, so this is also how the smoke learns it.
R=$(curl -s -b "$COOKIES" "$BASE/admin/event.php?eid=$EID1")
expect "the print page renders" '<svg' "$R"
expect "with the code under the QR" 'class="code pixel"' "$R"
expect "and says how to use it" 'Scan to join' "$R"
KEY1=$(echo "$R" | grep -oE "class=\"code pixel\">[0-9A-Z]{11}<" | grep -oE "[0-9A-Z]{11}" | head -1 || true)
expect "the key is 11 characters, the whole version 3 budget" '11' "${#KEY1}"
R=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/admin/event.php?eid=$EID1")
expect "and the page is login-gated" '401' "$R"

# ---- getting in ----

R=$(evjoin "$ID2" "$KEY1")
expect "a scan of the printed key joins an open event" '"you":{"state":"member"' "$R"
expect "and the answer carries the member count" '"members":' "$R"
R=$(evjoin "$ID2" "$KEY1")
expect "scanning again answers the same, so a lost response costs nothing" '"you":{"state":"member"' "$R"
R=$(evadmin event_roster "eid=$EID1" "id=$ID2" "set=member")
expect "and the roster still holds one row for them" '"ok":true' "$R"
R=$(evjoin "$ID3" "ZZZZZZZZZZZ")
expect "a wrong key is the same answer as no event at all" '"error":"no such event"' "$R"
R=$(evact "$ID3" state "$EID1")
expect "and leaves the scanner with no row" '"error":"no such event"' "$R"

# The wrong-code throttle. Lowered to two so this costs two requests
# rather than ten, then put back.
# One wrong code is already spent above, and the counter is per player
# for the whole minute - so the cap of two is one attempt away.
setting event_join_fails_per_min 2
R=$(evjoin "$ID3" "ZZZZZZZZZZZ")
expect "a wrong code still answers the same 404 up to the cap" '"error":"no such event"' "$R"
R=$(evjoin "$ID3" "ZZZZZZZZZZZ")
expect "past it the attempts are refused outright" '"error":"too many attempts"' "$R"
expect "and the client is told how long to wait" '"retry_after":' "$R"
R=$(evjoin "$ID3" "$KEY1")
expect "even a correct code, while the throttle stands" '"error":"too many attempts"' "$R"
R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=log")
expect "and the attempt is on record, which is what the throttle is for" 'event code throttle' "$R"
setting event_join_fails_per_min 10

# The live pass: minted from the clock, so no slot is ever stored, and ANY
# member may show one - that is what makes an event spread in a room.
R=$(evact "$ID2" pass "$EID1")
expect "a member is handed the next pass slots" '"slots":' "$R"
expect "with the rotation interval on the wire, not hard-coded" '"step":' "$R"
expect "and how long each code stays valid" '"valid":' "$R"
PASS=$(echo "$R" | grep -oE '"code":"[0-9A-Z]{6}"' | head -1 | cut -d'"' -f4 || true)
expect "the pass is six characters" '6' "${#PASS}"
R=$(evpass "$ID3" "$EID1" "$PASS")
expect "and somebody else scans their way in with it" '"you":{"state":"member"' "$R"
R=$(evact "$ID3" pass "$EID1")
expect "who may then pass it on themselves" '"slots":' "$R"

# What a member reads, and what a stranger does not.
R=$(evact "$ID2" state "$EID1")
expect "a member reads the event in full" '"members":' "$R"
expect "with its archive" '"archive":' "$R"
expect "and the live tournament slot" '"tourney":' "$R"
R=$(evact "$ID2" members "$EID1")
expect "and the roster" '"members":[' "$R"
expect "every id carrying its name" '"name":' "$R"
expect "with what a friend button reads" '"friend":' "$R"
refute "and no online state at all - presence stays friendship-gated" '"online"' "$R"

# ---- the events flag on hello and poll ----

R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID2\",\"events\":true}" "$BASE/api/hello.php")
expect "hello answers the caller's own events" "\"eid\":\"$EID1\"" "$R"
expect "saying where the caller stands in each" '"you":{"state":"member"' "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID2\",\"events\":\"yes\"}" "$BASE/api/hello.php")
expect "a bogus events flag is refused" '"error":"invalid events"' "$R"
R=$(curl -s "$BASE/api/poll.php?id=$ID2&ev=1")
expect "the poll answers them too" "\"eid\":\"$EID1\"" "$R"
R=$(curl -s "$BASE/api/poll.php?id=$ID2&ev=1&wait=2")
expect "and answers at once rather than holding for a signal" "\"eid\":\"$EID1\"" "$R"
R=$(curl -s "$BASE/api/poll.php?id=$ID2&ev=2")
expect "a bogus ev flag is refused" '"error":"invalid ev"' "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"to\":\"$ID2\",\"type\":\"event\",\"payload\":\"x\"}" "$BASE/api/signal.php")
expect "a client cannot forge an event signal" '"error":"invalid type"' "$R"

# ---- an event tournament is an ordinary tournament nobody outside can see ----

setting tournament_create_cooldown 0
R=$(tourney "{\"id\":\"$ID2\",\"action\":\"create\",\"eid\":\"$EID1\"}")
expect "only the organizer may open one on an event" '"error":"not the organizer"' "$R"
R=$(tourney "{\"id\":\"$ID1\",\"action\":\"create\",\"eid\":\"$EID1\"}")
ETID=$(evfield "$R" tid)
ECODE=$(evfield "$R" code)
expect "the organizer opens one" '"tid":' "$R"
expect "and it carries the event back" "\"eid\":\"$EID1\"" "$R"
R=$(evact "$ID2" state "$EID1")
expect "which the event names as its live tournament" "\"tid\":\"$ETID\"" "$R"
refute "carrying the real player cap, which is a setting and not a field" '"max":0' "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID2\"}" "$BASE/api/hello.php")
expect "and every member is told a lobby opened" '"event\":\"tourney' "$R"
expect "the signal naming the tournament" "\\\"tid\\\":\\\"$ETID\\\"" "$R"
expect "and carrying its join code" "\\\"code\\\":\\\"$ECODE\\\"" "$R"
R=$(tourney "{\"id\":\"$ID2\",\"action\":\"join\",\"tid\":\"$ETID\"}")
expect "a member joins it" '"event":"lobby"' "$R"
R=$(tourney "{\"id\":\"$ID1\",\"action\":\"leave\",\"tid\":\"$ETID\"}")
expect "the host closes it again" '"ok":true' "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID2\"}" "$BASE/api/hello.php")
expect "and the same member is told it is over" '"over\":true' "$R"
R=$(evact "$ID2" state "$EID1")
refute "the event naming no live tournament any more" '"tourney":{' "$R"

R=$(evadmin event_create "name=srv-CI-secret" "organizer=$ID1" "closed=0" "mode=active")
EID2=$(evfield "$R" eid)
R=$(tourney "{\"id\":\"$ID1\",\"action\":\"create\",\"eid\":\"$EID2\"}")
STID=$(evfield "$R" tid)
SCODE=$(evfield "$R" code)
expect "a second event opens a lobby of its own" '"tid":' "$R"
R=$(tourney "{\"id\":\"$ID2\",\"action\":\"join\",\"tid\":\"$STID\"}")
expect "somebody outside the event cannot join it by tid" '"error":"not in the event"' "$R"
R=$(tourney "{\"id\":\"$ID2\",\"action\":\"join\",\"code\":\"$SCODE\"}")
expect "nor by its code, which is the whole secrecy" '"error":"not in the event"' "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID2\",\"tourneys\":true}" "$BASE/api/hello.php")
refute "and the announce does not mention it to them" "$STID" "$(echo "$R" | grep -o '"tourneys":.*' || true)"
R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\",\"tourneys\":true}" "$BASE/api/hello.php")
expect "while a member is shown it whatever network they are on" "\"tid\":\"$STID\"" "$R"
R=$(tourney "{\"id\":\"$ID1\",\"action\":\"leave\",\"tid\":\"$STID\"}")
expect "that lobby closes too" '"ok":true' "$R"
R=$(evadmin event_delete "eid=$EID2")
expect "and the second event is deleted again" '"ok":true' "$R"

# ---- leaving, and the end ----

R=$(evact "$ID3" leave "$EID1")
expect "a member may leave" '"ok":true' "$R"
R=$(evact "$ID3" state "$EID1")
expect "and the row is gone" '"error":"no such event"' "$R"
R=$(evjoin "$ID3" "$KEY1")
expect "so they may scan their way back in" '"you":{"state":"member"' "$R"
R=$(evact "$ID1" leave "$EID1")
# The refusal names the REASON, which is that they are the organizer - not
# 'not the organizer', which is the opposite and is what a client shows.
expect "but the organizer cannot leave its own event" '"error":"the organizer"' "$R"

R=$(evact "$ID2" run "$EID1")
expect "a plain member cannot run the event" '"error":"not the organizer"' "$R"
R=$(evact "$ID1" pause "$EID1")
expect "the organizer pauses it" '"state":"paused"' "$R"
R=$(evjoin "$ID3" "$KEY1")
expect "and a paused event admits nobody" '"error":"paused"' "$R"
R=$(evact "$ID1" run "$EID1")
expect "running it again reopens the door" '"state":"active"' "$R"

# ---- the closed door ----

R=$(evadmin event_create "name=srv-CI-closed" "organizer=$ID1" "closed=1" "mode=active")
EID3=$(evfield "$R" eid)
R=$(curl -s -b "$COOKIES" "$BASE/admin/event.php?eid=$EID3")
KEY3=$(echo "$R" | grep -oE "class=\"code pixel\">[0-9A-Z]{11}<" | grep -oE "[0-9A-Z]{11}" | head -1 || true)
R=$(evjoin "$ID2" "$KEY3")
expect "a scan at a closed door lands pending" '"you":{"state":"pending"' "$R"
refute "and a pending row is told no count" '"members":' "$R"
refute "nor the achievement" '"ach"' "$R"
refute "nor the archive" '"archive"' "$R"
R=$(evact "$ID2" members "$EID3")
expect "and cannot read the roster" '"error":"not a member"' "$R"
R=$(evact "$ID2" pass "$EID3")
expect "nor mint a pass" '"error":"not a member"' "$R"
R=$(evjoin "$ID2" "$KEY3")
expect "scanning again answers pending again" '"you":{"state":"pending"' "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID1\"}" "$BASE/api/hello.php")
expect "the organizer's mailbox holds the request" '"type":"event"' "$R"
expect "which names the event" "$EID3" "$R"
expect "and who asked" "\\\"from\\\":\\\"$ID2\\\"" "$R"
R=$(ev "{\"id\":\"$ID3\",\"action\":\"roster\",\"eid\":\"$EID3\",\"peer\":\"$ID2\",\"set\":\"member\"}")
expect "a non-organizer cannot work the door" '"error":"not the organizer"' "$R"
R=$(ev "{\"id\":\"$ID1\",\"action\":\"roster\",\"eid\":\"$EID3\",\"peer\":\"$ID3\",\"set\":\"member\"}")
expect "and the organizer approves, never adds" '"error":"no such event"' "$R"
R=$(ev "{\"id\":\"$ID1\",\"action\":\"roster\",\"eid\":\"$EID3\",\"peer\":\"$ID2\",\"set\":\"member\"}")
expect "the organizer approves the request" '"ok":true' "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID2\"}" "$BASE/api/hello.php")
expect "and the requester is told" '"event\":\"accepted' "$R"
R=$(evact "$ID2" state "$EID3")
expect "who now reads the event in full" '"members":' "$R"

R=$(ev "{\"id\":\"$ID1\",\"action\":\"access\",\"eid\":\"$EID3\",\"closed\":false}")
expect "the organizer opens the door" '"closed":false' "$R"
R=$(evjoin "$ID3" "$KEY3")
expect "and the next scan goes straight in" '"you":{"state":"member"' "$R"
R=$(ev "{\"id\":\"$ID1\",\"action\":\"roster\",\"eid\":\"$EID3\",\"peer\":\"$ID3\",\"set\":\"banned\"}")
expect "a pest is banned" '"ok":true' "$R"
R=$(evjoin "$ID3" "$KEY3")
expect "and every later scan is refused" '"error":"banned"' "$R"
R=$(ev "{\"id\":\"$ID1\",\"action\":\"roster\",\"eid\":\"$EID3\",\"peer\":\"$ID3\",\"set\":\"none\"}")
expect "lifting the ban drops the row" '"ok":true' "$R"
R=$(evjoin "$ID3" "$KEY3")
expect "so they may scan again" '"you":{"state":"member"' "$R"

# The operator has one power the organizer has not: seating somebody who
# never scanned anything.
R=$(evadmin event_roster "eid=$EID3" "id=$ID1" "set=member")
expect "an operator seats a player with no row at all" '"ok":true' "$R"
R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=event&eid=$EID3")
expect "and the row records where it came from" '"via":"admin"' "$R"

# ---- ending one freezes it ----

R=$(evadmin event_end "eid=$EID3")
expect "the operator ends the event" '"state":"ended"' "$R"
R=$(evjoin "$ID2" "$KEY3")
expect "an ended event admits nobody" '"error":"ended"' "$R"
R=$(evact "$ID2" pass "$EID3")
expect "and mints no more passes" '"error":"ended"' "$R"
R=$(evact "$ID1" run "$EID3")
expect "and can never be run again" '"error":"ended"' "$R"
R=$(evact "$ID2" state "$EID3")
expect "though its members can still read it" '"state":"ended"' "$R"

# ---- the monitor: one screen per event, held two different ways ----

# Asking whether a screen is offered must not take the slot: the monitor
# call claims a free one, so it cannot be how a client finds out.
R=$(evact "$ID2" state "$EID1")
expect "state says whether the event offers a monitor" '"monitor_allowed":true' "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID2\",\"events\":true}" "$BASE/api/hello.php")
expect "and so does every row of the events list" '"monitor_allowed":' "$R"
R=$(evact "$ID3" monitor "$EID1")
expect "so the slot was still free for somebody else to take" '"reserved":false' "$R"
R=$(evact "$ID2" monitor "$EID1")
expect "and the one who asked is now the one turned away" '"error":"monitor taken"' "$R"
R=$(evact "$ID3" monitor "$EID1")
expect "a member running a FREE slot still reads as a member" '"you":{"state":"member"' "$R"
expect "and the screen is told how many have joined" '"members":' "$R"
expect "and how many are waiting" '"pending":' "$R"
expect "with the archive it shows between matches" '"archive":' "$R"
expect "and the tournament slot it fills while one runs" '"tourney":' "$R"
expect "the slot is not reserved on this event" '"reserved":false' "$R"
R=$(evact "$ID3" monitor "$EID1")
expect "while the holder renews by simply asking again" '"members":' "$R"
R=$(evadmin event_edit "eid=$EID1" "monitor_allowed=0")
expect "an operator can stop offering one" '"ok":true' "$R"
R=$(evact "$ID2" state "$EID1")
expect "which every answer then says" '"monitor_allowed":false' "$R"
R=$(evact "$ID2" monitor "$EID1")
expect "and then nobody gets a screen" '"error":"no monitor"' "$R"
R=$(evadmin event_edit "eid=$EID1" "monitor_allowed=1")
expect "offering it again is one edit" '"ok":true' "$R"

# A RESERVED screen holds the slot whether or not it is switched on, joins
# without approval, and is in no participant list.
R=$(evadmin event_create "name=srv-CI-tv" "organizer=$ID1" "closed=1" "mode=active" "monitor=$ID3")
EID4=$(evfield "$R" eid)
expect "an operator reserves the slot when setting the event up" '"ok":true' "$R"
R=$(curl -s -b "$COOKIES" "$BASE/admin/event.php?eid=$EID4")
KEY4=$(echo "$R" | grep -oE "class=\"code pixel\">[0-9A-Z]{11}<" | grep -oE "[0-9A-Z]{11}" | head -1 || true)
R=$(evjoin "$ID3" "$KEY4")
expect "the reserved screen joins a CLOSED event without waiting" '"you":{"state":"monitor"' "$R"
refute "and is granted no achievement for it" '"ach"' "$R"
R=$(evjoin "$ID2" "$KEY4")
expect "while an ordinary scan at that door still waits" '"you":{"state":"pending"' "$R"
R=$(ev "{\"id\":\"$ID1\",\"action\":\"roster\",\"eid\":\"$EID4\",\"peer\":\"$ID2\",\"set\":\"member\"}")
expect "the organizer lets that one in" '"ok":true' "$R"
R=$(evact "$ID2" members "$EID4")
expect "and the roster names them" "\"id\":\"$ID2\"" "$R"
refute "but never the screen" "\"id\":\"$ID3\"" "$R"
R=$(evact "$ID2" state "$EID4")
expect "the member count leaves the screen out too" '"members":2' "$R"

# A monitor may read the event and run itself. That is the whole list.
R=$(evact "$ID3" monitor "$EID4")
expect "the reserved screen runs" '"reserved":true' "$R"
R=$(evact "$ID3" state "$EID4")
expect "and may read the event" '"you":{"state":"monitor"' "$R"
R=$(evact "$ID3" members "$EID4")
expect "but not the roster" '"error":"monitor only"' "$R"
R=$(evact "$ID3" pass "$EID4")
expect "nor mint a pass" '"error":"monitor only"' "$R"
R=$(evact "$ID3" leave "$EID4")
expect "nor leave on its own" '"error":"monitor only"' "$R"
R=$(evact "$ID2" monitor "$EID4")
expect "and a member cannot take a reserved slot" '"error":"monitor taken"' "$R"

# A screen never takes a seat, so a tournament being watched still seats
# its full eight. It falls out of the row state: a monitor is not a
# member, and only members get into an event's tournament.
R=$(tourney "{\"id\":\"$ID1\",\"action\":\"create\",\"eid\":\"$EID4\"}")
MTID=$(evfield "$R" tid)
MCODE=$(evfield "$R" code)
expect "the organizer opens a tournament the screen will watch" '"tid":' "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID3\",\"tourneys\":true}" "$BASE/api/hello.php")
expect "the screen is served the lobby in the ordinary announce" "\"tid\":\"$MTID\"" "$R"
expect "and is signalled it, being in the event audience" '"event\":\"tourney' "$R"
R=$(tourney "{\"id\":\"$ID3\",\"action\":\"join\",\"tid\":\"$MTID\"}")
expect "the screen cannot join it and so can never hold a seat" '"error":"not in the event"' "$R"
R=$(tourney "{\"id\":\"$ID3\",\"action\":\"join\",\"code\":\"$MCODE\"}")
expect "nor by its code" '"error":"not in the event"' "$R"
R=$(evact "$ID3" monitor "$EID4")
expect "while it watches the same tournament as the screen" "\"tid\":\"$MTID\"" "$R"

# THE MONITOR AS A SPECTATOR (4.14). Once a match is up, the roles sheet
# names the screen - so every client grants it a feed, private duels
# included - and the screen receives the tournament's signals like a seat
# would, while sitting in none of the sheet's lists. A member joins, the
# host starts, and the sheet is dealt.
R=$(tourney "{\"id\":\"$ID2\",\"action\":\"join\",\"tid\":\"$MTID\"}")
expect "a member joins the tournament the screen watches" '"event":"lobby"' "$R"
curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID3\"}" "$BASE/api/hello.php" > /dev/null
R=$(tourney "{\"id\":\"$ID1\",\"action\":\"start\",\"tid\":\"$MTID\"}")
expect "and the host starts it" '"ok":true' "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID3\"}" "$BASE/api/hello.php")
expect "the screen is dealt the roles sheet with the players" 'event\":\"roles' "$R"
expect "which names the screen as the monitor" "\\\"monitor\\\":\\\"$ID3\\\"" "$R"
expect "with no seat of its own" 'you\":\"idle' "$R"
expect "and the event it belongs to, which is how a screen routes it" "\\\"eid\\\":\\\"$EID4\\\"" "$R"
expect "and nothing to wait for before it asks" 'after_ms\":0' "$R"
refute "while it is in none of the sheet's lists" "\\\"players\\\":[\\\"$ID3" "$R"
R=$(evact "$ID3" monitor "$EID4")
expect "the monitor read carries the same sheet" "\"monitor\":\"$ID3\"" "$R"
R=$(act "$ID1" state "$MTID")
expect "and so does a player's own read" "\"monitor\":\"$ID3\"" "$R"
refute "the screen is never among the players" "\"players\":[\"$ID3" "$R"
R=$(tourney "{\"id\":\"$ID1\",\"action\":\"leave\",\"tid\":\"$MTID\"}")
expect "the host closes it again" '"ok":true' "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$ID3\"}" "$BASE/api/hello.php")
expect "and is told when it is over" '"over\":true' "$R"
expect "by the tournament's own signal as well as the event's" 'host ended it' "$R"

# The operator's two id fields search by name, which is how one is set at all.
R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=player_find&q=SMOKE")
expect "the operator searches for a player by name" '"players":' "$R"
R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=player_find&q=")
expect "an empty search asks nothing of the database" '"players":[]' "$R"
R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=event&eid=$EID4")
expect "the popup names the reserved screen" "\"monitor\":\"$ID3\"" "$R"
expect "and says who is holding the slot" '"monitor_holder":' "$R"
expect "the screen being the one row only the operator sees" '"state":"monitor"' "$R"
R=$(evadmin event_delete "eid=$EID4")
expect "the reserved event is deleted again" '"ok":true' "$R"

# ---- the poster works before the event does ----
#
# A printed key goes on a wall days ahead, so scanning it joins an event that
# has not started. A PASS does not: it is minted from the clock by a member,
# and an upcoming event mints none. The achievement waits for the start as
# well - joining something that has not happened earns nothing yet.
R=$(evadmin event_create "name=srv-CI-soon" "organizer=$ID1" "closed=0" \
    "mode=active" "ach_name=EARLY BIRD" "ach_desc=Joined srv-CI-soon")
EID5=$(evfield "$R" eid)
expect "an operator opens an event that will be scanned early" '"ok":true' "$R"
R=$(curl -s -b "$COOKIES" "$BASE/admin/event.php?eid=$EID5")
KEY5=$(echo "$R" | grep -oE "class=\"code pixel\">[0-9A-Z]{11}<" | grep -oE "[0-9A-Z]{11}" | head -1 || true)
expect "whose poster carries a key" "$KEY5" "$R"
# Minted while it is still active, because that is the only time one exists.
R=$(evact "$ID1" pass "$EID5")
PASS5=$(echo "$R" | grep -oE "\"code\":\"[0-9A-Z]{6}\"" | head -1 | cut -d'"' -f4 || true)
expect "and a pass while it is running" '"slots":' "$R"
# SCHEDULED, which is what a future event is: the state is derived from
# the stamps and the clock, so moving `starts` is what makes it upcoming.
# Events::edit deliberately does not write `mode` - a scheduled event walks
# itself and its organizer cannot drive it.
NOW5=$(date +%s)
R=$(evadmin event_edit "eid=$EID5" "starts=$((NOW5 + 3600))")
expect "the operator schedules it for an hour from now" '"ok":true' "$R"
R=$(evact "$ID1" state "$EID5")
expect "which is what it reads as" '"state":"upcoming"' "$R"

R=$(evjoin "$ID2" "$KEY5")
expect "a printed key joins an event that has not started" '"you":{"state":"member"' "$R"
expect "and the answer says what it is waiting for" '"state":"upcoming"' "$R"
refute "with no achievement for an event that has not happened" '"ach"' "$R"
R=$(evpass "$ID3" "$EID5" "$PASS5")
expect "while a pass is still refused before the start" '"error":"not started"' "$R"
R=$(evact "$ID2" pass "$EID5")
expect "and an upcoming event mints none either" '"error":"not started"' "$R"

R=$(evadmin event_edit "eid=$EID5" "starts=$((NOW5 - 60))")
expect "the event's start moment passes" '"ok":true' "$R"
R=$(evact "$ID2" state "$EID5")
expect "and the early member is simply in it" '"you":{"state":"member"' "$R"
expect "with the achievement arriving on the first read after that" '"ach":' "$R"
R=$(evadmin event_delete "eid=$EID5")
expect "the early event is deleted again" '"ok":true' "$R"

# ---- cleanup: the smoke leaves no event behind ----

# Whatever this file lowered goes back to its default, or the install
# answers a number the code no longer sets (see Settings::set).
setting tournament_create_cooldown 10

# This file is the only one that needs a THIRD player - somebody who is
# neither the organizer nor the first member - and the admin section after
# it asserts an exact registered count. So it takes its own guest away.
R=$(curl -s -b "$COOKIES" -X POST -d "id=$ID3" "$BASE/admin/api.php?action=delete_player")
expect "the third player this file needed is removed again" '"ok":true' "$R"


R=$(evadmin event_delete "eid=$EID3")
expect "the operator deletes an event" '"ok":true' "$R"
R=$(evadmin event_delete "eid=$EID1")
expect "and the other one with it" '"ok":true' "$R"
R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=event&eid=$EID1")
expect "which the dashboard no longer knows" '"error":"unknown event"' "$R"
R=$(evact "$ID2" state "$EID1")
expect "and neither does anybody who was in it" '"error":"no such event"' "$R"

fi
