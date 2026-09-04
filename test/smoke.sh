#!/usr/bin/env bash
# Smoke test over real HTTP - the runner. lib.sh sets up ONE server (or targets
# a remote one) and the shared helpers; each part below runs its feature area
# IN ORDER against that single shared instance - state set up early (a
# friendship, registered players) is reused later, so the order is
# load-bearing. The parts are plain slices of what was one long file, split
# only for length. See test/smoke/lib.sh for local vs remote (SMOKE_BASE) mode.
set -euo pipefail
cd "$(dirname "$0")/.."

source test/smoke/lib.sh                    # env detect, server boot, helpers, admin login
source test/smoke/01_core.sh                # landing, version, CORS, hello, scores, backup
source test/smoke/02_signals_friends.sh     # signals, friends, poll, debug reports, time
source test/smoke/03_start_duel.sh          # start/epoch, directional isolation, relay duel flow, rematch
source test/smoke/04_matchmaking.sh         # friend-spam ban, quick match
source test/smoke/05_items.sh               # item registry: seed, mint, the claim ladder, freezes
source test/smoke/07_tournament.sh          # tournaments: lobby, schedule, roles, the result ladder
source test/smoke/06_admin.sh               # admin dashboard, relay caps/transport, config, remote cleanup
# 06_admin.sh runs LAST whatever its number: it asserts exact counts and, on a
# remote run, deletes this run's test data at the end.

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
