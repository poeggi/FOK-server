#!/usr/bin/env bash
# Round 7. What the earlier rounds settled:
#
#   An upload is ~19 serialized ~95 ms round trips, curl needs one login per
#   file and gets 530d at 24 ways, lftp pools connections and does the whole
#   tree at 24 ways in ~7.3 s. A bare login is 2.2 s. A recursive listing is
#   13 s, which is why nothing lists any more. lftp will run a shell command
#   mid-session and `source` the file it writes, so the whole deploy is now ONE
#   login: get the manifest, plan from it in a shell escape, source the plan. A
#   failure before `set cmd:fail-exit on` does not poison the exit code, and
#   fail-exit does reach inside a sourced file - the deploy depends on both.
#   `mkdir -f -p` over an existing directory costs 166 ms, which is why the
#   manifest is asked first. And the swap does NOT keep scaling: 52 renames
#   took 4284/6349/6108/4144 ms at 16/24/48/64 ways - no trend, just ~50% noise
#   around ~5 s, so 16 ways is already everything there is. The dependency
#   barriers cost ~1.5-3 s on top of that, which is what they are worth paying.
#
# Which leaves the one cost every round has measured and none has attacked:
# ~19 round trips to put ONE file, ~1.4 s of latency each. Parallelism hides it
# on a cold deploy (52 files in ~9 s) but not on a real one - the deploy CI
# actually runs changes two or three files and spends ~2.9 s of its 7.5 s
# uploading them. Nothing about the plan can fix that; it is what lftp says on
# the wire per file, and some of it is optional:
#
#   V  Which per-file commands this lftp actually sends, and what dropping the
#      pointless ones is worth. SIZE and MDTM ask about a file the deploy KNOWS
#      is not there - it uploads to a fresh .tmp name every time. ALLO asks
#      permission to write bytes the host never refuses. And the data channel
#      does its own TLS handshake per transfer, which session reuse can turn
#      into an abbreviated one.
#
# The settings are dumped from the running lftp first, rather than guessed at
# from memory: a misspelled variable is silently a no-op, which would look
# exactly like "the setting did not help".
#
# Run from CI only (.github/workflows/ftp-bench.yml, manual dispatch), against
# staging/. Everything it writes is a .tmp sibling - what a real deploy leaves
# between its phases, never served, renamed away by the next staging deploy.
set -uo pipefail
cd "$(dirname "$0")/.."

: "${FTP_HOST:?}" "${FTP_USER:?}" "${FTP_PASS:?}"
prefix='staging/'

now_ms() { date +%s%3N; }
log() { printf '\n===== %s =====\n' "$*"; }
lftp_run() { lftp -u "$FTP_USER,$FTP_PASS" "$FTP_HOST" -e "
    set ssl:verify-certificate no; set ftp:ssl-force true;
    set ftp:ssl-protect-data true; set net:timeout 20;
    $1
    bye"; }

log "what this lftp calls its per-file settings"
lftp -c 'set -a' | grep -E '^set (ftp:(use-|ssl-)|xfer:)' | sed 's/^/  /'

# One real file, uploaded SERIALLY, so what is being measured is the per-file
# conversation and not how well 24 of them overlap. The bare login is measured
# in the same session shape and subtracted.
F=public/api/version.php
REP=8

log "V. what one upload costs, and what each setting is worth"
t=$(now_ms); lftp_run "quote noop;" >/dev/null 2>&1; login=$(( $(now_ms) - t ))
echo "  bare login (subtracted below): $login ms"

variant() { # variant <name> <settings>
    local s="$2"$'\n' i
    s+='set cmd:queue-parallel 1'$'\n'
    for i in $(seq "$REP"); do
        s+="queue put $F -o ${prefix}.probe-v$i.tmp"$'\n'
    done
    s+='wait all'$'\n'
    local t e; t=$(now_ms)
    lftp_run "$s" >/dev/null 2>&1
    e=$(( $(now_ms) - t - login ))
    printf '  %-34s %6d ms  %4d ms per file\n' "$1" "$e" "$(( e / REP ))"
}

variant "base, as the deploy runs today" ""
variant "no SIZE/MDTM"                   "set ftp:use-size no; set ftp:use-mdtm no"
variant "no ALLO"                        "set ftp:use-allo no"
variant "data-channel TLS session reuse" "set ftp:ssl-data-use-keys yes"
variant "all of the above"               "set ftp:use-size no; set ftp:use-mdtm no;
                                          set ftp:use-allo no; set ftp:ssl-data-use-keys yes"

# The same comparison at the parallelism a cold deploy uses: a per-file saving
# that disappears once 24 conversations overlap is not worth taking.
log "the same, at 24 ways over the whole tree"
mapfile -t FILES < <(cd public && find . -type f -printf '%P\n' | sort)
tree() { # tree <name> <settings>
    local s="$2"$'\n' i
    s+='set cmd:queue-parallel 24'$'\n'
    for i in $(seq 0 $(( ${#FILES[@]} - 1 ))); do
        s+="queue put public/${FILES[$i]} -o ${prefix}.probe-t$i.tmp"$'\n'
    done
    s+='wait all'$'\n'
    local t; t=$(now_ms)
    lftp_run "$s" >/dev/null 2>&1
    printf '  %-34s %6d ms  (%d files)\n' "$1" "$(( $(now_ms) - t ))" "${#FILES[@]}"
}
tree "base"             ""
tree "all of the above" "set ftp:use-size no; set ftp:use-mdtm no;
                         set ftp:use-allo no; set ftp:ssl-data-use-keys yes"

log "cleanup"
s='set cmd:queue-parallel 24'$'\n'
for i in $(seq "$REP"); do s+="queue rm -f ${prefix}.probe-v$i.tmp"$'\n'; done
for i in $(seq 0 $(( ${#FILES[@]} - 1 ))); do s+="queue rm -f ${prefix}.probe-t$i.tmp"$'\n'; done
s+='wait all'$'\n'
t=$(now_ms); lftp_run "$s" >/dev/null 2>&1; echo "  removed in $(( $(now_ms) - t )) ms"

echo
echo "probe done"
