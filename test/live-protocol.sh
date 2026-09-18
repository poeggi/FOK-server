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
#   - the "connecting" signal burst, where ConnTrack::set keeps an established
#     relay mode across a same-state re-stamp but must NEVER swallow a
#     p2p->relay mode upgrade (ConnTrack).
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

# --- Identity (API 4.20). This harness is a client, so every request that
# names one of its ids carries that id's token. The cast is bound on the
# first run against an environment - the hello that carries tok as null is
# answered the token - and the tokens are kept OUTSIDE the repo, one line
# per (base, id) in ~/.fok-server-livetest.tok beside the deploy
# credentials, so every later run proves the same ids. A token the server
# no longer knows (an operator's reset) is replaced by what the next hello
# answers; an id somebody else bound is a 401 here, and a finding.
TOKFILE="${FOK_LIVETEST_TOK:-$HOME/.fok-server-livetest.tok}"
declare -A TOK=()
if [ -f "$TOKFILE" ]; then
    while read -r b i t; do [ "$b" = "$BASE" ] && TOK[$i]=$t; done < "$TOKFILE"
fi
jt() { if [ -n "${TOK[$1]:-}" ]; then printf ',"tok":"%s"' "${TOK[$1]}"; else printf ',"tok":null'; fi; }
adopt() { # adopt <id> <hello answer> : the one rule - store whatever a hello answers
    local t
    t=$(echo "$2" | grep -oE '"tok":"[a-f0-9]{32}"' | cut -d'"' -f4 || true)
    [ -n "$t" ] || return 0
    TOK[$1]=$t
    { [ -f "$TOKFILE" ] && grep -v "^$BASE $1 " "$TOKFILE"; echo "$BASE $1 $t"; } > "$TOKFILE.new"
    mv "$TOKFILE.new" "$TOKFILE"
    chmod 600 "$TOKFILE"
    echo "   bound $1 on $BASE (token kept in $TOKFILE)"
}
# A hello sets R rather than printing: adopting the token has to happen in
# THIS shell, and a $(...) would keep it to itself.
hello() { # hello <id> <name> [-4|-6] [,"member":value...]
    R=$(curl ${3:-} -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$1\"$(jt "$1"),\"name\":\"$2\"${4:-}}" "$BASE/api/hello.php")
    adopt "$1" "$R"
}
seek() { # seek <id>
    curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$1\"$(jt "$1"),\"action\":\"seek\"}" "$BASE/api/match.php"
}
sig() { # sig <from> <to> <type> <payload>
    curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$1\"$(jt "$1"),\"to\":\"$2\",\"type\":\"$3\",\"payload\":\"$4\"}" "$BASE/api/signal.php" > /dev/null
}
poll() { # drain <id>'s signals: the POST form (4.21), the token in the body
    curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$1\"$(jt "$1")}" "$BASE/api/poll.php"
}
rly() { # rly <from> <peer> <payload>
    curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$1\"$(jt "$1"),\"peer\":\"$2\",\"payload\":\"$3\"}" "$BASE/api/relay.php" > /dev/null
}
rlyget() { # the relay's held read: a POST without a payload (4.21)
    curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$1\"$(jt "$1"),\"peer\":\"$2\",\"wait\":${3:-0}}" "$BASE/api/relay.php"
}

# Fixed throwaway ids (8-hex, the server's id format) so repeat runs reuse the
# same four rows instead of littering the live player list with a fresh set
# every run. The shared 7e57 ("test") suffix marks them as this smoke's own,
# so they are easy to spot and remove on the admin dashboard.
A=11117e57; B=22227e57; C=33337e57; D=44447e57
echo "== live-protocol smoke against $BASE"
echo "   ids A=$A B=$B C=$C D=$D"

# --- Health: is the deployment answering, and is it the contract we expect?
V=$(curl -s "$BASE/api/version.txt")
expect "version endpoint answers" '"ok":true' "$V"
# Clients gate on the MAJOR only (see docs/API.md "Versioning"), so that is
# what a protocol harness pins: a MINOR bump is additive by definition and
# must not fail a run, where a MAJOR one means this script's wire is gone.
expect "api contract is major 4" '"api":"4.' "$V"

# Skew the client clock to the server so a start pts lands just in the past,
# never the future the sync gate rejects. The clock is t.txt's X-Fok-T
# stamp (microseconds), the one clock source there is; on a real host a
# missing header is a failure, not a case.
hdr=$(curl -s -o /dev/null -D - "$BASE/api/t.txt" | tr 'A-Z' 'a-z')
expect "clock source stamps the arrival time" 'x-fok-t: t=' "$hdr"
srv_us=$(echo "$hdr" | grep -oE 'x-fok-t: t=[0-9]+' | grep -oE '[0-9]+$')
skew=$(( ${srv_us:-0} / 1000 - $(date +%s%3N) ))
now_ms() { echo $(( $(date +%s%3N) + skew )); }
start_req() { # id peer epoch reason pts
    curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$1\"$(jt "$1"),\"peer\":\"$2\",\"epoch\":$3,\"reason\":\"$4\",\"pts\":$5}" "$BASE/api/start.php"
}

