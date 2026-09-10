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
evjoin() { # evjoin <id> <eid> <code>
    ev "{\"id\":\"$1\",\"action\":\"join\",\"eid\":\"$2\",\"code\":\"$3\"}"
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
expect "with the code under the QR" "$EID1." "$R"
expect "and says how to use it" 'Scan to join' "$R"
KEY1=$(echo "$R" | grep -oE "$EID1\.[0-9A-Z]{16}" | head -1 | cut -d. -f2 || true)
expect "the key is 16 characters" '16' "${#KEY1}"
R=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/admin/event.php?eid=$EID1")
expect "and the page is login-gated" '401' "$R"

# ---- getting in ----

R=$(evjoin "$ID2" "$EID1" "$KEY1")
expect "a scan of the printed key joins an open event" '"you":{"state":"member"' "$R"
expect "and the answer carries the member count" '"members":' "$R"
R=$(evjoin "$ID2" "$EID1" "$KEY1")
expect "scanning again answers the same, so a lost response costs nothing" '"you":{"state":"member"' "$R"
R=$(evadmin event_roster "eid=$EID1" "id=$ID2" "set=member")
expect "and the roster still holds one row for them" '"ok":true' "$R"
R=$(evjoin "$ID3" "$EID1" "ZZZZZZZZZZZZZZZZ")
expect "a wrong key is the same answer as no event at all" '"error":"no such event"' "$R"
R=$(evact "$ID3" state "$EID1")
expect "and leaves the scanner with no row" '"error":"no such event"' "$R"

# The live pass: minted from the clock, so no slot is ever stored, and ANY
# member may show one - that is what makes an event spread in a room.
R=$(evact "$ID2" pass "$EID1")
expect "a member is handed the next pass slots" '"slots":' "$R"
expect "with the rotation interval on the wire, not hard-coded" '"step":' "$R"
expect "and how long each code stays valid" '"valid":' "$R"
PASS=$(echo "$R" | grep -oE '"code":"[0-9A-Z]{6}"' | head -1 | cut -d'"' -f4 || true)
expect "the pass is six characters" '6' "${#PASS}"
R=$(evjoin "$ID3" "$EID1" "$PASS")
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
R=$(tourney "{\"id\":\"$ID2\",\"action\":\"join\",\"tid\":\"$ETID\"}")
expect "a member joins it" '"event":"lobby"' "$R"
R=$(tourney "{\"id\":\"$ID1\",\"action\":\"leave\",\"tid\":\"$ETID\"}")
expect "the host closes it again" '"ok":true' "$R"

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
R=$(evjoin "$ID3" "$EID1" "$KEY1")
expect "so they may scan their way back in" '"you":{"state":"member"' "$R"
R=$(evact "$ID1" leave "$EID1")
expect "but the organizer cannot leave its own event" '"error":"not the organizer"' "$R"

R=$(evact "$ID2" run "$EID1")
expect "a plain member cannot run the event" '"error":"not the organizer"' "$R"
R=$(evact "$ID1" pause "$EID1")
expect "the organizer pauses it" '"state":"paused"' "$R"
R=$(evjoin "$ID3" "$EID1" "$KEY1")
expect "and a paused event admits nobody" '"error":"paused"' "$R"
R=$(evact "$ID1" run "$EID1")
expect "running it again reopens the door" '"state":"active"' "$R"

# ---- the closed door ----

R=$(evadmin event_create "name=srv-CI-closed" "organizer=$ID1" "closed=1" "mode=active")
EID3=$(evfield "$R" eid)
R=$(curl -s -b "$COOKIES" "$BASE/admin/event.php?eid=$EID3")
KEY3=$(echo "$R" | grep -oE "$EID3\.[0-9A-Z]{16}" | head -1 | cut -d. -f2 || true)
R=$(evjoin "$ID2" "$EID3" "$KEY3")
expect "a scan at a closed door lands pending" '"you":{"state":"pending"' "$R"
refute "and a pending row is told no count" '"members":' "$R"
refute "nor the achievement" '"ach"' "$R"
refute "nor the archive" '"archive"' "$R"
R=$(evact "$ID2" members "$EID3")
expect "and cannot read the roster" '"error":"not a member"' "$R"
R=$(evact "$ID2" pass "$EID3")
expect "nor mint a pass" '"error":"not a member"' "$R"
R=$(evjoin "$ID2" "$EID3" "$KEY3")
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
R=$(evjoin "$ID3" "$EID3" "$KEY3")
expect "and the next scan goes straight in" '"you":{"state":"member"' "$R"
R=$(ev "{\"id\":\"$ID1\",\"action\":\"roster\",\"eid\":\"$EID3\",\"peer\":\"$ID3\",\"set\":\"banned\"}")
expect "a pest is banned" '"ok":true' "$R"
R=$(evjoin "$ID3" "$EID3" "$KEY3")
expect "and every later scan is refused" '"error":"banned"' "$R"
R=$(ev "{\"id\":\"$ID1\",\"action\":\"roster\",\"eid\":\"$EID3\",\"peer\":\"$ID3\",\"set\":\"none\"}")
expect "lifting the ban drops the row" '"ok":true' "$R"
R=$(evjoin "$ID3" "$EID3" "$KEY3")
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
R=$(evjoin "$ID2" "$EID3" "$KEY3")
expect "an ended event admits nobody" '"error":"ended"' "$R"
R=$(evact "$ID2" pass "$EID3")
expect "and mints no more passes" '"error":"ended"' "$R"
R=$(evact "$ID1" run "$EID3")
expect "and can never be run again" '"error":"ended"' "$R"
R=$(evact "$ID2" state "$EID3")
expect "though its members can still read it" '"state":"ended"' "$R"

# ---- the monitor: one screen per event, held two different ways ----

R=$(evact "$ID2" monitor "$EID1")
expect "a member takes the free monitor slot" '"you":{"state":"monitor"' "$R"
expect "and the screen is told how many have joined" '"members":' "$R"
expect "and how many are waiting" '"pending":' "$R"
expect "with the archive it shows between matches" '"archive":' "$R"
expect "and the tournament slot it fills while one runs" '"tourney":' "$R"
expect "the slot is not reserved on this event" '"reserved":false' "$R"
R=$(evact "$ID3" monitor "$EID1")
expect "so a second screen is turned away" '"error":"monitor taken"' "$R"
R=$(evact "$ID2" monitor "$EID1")
expect "while the holder renews by simply asking again" '"you":{"state":"monitor"' "$R"
R=$(evadmin event_edit "eid=$EID1" "monitor_allowed=0")
expect "an operator can stop offering one" '"ok":true' "$R"
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
KEY4=$(echo "$R" | grep -oE "$EID4\.[0-9A-Z]{16}" | head -1 | cut -d. -f2 || true)
R=$(evjoin "$ID3" "$EID4" "$KEY4")
expect "the reserved screen joins a CLOSED event without waiting" '"you":{"state":"monitor"' "$R"
refute "and is granted no achievement for it" '"ach"' "$R"
R=$(evjoin "$ID2" "$EID4" "$KEY4")
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
setting tournament_create_cooldown 0
R=$(tourney "{\"id\":\"$ID1\",\"action\":\"create\",\"eid\":\"$EID4\"}")
MTID=$(evfield "$R" tid)
MCODE=$(evfield "$R" code)
expect "the organizer opens a tournament the screen will watch" '"tid":' "$R"
R=$(tourney "{\"id\":\"$ID3\",\"action\":\"join\",\"tid\":\"$MTID\"}")
expect "the screen cannot join it and so can never hold a seat" '"error":"not in the event"' "$R"
R=$(tourney "{\"id\":\"$ID3\",\"action\":\"join\",\"code\":\"$MCODE\"}")
expect "nor by its code" '"error":"not in the event"' "$R"
R=$(evact "$ID3" monitor "$EID4")
expect "while it watches the same tournament as the screen" "\"tid\":\"$MTID\"" "$R"
R=$(tourney "{\"id\":\"$ID1\",\"action\":\"leave\",\"tid\":\"$MTID\"}")
expect "the host closes it again" '"ok":true' "$R"

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
