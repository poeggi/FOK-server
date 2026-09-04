
# Friend feature suite (API 3.5): existence feedback, spam ban, request
# throttle. It needs admin to lower the ban cap, to re-enable the throttle
# lib.sh disabled suite-wide, and to register/delete its temporary peers - an
# unknown peer now answers exists:false and records nothing - so a bare remote
# diagnostic (no creds) skips it.
if [ "$ADMIN" -ne 1 ]; then
    echo "skip friend feature suite (needs admin to relax caps and register peers)"
else
    # Existence feedback: a request to an unregistered id is reported back and
    # recorded nowhere; a request to a registered id records a pending row.
    R=$(curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$ID1\",\"action\":\"request\",\"peer\":\"deadface\"}" "$BASE/api/friend.php")
    expect "request to an unknown id reports exists:false" '"exists":false' "$R"
    if echo "$R" | grep -q '"state"'; then
        echo "FAIL unknown-id request recorded a state: $R"; fail=1
    else
        echo "ok   unknown-id request records no state"
    fi
    hello "aa000010" > /dev/null
    R=$(curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$ID1\",\"action\":\"request\",\"peer\":\"aa000010\"}" "$BASE/api/friend.php")
    expect "request to a known id records it" '"state":"pending"' "$R"
    expect "a recorded request reports exists:true" '"exists":true' "$R"

    # Mass friend requests: alert + timed ban + purge of the spammer's
    # pendings. Lower the cap so a handful trips it; the peers must exist.
    setting friend_req_max 3
    for p in aa000011 aa000012 aa000013 aa000014; do hello "$p" > /dev/null; done
    for p in aa000011 aa000012 aa000013 aa000014; do
        R=$(curl -s -X POST -H 'Content-Type: application/json' \
            -d "{\"id\":\"$ID1\",\"action\":\"request\",\"peer\":\"$p\"}" "$BASE/api/friend.php")
    done
    expect "friend-request spam banned" 'banned' "$R"
    setting friend_req_max 15
    R=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$ID1\",\"action\":\"request\",\"peer\":\"aa000099\"}" "$BASE/api/friend.php")
    expect "banned client stays banned" '429' "$R"
    R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\",\"action\":\"list\"}" "$BASE/api/friend.php")
    if echo "$R" | grep -q '"state":"pending"'; then
        echo "FAIL spammer pendings not purged: $R"; fail=1
    else
        echo "ok   spammer pending requests purged"
    fi
    R=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$ID1\",\"to\":\"$ID2\",\"type\":\"invite\",\"payload\":\"again?\"}" "$BASE/api/signal.php")
    expect "invite blocked again after removal" '403' "$R"

    # Per-id request throttle (the anti-probe guard). Re-enable it at tight
    # caps with dedicated prober ids: first the 1-per-interval scale, then the
    # burst -> cooldown scale, whose trip must land a visible server-log line.
    setting friend_rate_interval 5
    R=$(curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"c0c0c0c0\",\"action\":\"request\",\"peer\":\"aa000010\"}" "$BASE/api/friend.php")
    expect "first request passes the throttle" '"ok":true' "$R"
    R=$(curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"c0c0c0c0\",\"action\":\"request\",\"peer\":\"aa000011\"}" "$BASE/api/friend.php")
    expect "a second request within the interval is throttled" 'friend requests too fast' "$R"
    expect "the throttle answers 429 with retry_after" '"retry_after":5' "$R"
    setting friend_rate_interval 0
    setting friend_rate_burst 3
    for p in aa000010 aa000011 aa000012 aa000099; do
        R=$(curl -s -X POST -H 'Content-Type: application/json' \
            -d "{\"id\":\"c1c1c1c1\",\"action\":\"request\",\"peer\":\"$p\"}" "$BASE/api/friend.php")
    done
    expect "a burst trips the cooldown" 'friend request cooldown' "$R"
    if [ "$REMOTE" -eq 0 ]; then
        if grep -q 'friend-request cooldown' "$DATA/php-error.log" 2>/dev/null; then
            echo "ok   cooldown trip logged a visible server warning"
        else
            echo "FAIL cooldown trip not written to the server log"; fail=1
        fi
    fi

    # Escalation (see Friends::rateHit): a repeat burst within the window earns
    # the long cooldown, not the short one. Keep the burst to two requests
    # (burst=1) so the pair always lands inside one cooldown even over a slow
    # remote link; the cooldown is short enough to lift with a brief sleep, and
    # a distinctive hard cooldown makes the escalated retry_after unmistakable.
    setting friend_rate_cooldown 4
    setting friend_rate_cooldown_hard 7200
    setting friend_rate_burst 1
    for p in aa000010 aa000011; do
        R=$(curl -s -X POST -H 'Content-Type: application/json' \
            -d "{\"id\":\"c2c2c2c2\",\"action\":\"request\",\"peer\":\"$p\"}" "$BASE/api/friend.php")
    done
    expect "a first burst trips the short cooldown" '"retry_after":4' "$R"
    sleep 5
    for p in aa000010 aa000011; do
        R=$(curl -s -X POST -H 'Content-Type: application/json' \
            -d "{\"id\":\"c2c2c2c2\",\"action\":\"request\",\"peer\":\"$p\"}" "$BASE/api/friend.php")
    done
    expect "a repeat burst within the window escalates to the long cooldown" '"retry_after":7200' "$R"
    if [ "$REMOTE" -eq 0 ]; then
        # The first trip is a note, the escalation is an alert - and an alert
        # always writes its own "FOK alert <type>:" line (see Alerts).
        if grep -q 'FOK alert friend-cooldown-hard' "$DATA/php-error.log" 2>/dev/null; then
            echo "ok   escalation raised an alert and logged it"
        else
            echo "FAIL escalation not written to the server log"; fail=1
        fi
        if grep -q 'FOK friend: c2c2c2c2 hit the friend-request cooldown' "$DATA/php-error.log" 2>/dev/null; then
            echo "ok   the first trip is noted, not alerted"
        else
            echo "FAIL first cooldown trip not noted in the server log"; fail=1
        fi
    fi
    setting friend_rate_cooldown 60
    setting friend_rate_cooldown_hard 3600
    setting friend_rate_interval 0
    setting friend_rate_burst 1000000

    # Drop the temporary players so the registered count stays exact for the
    # admin stats test (delete_player clears the counts cache).
    for p in aa000010 aa000011 aa000012 aa000013 aa000014 c0c0c0c0 c1c1c1c1 c2c2c2c2; do
        curl -s -b "$COOKIES" -X POST -d "id=$p" "$BASE/admin/api.php?action=delete_player" > /dev/null
    done
fi

R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\",\"action\":\"seek\"}" "$BASE/api/match.php")
expect "first seeker waits" '"waiting":true' "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID2\",\"action\":\"seek\"}" "$BASE/api/match.php")
expect "second seeker matched" "\"matched\":\"$ID1\"" "$R"
expect "second seeker answers" '"role":"answerer"' "$R"
expect "match carries peer name" '"peer_name":"SMOKE ONE"' "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\",\"action\":\"seek\"}" "$BASE/api/match.php")
expect "first seeker offers" '"role":"offerer"' "$R"

