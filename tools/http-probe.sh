#!/usr/bin/env bash
# Where the remote smoke's time goes, measured from a runner against the real
# host - the HTTP counterpart to tools/ftp-probe.sh.
#
# What is already known: the suite is 386 curl processes run one after another,
# and against staging it takes 183 s direct and 86 s through
# test/smoke/keepalive-proxy.py, all assertions green either way. Roughly 13 s
# of that is deliberate (two 2 s long-poll timeouts and the throttle sleeps),
# so the direct run spends ~474 ms per request and the tunnelled run ~223 ms.
#
# 223 ms is more than one round trip plus the ~25 ms of work the endpoint does,
# so something between curl and the host is still costing real time. This
# separates the three candidates instead of guessing which:
#
#   A  fresh process, fresh TLS, direct        - what the suite used to do
#   B  fresh process, through the tunnel       - what the suite does now
#   C  ONE curl process, N urls, direct        - curl reusing its own
#                                                connection: the floor B is
#                                                trying to reach, with no
#                                                tunnel in the path at all
#   D  ONE curl process, N urls, tunnelled     - the same floor WITH the
#                                                tunnel, so C-D is the
#                                                tunnel's own overhead
#   E  N processes, no request at all          - what merely starting curl
#                                                386 times costs
#
# B-C is what the tunnel still leaves on the table; C-E is the network floor a
# serial suite cannot go below without running requests concurrently.
set -uo pipefail
cd "$(dirname "$0")/.."

: "${SMOKE_BASE:?set SMOKE_BASE to the deployed instance to probe}"
N="${HTTP_PROBE_N:-40}"
BASE="${SMOKE_BASE%/}"
EP="/api/version.php"

now_ms() { date +%s%3N; }
report() { printf '  %-42s %6d ms  %4d ms each\n' "$1" "$2" "$((${2} / N))"; }

up="${BASE#*://}"; origin="${BASE%%://*}://${up%%/*}"
path=''; [ "${up#*/}" != "$up" ] && path="/${up#*/}"

port_file=$(mktemp)
python3 test/smoke/keepalive-proxy.py "$origin" > "$port_file" 2>/dev/null &
proxy=$!
trap 'kill $proxy 2>/dev/null; rm -f "$port_file"' EXIT
for _ in $(seq 50); do port=$(head -1 "$port_file"); [ -n "$port" ] && break; sleep 0.1; done
: "${port:?the keep-alive tunnel did not start}"
TUN="http://127.0.0.1:$port$path"

printf '\n===== %s requests to %s =====\n' "$N" "$EP"

t=$(now_ms); for _ in $(seq $N); do curl -s -o /dev/null "$BASE$EP"; done
report "A  fresh process, fresh TLS, direct" $(( $(now_ms) - t ))

t=$(now_ms); for _ in $(seq $N); do curl -s -o /dev/null "$TUN$EP"; done
report "B  fresh process, through the tunnel" $(( $(now_ms) - t ))

# One -o per url: with several urls curl applies a single -o to the FIRST
# only and writes the rest to stdout.
urls=''; for _ in $(seq $N); do urls+=" -o /dev/null $BASE$EP"; done
t=$(now_ms); curl -s $urls
report "C  one process reusing its connection" $(( $(now_ms) - t ))

urls=''; for _ in $(seq $N); do urls+=" -o /dev/null $TUN$EP"; done
t=$(now_ms); curl -s $urls
report "D  one process, tunnelled" $(( $(now_ms) - t ))

t=$(now_ms); for _ in $(seq $N); do curl -s --version >/dev/null; done
report "E  starting curl, no request at all" $(( $(now_ms) - t ))

echo
echo "probe done"
