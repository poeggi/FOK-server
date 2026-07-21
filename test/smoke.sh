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
source test/smoke/05_admin.sh               # admin dashboard, relay caps/transport, config, remote cleanup

if [ "$fail" -ne 0 ]; then
    echo "SMOKE FAILED"
    exit 1
fi
echo "OK"
