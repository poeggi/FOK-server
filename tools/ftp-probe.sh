#!/usr/bin/env bash
# Round 6. What the earlier rounds settled:
#
#   An upload is ~19 serialized ~95 ms round trips, curl needs one login per
#   file and gets 530d at 24 ways, lftp pools connections and does the whole
#   tree at 24 ways in ~7.3 s - and 32 and 48 ways are no faster, so for
#   UPLOADS 24 is not a ceiling, it is past the knee. A bare login is 2.2 s. A
#   serial rename is ~337 ms of wire time; the same rename through the queue at
#   24 ways is ~80 ms, and fail-exit still returns non-zero for a bad one. A
#   recursive listing is 13 s, which is why nothing lists any more. lftp will
#   run a shell command mid-session and `source` the file it writes, so the
#   whole deploy is now ONE login: get the manifest, plan from it in a shell
#   escape, source the plan. A failure before `set cmd:fail-exit on` does not
#   poison the exit code, and fail-exit does reach inside a sourced file - both
#   measured, and the deploy depends on both. `mkdir -f -p` over an existing
#   directory costs 166 ms of wire, which is why the manifest is asked first.
#
# Which leaves the swap. A full deploy is now ~16.4 s: ~2.2 s login, ~7.3 s
# upload, and the rest renaming. That last part is the only reducible half
# left, and 24 ways was chosen for it only because 24 ways was right for the
# uploads - which is not an argument, because the two are limited by different
# things. An upload moves bytes; a rename is a single short command whose cost
# is almost entirely waiting for the host to answer. Latency-bound work keeps
# scaling with concurrency long after bandwidth-bound work has stopped.
#
#   T  What 52 renames actually cost at 16, 24, 48 and 64 ways. If the curve
#      is still falling at 64 the deploy is leaving seconds on the table; if
#      it is flat past 24, the swap is as fast as it gets and the remaining
#      time is the host's, not the plan's.
#   U  Whether the `wait all` barrier between dependency tiers is worth what
#      it costs: the same 52 renames as ONE queue with no barriers, against
#      the same renames split into the 12 tiers a real deploy uses.
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

# The bed: one .probe-N.a per file in the tree, uploaded once. Every pass below
# renames the whole set from .a to .b or back, so each pass is exactly the 52
# renames a cold deploy does, and the set is in the right state for the next
# one without any cleanup in between.
mapfile -t FILES < <(cd public && find . -type f -printf '%P\n' | sort)
N=${#FILES[@]}
log "bed: $N files"
s='set cmd:queue-parallel 24'$'\n'
for i in $(seq 0 $((N - 1))); do
    s+="queue put public/${FILES[$i]} -o ${prefix}.probe-$i.a"$'\n'
done
s+='wait all'$'\n'
t=$(now_ms); lftp_run "$s" >/dev/null 2>&1; echo "  uploaded in $(( $(now_ms) - t )) ms"

# --- T: does the rename queue keep scaling past 24? -------------------------
log "T. 52 renames, one queue, no barriers"
from=a; to=b
for par in 16 24 48 64; do
    s="set cmd:queue-parallel $par"$'\n'
    for i in $(seq 0 $((N - 1))); do
        s+="queue mv ${prefix}.probe-$i.$from ${prefix}.probe-$i.$to"$'\n'
    done
    s+='wait all'$'\n'
    t=$(now_ms); lftp_run "$s" >/dev/null 2>&1; e=$(( $(now_ms) - t ))
    printf '  %2d ways %6d ms  %3d ms each\n' "$par" "$e" "$(( e / N ))"
    [ "$from" = a ] && { from=b; to=a; } || { from=a; to=b; }
done

# --- U: what the dependency barriers cost -----------------------------------
# 12 tiers is what a real full deploy emits, so the split is the real one:
# the same renames, cut into 12 groups with a `wait all` between them.
log "U. the same renames, cut into 12 tiers with a barrier between each"
step=$(( N / 12 )); [ "$step" -lt 1 ] && step=1
for par in 24 48; do
    s="set cmd:queue-parallel $par"$'\n'
    for i in $(seq 0 $((N - 1))); do
        s+="queue mv ${prefix}.probe-$i.$from ${prefix}.probe-$i.$to"$'\n'
        [ $(( (i + 1) % step )) -eq 0 ] && s+='wait all'$'\n'
    done
    s+='wait all'$'\n'
    t=$(now_ms); lftp_run "$s" >/dev/null 2>&1; e=$(( $(now_ms) - t ))
    printf '  %2d ways %6d ms  %3d ms each  (12 barriers)\n' "$par" "$e" "$(( e / N ))"
    [ "$from" = a ] && { from=b; to=a; } || { from=a; to=b; }
done

# The bed is left behind as .probe-N.<a|b> siblings, never served. Remove it so
# the next probe run starts from a clean tree.
log "cleanup"
s='set cmd:queue-parallel 24'$'\n'
for i in $(seq 0 $((N - 1))); do s+="queue rm -f ${prefix}.probe-$i.$from"$'\n'; done
s+='wait all'$'\n'
t=$(now_ms); lftp_run "$s" >/dev/null 2>&1; echo "  removed in $(( $(now_ms) - t )) ms"

echo
echo "probe done"
