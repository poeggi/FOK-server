#!/usr/bin/env bash
# Post-deploy protocol smoke: run from a workstation against the LIVE (or any
# deployed) server to confirm the multiplayer wire still works end to end after
# a deploy. Two throwaway clients drive the real handshake over real HTTP - no
# admin, no game logic, no writes beyond ordinary protocol traffic that the
# server expires on its own.
#
#   bash test/live-protocol.sh [base-url]      # default: the live server
#   LIVE_BASE=https://host/staging bash test/live-protocol.sh
#
# It targets the exact paths the 1.0.7 writer-contention cuts touch:
#   - quick match, where the peer-select now gates on seeker liveness and the
#     cleanup DELETE is sampled (Matchmaking);
#   - the "connecting" signal burst, where ConnTrack::set skips a same-state
#     re-stamp within FOK_CONN_TRACK_THROTTLE but must NEVER skip a p2p->relay
#     mode upgrade (ConnTrack).
# The upgrade's mode is only readable through the admin Duels card, so here we
# assert the client-observable consequences instead: every signal in the burst
# still arrives in order, and a relay pairing declared mid-burst still relays
# and still tears down with a v3.3 "gone". The definitive mode=relay assertion
# belongs to the client-side (admin-capable) test. NOT wired into checks.sh -
# CI stays offline; this needs a network and a running deployment.
set -uo pipefail

BASE="${1:-${LIVE_BASE:-https://fok-server.poggensee.it}}"
BASE="${BASE%/}"

fail=0
expect() { # expect <name> <needle> <actual>
    if [[ "$3" == *"$2"* ]]; then
        echo "ok   $1"
    else
        echo "FAIL $1: expected '$2' in: $3"
        fail=1
    fi
}
ordered() { # ordered <name> <first> <second> <actual>
    if [[ "$4" == *"$2"* && "$4" == *"$3"* && "${4%%"$3"*}" == *"$2"* ]]; then
        echo "ok   $1"
    else
        echo "FAIL $1: expected '$2' before '$3' in: $4"
        fail=1
    fi
}

hello() { # hello <id> <name>
    curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$1\",\"name\":\"$2\"}" "$BASE/api/hello.php"
}
seek() { # seek <id>
    curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$1\",\"action\":\"seek\"}" "$BASE/api/match.php"
}
sig() { # sig <from> <to> <type> <payload>
    curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$1\",\"to\":\"$2\",\"type\":\"$3\",\"payload\":\"$4\"}" "$BASE/api/signal.php" > /dev/null
}
poll() { curl -s "$BASE/api/poll.php?id=$1"; }               # drain <id>'s signals
rly() { # rly <from> <peer> <payload>
    curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$1\",\"peer\":\"$2\",\"payload\":\"$3\"}" "$BASE/api/relay.php" > /dev/null
}
rlyget() { curl -s "$BASE/api/relay.php?id=$1&peer=$2&wait=${3:-0}"; }

# Fixed throwaway ids (8-hex, the server's id format) so repeat runs reuse the
# same four rows instead of littering the live player list with a fresh set
# every run. The shared 7e57 ("test") suffix marks them as this smoke's own,
# so they are easy to spot and remove on the admin dashboard.
A=11117e57; B=22227e57; C=33337e57; D=44447e57
echo "== live-protocol smoke against $BASE"
echo "   ids A=$A B=$B C=$C D=$D"

# --- Health: is the deployment answering, and is it the contract we expect?
V=$(curl -s "$BASE/api/version.php")
expect "version endpoint answers" '"ok":true' "$V"
# Clients gate on the MAJOR only (see docs/API.md "Versioning"), so that is
# what a protocol harness pins: a MINOR bump is additive by definition and
# must not fail a run, where a MAJOR one means this script's wire is gone.
expect "api contract is major 4" '"api":"4.' "$V"

# Skew the client clock to the server so a start pts lands just in the past,
# never the future the sync gate rejects.
srv_ms=$(curl -s "$BASE/api/time.php" | grep -oE '"t":[0-9]+' | cut -d: -f2)
skew=$(( srv_ms - $(date +%s%3N) ))
now_ms() { echo $(( $(date +%s%3N) + skew )); }
start_req() { # id peer epoch reason pts
    curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$1\",\"peer\":\"$2\",\"epoch\":$3,\"reason\":\"$4\",\"pts\":$5}" "$BASE/api/start.php"
}