# --- Register both players (records the display name the peer will see).
# Every name here is srv-CI-*: on a live instance the players list is read by
# an operator, and a harness must never look like somebody's account.
hello "$A" srv-CI-1111; expect "hello A registers" '"ok":true' "$R"
hello "$B" srv-CI-2222; expect "hello B registers" '"ok":true' "$R"

# --- Quick match: the matchmaking-prune path. Two LIVE seekers must still
# pair, name carried, roles assigned.
expect "first seeker waits" '"waiting":true' "$(seek "$A")"
M=$(seek "$B")
expect "second seeker is matched to the first" "\"matched\":\"$A\"" "$M"
expect "the matched seeker is the answerer" '"role":"answerer"' "$M"
expect "the match carries the peer name" 'srv-CI-1111' "$M"
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

# The same burst as ONE request ('ices', 4.4). This is the whole point of the
# type on this host: the cost measured here is paid per REQUEST, not per byte.
# Worth asserting against the REAL host rather than php -S, because the thing
# it answers - queueing above the PHP pool - only exists on the real one.
sig "$A" "$B" ices '[{\"candidate\":\"batch-1\"},{\"candidate\":\"batch-2\"}]'
BR=$(poll "$B")
expect "a batched candidate list is delivered" '"type":"ices"' "$BR"
ordered "and carries the whole batch" 'batch-1' 'batch-2' "$BR"
R=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$A\"$(jt "$A"),\"to\":\"$B\",\"type\":\"ices\",\"payload\":\"not-an-array\"}" "$BASE/api/signal.php")
expect "a batch that is not an array is refused" '"error":"invalid payload"' "$R"

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
hello "$C" srv-CI-3333; expect "hello C registers" '"ok":true' "$R"
hello "$D" srv-CI-4444; expect "hello D registers" '"ok":true' "$R"
INV=$(curl -s -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":\"$C\"$(jt "$C"),\"to\":\"$D\",\"type\":\"invite\",\"payload\":\"x\"}" "$BASE/api/signal.php")
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
    tourney "{\"id\":\"$1\"$(jt "$1"),\"action\":\"$2\",\"tid\":\"$3\"}"
}
result() { # result <id> <tid> <nid> <outcome> <mine> <theirs>
    tourney "{\"id\":\"$1\"$(jt "$1"),\"action\":\"result\",\"tid\":\"$2\",\"nid\":\"$3\",\"outcome\":\"$4\",\"score\":[$5,$6]}"
}

TR=$(tourney "{\"id\":\"$A\"$(jt "$A"),\"action\":\"create\"}")
case "$TR" in
*'"ok":true'*)
    T1=$(tfield "$TR" tid)
    CODE=$(tfield "$TR" code)
    # The cap is a Settings key, so this reads what the HOST is serving, not
    # what Config.php says: a stored row shadows a changed default silently.
    expect "a host opens a lobby at the deployed player cap" '"max":8' "$TR"
    R=$(tourney "{\"id\":\"$B\"$(jt "$B"),\"action\":\"join\",\"code\":\"$CODE\"}")
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
    # A finished round does not roll into the next one: the server stops on a
    # scoreboard and waits for the host to press on (the whole break is walked
    # in test/smoke/07_tournament.sh).
    R=$(act "$A" state "$T1")
    expect "the round ends on a board, not on the next match" '"stage":"final"' "$R"
    sleep 1.1
    expect "the host presses on once the board has been up" '"ok":true' "$(act "$A" continue "$T1")"
    R=$(act "$A" state "$T1")
    expect "which deals the final" '"cursor":"final"' "$R"
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

