#!/usr/bin/env bash
# Round 4. What the earlier rounds settled, and now measure against:
#
#   One FTPS upload is ~19 serialized ~95 ms round trips (the bytes are
#   irrelevant), curl cannot reuse an FTP connection so it needs one login per
#   file, this host rate-limits logins and 530s curl at 24 ways, and lftp -
#   which does reuse its pooled connections - uploads the whole tree at 24 ways
#   in ~11 s. A bare login is 2.2 s, a rename ~300 ms, a recursive listing
#   13 s. Round 3 fixed the manifest round trip and dropped both listing gates.
#
# tools/deploy.sh now measures, against staging: 28.6 s for the whole tree,
# 14.5 s for a two-file delta, 3.9 s when nothing changed. The remaining cost
# is no longer the upload, and this round measures the two things it IS:
#
#   N  52 renames cost ~15.6 s - more than the upload they follow - because
#      they run one after another. lftp's queue parallelises transfers; if it
#      parallelises a `mv` too, the swap burst gets shorter as well as faster,
#      which is the direction the whole phase exists to go. Also re-checks
#      that fail-exit still catches a bad rename inside the queue, because the
#      swap is gated on nothing else.
#   O  Three sessions cost ~6.6 s of login before anything is transferred, and
#      a two-file deploy is mostly that. The phases are separate because phase
#      1 cannot be planned until the manifest is read - but lftp can run a
#      shell command mid-session and `source` the file it writes, which would
#      let one login carry read, plan, upload and swap. Tests whether the
#      escape and the source actually work inside an -e script.
#   P  24 ways is where curl started getting 530s; lftp was never pushed past
#      it. Finds the ceiling instead of inheriting curl's.
#
# Run from CI only (.github/workflows/ftp-bench.yml, manual dispatch), against
# staging/. Everything it writes is a .tmp sibling - what a real deploy leaves
# between its phases, never served, renamed away by the next staging deploy -
# or a rename of a file back onto its own identical content.
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

mapfile -t API < <(find public/api -maxdepth 1 -type f | sort | head -20)
mapfile -t SRC < <(find public/src -maxdepth 1 -type f | sort | head -20)
mapfile -t ALL < <(find public -type f | sort)

put_tmp() {   # $@ = local files -> <name>.tmp beside their remote twin
    local s='set cmd:queue-parallel 24;'$'\n' f
    for f in "$@"; do s+="queue put $f -o $prefix${f#public/}.tmp"$'\n'; done
    lftp_run "$s"'wait all'$'\n' >/dev/null 2>&1
}

# --- N: can the swap burst be parallelised? ---------------------------------
log "N. rename: one after another vs through the queue"
put_tmp "${API[@]}" "${SRC[@]}"

s=''; for f in "${API[@]}"; do r="${f#public/}"; s+="mv $prefix$r.tmp $prefix$r"$'\n'; done
t=$(now_ms); lftp_run "$s" >/dev/null 2>&1; rc=$?
n=${#API[@]}; e=$(( $(now_ms) - t ))
printf '  serial mv              %6d ms  rc=%s  %d renames  %d ms each\n' "$e" "$rc" "$n" "$((e / n))"

s='set cmd:queue-parallel 24;'$'\n'
for f in "${SRC[@]}"; do r="${f#public/}"; s+="queue mv $prefix$r.tmp $prefix$r"$'\n'; done
t=$(now_ms); lftp_run "$s"'wait all'$'\n' >/dev/null 2>&1; rc=$?
n=${#SRC[@]}; e=$(( $(now_ms) - t ))
printf '  queued mv, parallel 24 %6d ms  rc=%s  %d renames  %d ms each\n' "$e" "$rc" "$n" "$((e / n))"

# The swap is gated on the exit status alone, so a failure that the queue
# swallows would be worse than a slow swap.
lftp_run "set cmd:fail-exit on; set cmd:queue-parallel 4;
    queue mv ${prefix}definitely-not-here.tmp ${prefix}x
    wait all" >/dev/null 2>&1
echo "  bad queued mv, fail-exit on    rc=$?  (want non-zero)"

# --- O: can one login carry read, plan and act? -----------------------------
log "O. shell escape and source inside one session"
lftp_run "put tools/ftp-probe.sh -o ${prefix}.htprobe-manifest;" >/dev/null 2>&1
g=$(mktemp); rm -f "$g"
plan=$(mktemp); rm -f "$plan"
gen=$(mktemp); cat > "$gen" <<EOF
#!/bin/sh
# Stands in for the deploy's planner: reads what the get just fetched and
# writes the commands that depend on it.
printf 'cls -1 %ssrc\n' "$prefix" > $plan
wc -l < $g > $plan.saw
EOF
chmod +x "$gen"

t=$(now_ms)
out=$(lftp_run "set cmd:fail-exit on;
    get ${prefix}.htprobe-manifest -o $g;
    !$gen;
    source $plan;" 2>&1); rc=$?
e=$(( $(now_ms) - t ))
printf '  one session: get + shell + source  %6d ms  rc=%s\n' "$e" "$rc"
printf '    the shell escape ran        %s\n' "$( [ -s "$plan.saw" ] && echo "yes, it read $(cat "$plan.saw") lines" || echo 'NO' )"
printf '    the sourced command ran     %s\n' "$(echo "$out" | grep -qE 'Config\.php|Db\.php' && echo yes || echo NO)"
printf '    (output: %s)\n' "$(echo "$out" | tr '\n' ' ' | cut -c1-70)"
rm -f "$g" "$plan" "$plan.saw" "$gen"

# --- P: where is the host's login ceiling? ----------------------------------
log "P. upload parallelism ceiling (${#ALL[@]} files)"
for par in 24 32 48; do
    s="set cmd:queue-parallel $par;"$'\n'
    for f in "${ALL[@]}"; do s+="queue put $f -o $prefix${f#public/}.tmp"$'\n'; done
    t=$(now_ms); out=$(lftp_run "$s"'wait all'$'\n' 2>&1); rc=$?
    e=$(( $(now_ms) - t ))
    printf '  parallel %-2s  %6d ms  rc=%s  530s=%s  errors=%s\n' "$par" "$e" "$rc" \
        "$(echo "$out" | grep -c '530')" "$(echo "$out" | grep -ci 'error\|denied\|refused')"
done

lftp_run "rm -f ${prefix}.htprobe-manifest;" >/dev/null 2>&1
echo
echo "probe done"