# --- Register both players (records the display name the peer will see).
expect "hello A registers" '"ok":true' "$(hello "$A" LIVEA)"
expect "hello B registers" '"ok":true' "$(hello "$B" LIVEB)"

# --- Quick match: the matchmaking-prune path. Two LIVE seekers must still
# pair, name carried, roles assigned.
expect "first seeker waits" '"waiting":true' "$(seek "$A")"
M=$(seek "$B")
expect "second seeker is matched to the first" "\"matched\":\"$A\"" "$M"
expect "the matched seeker is the answerer" '"role":"answerer"' "$M"
expect "the match carries the peer name" 'LIVEA' "$M"
expect "the first seeker re-polls as offerer" '"role":"offerer"' "$(seek "$A")"

# --- Connecting-signal burst (P2P): the ICE-throttle path. offer/answer then
# a fast burst of ice candidates - all must reach the peer, in order.
sig "$A" "$B" offer 'sdp-offer'
expect "offer delivered" '"type":"offer"' "$(poll "$B")"
sig "$B" "$A" answer 'sdp-answer'
expect "answer delivered" '"type":"answer"' "$(poll "$A")"
sig "$A" "$B" ice 'cand-1'; sig "$A" "$B" ice 'cand-2'; sig "$A" "$B" ice 'cand-3'
BR=$(poll "$B")
ordered "ice burst all delivered, in order" 'cand-1' 'cand-3' "$BR"
expect "ice burst kept the middle candidate" 'cand-2' "$BR"

# --- Start: the server hands both peers the identical shared start moment.
start_req "$A" "$B" 0 first "$(now_ms)" > /tmp/lp_s1.$$ &
start_req "$B" "$A" 0 first "$(now_ms)" > /tmp/lp_s2.$$ &
wait
S1=$(grep -oE '"start_pts":[0-9]+' /tmp/lp_s1.$$ | cut -d: -f2)
S2=$(grep -oE '"start_pts":[0-9]+' /tmp/lp_s2.$$ | cut -d: -f2)
rm -f /tmp/lp_s1.$$ /tmp/lp_s2.$$
if [ -n "$S1" ] && [ "$S1" = "$S2" ]; then echo "ok   both peers get one identical start"; else echo "FAIL start pts differ: '$S1' vs '$S2'"; fail=1; fi
if [ "${#S1}" -eq 13 ]; then echo "ok   start pts is milliseconds"; else echo "FAIL start pts not ms: '$S1'"; fail=1; fi
sig "$A" "$B" bye ''; poll "$B" > /dev/null

# --- Invites are friend-gated: a stranger cannot be invited (quick match is
# the sanctioned path to a stranger, and it carries no invite). Assert the
# gate holds, then drive the relay pairing the quick-match way.
expect "hello C registers" '"ok":true' "$(hello "$C" LIVEC)"
expect "hello D registers" '"ok":true' "$(hello "$D" LIVED)"
INV=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$C\",\"to\":\"$D\",\"type\":\"invite\",\"payload\":\"x\"}" "$BASE/api/signal.php")
expect "inviting a stranger is refused" 'not friends' "$INV"

# --- Relay mode declared DURING the connecting burst (the must-not-break).
# A p2p accept, then a relay upgrade, then an ice burst on the same pair: if the
# throttle wrongly swallowed the upgrade the pairing would not be a relay one.
# Observable proof: it relays in order and tears down with a v3.3 "gone".
sig "$D" "$C" accept ''
sig "$D" "$C" accept-relay ''
CR=$(poll "$C")
expect "accept delivered" '"type":"accept"' "$CR"
expect "relay upgrade delivered mid-connect" '"type":"accept-relay"' "$CR"
sig "$C" "$D" ice 'r-1'; sig "$C" "$D" ice 'r-2'
poll "$D" > /dev/null
rly "$C" "$D" 'IN:1'; rly "$C" "$D" 'IN:2'
RR=$(rlyget "$D" "$C" 2)
ordered "relay pair delivers in order" 'IN:1' 'IN:2' "$RR"
expect "relayed messages carry a server age" '"age":' "$RR"
rly "$D" "$C" 'IN:3'
expect "the reverse relay direction works" 'IN:3' "$(rlyget "$C" "$D")"
sig "$C" "$D" bye ''
expect "after bye the relay peer is told it is gone" '"gone":true' "$(rlyget "$D" "$C")"
poll "$D" > /dev/null

