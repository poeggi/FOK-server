#!/usr/bin/env bash
# Smoke test over real HTTP - the runner. lib.sh sets up ONE server (or targets
# a remote one) and the shared helpers; each part below runs its feature area
# against that single shared instance. The parts are plain slices of what was
# one long file, split only for length. See test/smoke/lib.sh for local vs
# remote (SMOKE_BASE) mode.
#
# ORDER IS LOAD-BEARING inside a group: state set up early (a friendship,
# registered players) is reused later. Between groups it is not, which is
# what the remote run exploits below.
set -euo pipefail
cd "$(dirname "$0")/.."

source test/smoke/lib.sh                    # env detect, server boot, helpers, admin login

# A group: its own four ids, its parts in order, its output and its fail
# count kept apart so the log can be printed in a fixed order afterwards.
# Called plainly, never in $(...): the background job has to be THIS shell's
# child or `wait` cannot see it.
group() { # group <name> <id1> <id2> <id3> <id4> <part>...
    local name=$1 i1=$2 i2=$3 i3=$4 i4=$5
    shift 5
    (
        ID1=$i1; ID2=$i2; ID3=$i3; ID4=$i4
        for p in "$@"; do
            source "test/smoke/$p.sh"
        done
        echo "$fail" > "$DATA/fail.$name"
    ) > "$DATA/out.$name" 2>&1 &
    GPID=$!
}

if [ "$REMOTE" -eq 1 ] && [ -z "${SMOKE_SEQUENTIAL:-}" ]; then
    # Against a remote host the suite is ~700 sequential round trips of
    # ~130 ms each, so its length is distance, not work. The parts fall into
    # three groups that share nothing - not an id, not a settings key they
    # change - and those run at once through the keep-alive tunnel, which is
    # threaded and pools its upstream connections. Local mode stays
    # sequential: php -S serves one request at a time on the box that runs
    # the pre-commit hook, so a 9 s hold in one group would stall the others
    # and fail their timing assertions.
    #
    # What stays sequential, and why: 09_sweep decides by WHICH request fires
    # the tournament sweep, and the sweep rides the deferred tail of any
    # client request - it needs a server nobody else is asking. 06_admin
    # reads whole-database totals every group contributes to, so it goes
    # last, and on a remote run it also cleans up after every group.
    rid() { od -An -N4 -tx1 /dev/urandom | tr -d ' \n'; }
    B1=$(rid); B2=$(rid); B3=$(rid); B4=$(rid)
    C1=$(rid); C2=$(rid); C3=$(rid); C4=$(rid)
    group core    "$ID1" "$ID2" "$ID3" "$ID4" 01_core 02_signals_friends 03_start_duel 04_matchmaking; P_CORE=$GPID
    group items   "$B1"  "$B2"  "$B3"  "$B4"  05_items;                                                P_ITEMS=$GPID
    group tourney "$C1"  "$C2"  "$C3"  "$C4"  07_tournament 08_events;                                P_TOURNEY=$GPID
    echo "     (three groups in flight: core, items, tourney)"
    # Explicit pids: a bare `wait` would also wait on the tunnel.
    wait "$P_CORE" "$P_ITEMS" "$P_TOURNEY" || true
    for g in core items tourney; do
        cat "$DATA/out.$g"
        # A group that died before writing its count failed.
        gf=$(cat "$DATA/fail.$g" 2>/dev/null || echo 1)
        if [ "$gf" -ne 0 ]; then fail=1; fi
    done
    EXTRA_IDS="$B1 $B2 $B3 $B4 $C1 $C2 $C3 $C4"   # 06_admin removes these too
    source test/smoke/09_sweep.sh               # the tournament sweep, alone
    source test/smoke/06_admin.sh               # admin dashboard, relay caps and hub, config, remote cleanup
else
    source test/smoke/01_core.sh                # landing, version, CORS, hello, scores, backup
    source test/smoke/02_signals_friends.sh     # signals, friends, poll, debug reports, time
    source test/smoke/03_start_duel.sh          # start/epoch, directional isolation, relay duel flow, rematch
    source test/smoke/04_matchmaking.sh         # friend-spam ban, quick match
    source test/smoke/05_items.sh               # item registry: seed, mint, the claim ladder, freezes
    source test/smoke/07_tournament.sh          # tournaments: lobby, schedule, roles, the result ladder
    source test/smoke/08_events.sh              # events: the door, the two codes, the monitor, event tournaments
    source test/smoke/09_sweep.sh               # the tournament sweep, alone: it decides by which request fires it
    source test/smoke/06_admin.sh               # admin dashboard, relay caps and hub, config, remote cleanup
    # 06_admin.sh runs LAST whatever its number: it asserts exact counts and,
    # on a remote run, deletes this run's test data at the end.
fi

if [ "$fail" -ne 0 ]; then
    # A CI log is all anyone gets to look at when this goes red, and the run
    # that failed is gone (throwaway data dir, random port). Hand over what
    # the server itself said rather than leaving a local re-run - which may
    # not reproduce it - as the only way to find out.
    if [ "$REMOTE" -eq 0 ]; then
        for log in server.log php-error.log; do
            if [ -s "$DATA/$log" ]; then
                echo
                echo "== $log (last 40 lines) =="
                tail -n 40 "$DATA/$log"
            fi
        done
    fi
    echo "SMOKE FAILED"
    exit 1
fi
echo "OK"
