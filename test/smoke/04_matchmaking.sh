
# Mass friend requests: alert + timed ban + purge of the spammer's pendings.
# With admin, lower the cap so a handful trips the ban; otherwise flood (15).
freqs=$(seq 10 26)
if [ "$ADMIN" -eq 1 ]; then setting friend_req_max 3; freqs=$(seq 10 16); fi
for i in $freqs; do
    R=$(curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$ID1\",\"action\":\"request\",\"peer\":\"aa0000$i\"}" "$BASE/api/friend.php")
done
expect "friend-request spam banned" 'banned' "$R"
[ "$ADMIN" -eq 1 ] && setting friend_req_max 15
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

R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\",\"action\":\"seek\"}" "$BASE/api/match.php")
expect "first seeker waits" '"waiting":true' "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID2\",\"action\":\"seek\"}" "$BASE/api/match.php")
expect "second seeker matched" "\"matched\":\"$ID1\"" "$R"
expect "second seeker answers" '"role":"answerer"' "$R"
expect "match carries peer name" '"peer_name":"SMOKE ONE"' "$R"
R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$ID1\",\"action\":\"seek\"}" "$BASE/api/match.php")
expect "first seeker offers" '"role":"offerer"' "$R"