# --- Tournament (API 4.1): the server orchestrates, the players play. Not one
# match or spectator byte passes through it, so what a live run can check is
# the orchestration wire - and above all the two things that only ever bite in
# production: the cap this deployment actually hands out, and the rule that a
# settled node cannot be reopened.
#
# A host may only create once per tournament_create_cooldown (10s), so a
# re-run inside that window SKIPS this section instead of failing it: nothing
# is wrong with the server, the run is simply too soon after the last one.
# The walk ends in 'done'. A terminal tournament is never read again, which is
# what makes leaving one behind on production harmless.
tourney() { # tourney <json-body> : POST to api/tournament.php, print the body
    curl -s -X POST -H 'Content-Type: application/json' -d "$1" "$BASE/api/tournament.php"
}
# A no-match grep exits 1, which the extractor swallows rather than let it
# abort the run (see test/smoke/07_tournament.sh).
tfield() { echo "$1" | grep -oE "\"$2\":\"[0-9A-Za-z]+\"" | head -1 | cut -d'"' -f4 || true; }
act() { # act <id> <action> <tid>
    tourney "{\"id\":\"$1\",\"action\":\"$2\",\"tid\":\"$3\"}"
}
result() { # result <id> <tid> <nid> <outcome> <mine> <theirs>
    tourney "{\"id\":\"$1\",\"action\":\"result\",\"tid\":\"$2\",\"nid\":\"$3\",\"outcome\":\"$4\",\"score\":[$5,$6]}"
}

TR=$(tourney "{\"id\":\"$A\",\"action\":\"create\"}")
case "$TR" in
*'"ok":true'*)
    T1=$(tfield "$TR" tid)
    CODE=$(tfield "$TR" code)
    # The cap is a Settings key, so this reads what the HOST is serving, not
    # what Config.php says: a stored row shadows a changed default silently.
    expect "a host opens a lobby at the deployed player cap" '"max":8' "$TR"
    R=$(tourney "{\"id\":\"$B\",\"action\":\"join\",\"code\":\"$CODE\"}")
    expect "the second player joins by code" "\"host\":\"$A\"" "$R"
    expect "the host starts it" '"ok":true' "$(act "$A" start "$T1")"
    R=$(act "$A" state "$T1")
    expect "the tournament is running" '"state":"running"' "$R"
    expect "with the first match dealt" '"cursor":"r1.1"' "$R"
    expect "and the caller holding its own roles sheet" '"you":' "$R"
    # Nobody lies to lose, so one reported loss settles the node on the spot.
    # Once settled it is closed: the other side claiming the opposite must be
    # answered with the standing verdict, never with a reopened node.
    expect "a reported loss settles at once" '"state":"settled"' "$(result "$A" "$T1" r1.1 loss 9 12)"
    expect "a contradicting late report cannot reopen it" '"state":"settled"' "$(result "$B" "$T1" r1.1 loss 12 9)"
    R=$(act "$A" state "$T1")
    expect "the settled node advanced the cursor to the final" '"cursor":"final"' "$R"
    expect "the final settles the same way" '"state":"settled"' "$(result "$A" "$T1" final loss 4 6)"
    R=$(act "$B" state "$T1")
    expect "the final settles the tournament" '"state":"done"' "$R"
    expect "and there is no match left in flight" '"cursor":null' "$R"
    ;;
*'create cooldown'* | *'already hosting'*)
    echo "skip the tournament walk: $A is too soon after its last one ($TR)"
    ;;
*)
    echo "FAIL a host could not open a lobby: $TR"
    fail=1
    ;;
esac

echo
if [ "$fail" -ne 0 ]; then
    echo "LIVE PROTOCOL SMOKE FAILED"
    exit 1
fi
echo "LIVE PROTOCOL SMOKE PASSED"