# --- Dual-stack announce -------------------------------------------------
# The one thing a single-stack CI runner cannot test, and the reason the local
# tournament announce did not work in a real house: a dual-stack client picks
# an address family per connection, so the host's hello can arrive over IPv6
# while the joiner's arrives over IPv4, and the two describe the same room in
# two strings that will never be equal. Here both families are real, so drive
# them on purpose (curl -4 / -6) instead of hoping the resolver alternates.
#
# Only the POSITIVE cases are asserted here. "A network that must NOT match"
# needs a player with no other network recorded, and these ids are reused
# across runs by design, so that boundary is pinned in test/unit.php where the
# state is built from nothing.
E=55557e57; F=66667e57; G=77777e57; H=88887e57
# Which families this runner has, and its own public address in each.
# www4/www6 are single-family names that echo the caller's address back, so
# one fetch per family answers both questions at once: whether the family
# works end to end, and what a server sees us as on it. They redirect to
# https, hence -L. Asking our own server would answer neither - it reports
# the family the request happened to arrive on, which is the thing under
# test.
V4=0; V6=0
MY4=$(curl -sL -m 10 https://www4.poggensee.it/ip)
MY6=$(curl -sL -m 10 https://www6.poggensee.it/ip)
[[ "$MY4" =~ ^[0-9.]+$ ]] && V4=1 || MY4=''
[[ "$MY6" =~ ^[0-9a-fA-F:]+$ ]] && V6=1 || MY6=''
if [ "$V4" -eq 1 ] && [ "$V6" -eq 1 ]; then
    echo "   dual-stack runner: driving the announce over both families (host=$E seekers=$D,$G)"
    hf() { hello "$2" srv-CI-dual "$1" ',"tourneys":true'; }   # hf <-4|-6> <id> : hello asking for the announce, sets R
    tf() { curl "$1" -s -X POST -H 'Content-Type: application/json' -d "$2" "$BASE/api/tournament.php"; }
    hf -6 "$E"
    TR=$(tf -6 "{\"id\":\"$E\"$(jt "$E"),\"action\":\"create\"}")
    case "$TR" in
    *'"ok":true'*)
        T2=$(tfield "$TR" tid)
        # Same family, the case that always worked: both sides came in over v6.
        hf -6 "$D"; expect "an ipv6 host is announced to an ipv6 seeker" "\"tid\":\"$T2\"" "$R"
        # THE FIX, seeker side: the same seeker now asks over IPv4. Its own v6
        # network is still one of the networks it is on, so the room it shares
        # with the host is still found.
        hf -4 "$D"; expect "and to that seeker when it asks over ipv4 instead" "\"tid\":\"$T2\"" "$R"
        # THE FIX, host side: the host is seen over IPv4 too, which is what a
        # browser does on its own sooner or later. A seeker that only ever
        # speaks v4 can now be told about a lobby opened over v6.
        hf -4 "$E"
        hf -4 "$G"; expect "a dual-stack host reaches a v4-only seeker" "\"tid\":\"$T2\"" "$R"
        tf -6 "{\"id\":\"$E\"$(jt "$E"),\"action\":\"leave\",\"tid\":\"$T2\"}" > /dev/null
        # THE CLAIM PATH (api 4.2), which only a real dual-stack machine can
        # exercise: $F is never touched over IPv4 here, so the server cannot
        # have OBSERVED its v4 network - the only way a v4 seeker learns
        # about its lobby is the address $F reported about itself. That is
        # what a browser will do: it cannot choose a family for a request,
        # but it can find its own public addresses through STUN.
        if [ -n "$MY4" ]; then
            hello "$F" srv-CI-claim -6 ',"nets":["'"$MY4"'"]'
            TR=$(tf -6 "{\"id\":\"$F\"$(jt "$F"),\"action\":\"create\"}")
            case "$TR" in
            *'"ok":true'*)
                T3=$(tfield "$TR" tid)
                hf -4 "$H"; expect "a v6-only host is reachable on the v4 network it reported" "\"tid\":\"$T3\"" "$R"
                tf -6 "{\"id\":\"$F\"$(jt "$F"),\"action\":\"leave\",\"tid\":\"$T3\"}" > /dev/null
                ;;
            *'create cooldown'* | *'already hosting'*)
                echo "skip the claimed-network announce: $F is too soon after its last one ($TR)"
                ;;
            *)
                echo "FAIL the claiming host could not open a lobby: $TR"
                fail=1
                ;;
            esac
        else
            echo "skip the claimed-network announce: could not read this machine's own v4 address"
        fi
        ;;
    *'create cooldown'* | *'already hosting'*)
        echo "skip the dual-stack announce: $E is too soon after its last one ($TR)"
        ;;
    *)
        echo "FAIL a dual-stack host could not open a lobby: $TR"
        fail=1
        ;;
    esac
else
    echo "skip the dual-stack announce checks: this machine reaches $BASE over one family only (v4=$V4 v6=$V6)"
fi

# --- TURN (API 4.22). A credential from THIS server carries a DataChannel
# through Cloudflare's relay: test/turn-probe.mjs drives headless Edge with
# two peer connections forced onto the relay and reads the echo back. The
# server offering no TURN (503: no key on the host) is the operator's
# choice and is reported, not failed; a credential that does not carry is.
# Needs node and Edge on this box; without them the step is skipped.
echo
if command -v node > /dev/null 2>&1; then
    TP=$(node test/turn-probe.mjs --base "$BASE" 2>&1 || true)
    case "$TP" in
    *'TURN PROBE PASSED'*)
        echo "ok   turn: $(grep -oE 'relay-only: open [0-9]+ ms, rtt [0-9.]+ ms' <<< "$TP")"
        ;;
    *'turn.php:   503'*)
        echo "note turn: this server offers no TURN (503 turn_unavailable) - the key file is not on the host, or it is switched off"
        ;;
    *)
        echo "FAIL turn: $(grep -E '^(error|turn.php|relay-only)' <<< "$TP" | tr '\n' ';')"
        fail=1
        ;;
    esac
else
    echo "skip turn: no node on this box"
fi

echo
if [ "$fail" -ne 0 ]; then
    echo "LIVE PROTOCOL SMOKE FAILED"
    exit 1
fi
echo "LIVE PROTOCOL SMOKE PASSED"
