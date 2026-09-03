#!/usr/bin/env bash
# Round 3. Rounds 1 and 2 settled the upload: one FTPS upload is ~19
# serialized ~95 ms round trips (the bytes are irrelevant), curl cannot reuse
# an FTP connection so it needs one login per file, this host rate-limits
# logins and 530s curl at 24 ways, and lftp - which does reuse its pooled
# connections - uploads the whole tree at 24 ways in ~11 s, 52/52 verified.
# Its parallel queue writes <name>.tmp siblings, so the deploy's swap phase
# survives unchanged. That is now in tools/deploy.sh.
#
# Timing the result against staging exposed two things the local stub could
# not, and this round measures each rather than guessing:
#
#   J  The sha256 manifest never survives - every run reports "no manifest on
#      the server", so the delta never engages. Suspect: mktemp pre-creates
#      the local file and lftp's xfer:clobber defaults to off, so `get -o`
#      refuses to overwrite it. Tests the suspicion and the two candidate
#      fixes.
#   K  The swap costs ~440 ms per rename, far more than the 2 round trips a
#      RNFR/RNTO pair should be. Suspect: a `mv` naming a path makes lftp
#      change directory each time. Compares paths against one `cd` and bare
#      basenames.
#   L  Both phases currently verify by listing the tree, which costs ~7 s a
#      run - most of a small deploy. If `cmd:fail-exit` reliably turns a bad
#      command into a non-zero exit, the swap needs no listing at all. Tested
#      for a plain command AND for a queued transfer, which is the case that
#      cannot be assumed.
#   M  What that listing actually costs: a recursive find over the tree
#      against a single directory.
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

# --- J: why the manifest never comes back -----------------------------------
log "J. manifest round trip"
src=$(mktemp); echo "manifest-probe-$(date +%s)" > "$src"
lftp_run "put $src -o ${prefix}.htprobe-manifest;" >/dev/null 2>&1
echo "  uploaded, rc=$?"

a=$(mktemp)                      # mktemp pre-creates it: the suspected case
out=$(lftp_run "get ${prefix}.htprobe-manifest -o $a;" 2>&1); rc=$?
printf '  get onto an existing file   rc=%s size=%s  %s\n' \
    "$rc" "$(wc -c < "$a")" "$(echo "$out" | tr '\n' ' ' | cut -c1-60)"

b=$(mktemp); rm -f "$b"          # candidate fix 1: no local file in the way
out=$(lftp_run "get ${prefix}.htprobe-manifest -o $b;" 2>&1); rc=$?
printf '  get with no file in the way rc=%s size=%s  %s\n' \
    "$rc" "$( [ -f "$b" ] && wc -c < "$b" || echo -)" "$(echo "$out" | tr '\n' ' ' | cut -c1-60)"

c=$(mktemp)                      # candidate fix 2: allow the overwrite
out=$(lftp_run "set xfer:clobber on; get ${prefix}.htprobe-manifest -o $c;" 2>&1); rc=$?
printf '  get with xfer:clobber on    rc=%s size=%s  %s\n' \
    "$rc" "$(wc -c < "$c")" "$(echo "$out" | tr '\n' ' ' | cut -c1-60)"
rm -f "$src" "$a" "$b" "$c"

# --- K/L/M need .tmp files to rename ----------------------------------------
mapfile -t API < <(find public/api -maxdepth 1 -type f | sort | head -20)
mapfile -t SRC < <(find public/src -maxdepth 1 -type f | sort | head -20)
put_tmp() {   # $@ = local files -> <name>.tmp beside their remote twin
    local s='set cmd:queue-parallel 24;'$'\n' f
    for f in "$@"; do s+="queue put $f -o $prefix${f#public/}.tmp"$'\n'; done
    lftp_run "$s"'wait all'$'\n' >/dev/null 2>&1
}

# --- K: does a path in mv cost a directory change? --------------------------
log "K. rename cost: a path per mv vs one cd and bare basenames"
put_tmp "${API[@]}" "${SRC[@]}"

s=''; for f in "${API[@]}"; do r="${f#public/}"; s+="mv $prefix$r.tmp $prefix$r"$'\n'; done
t=$(now_ms); lftp_run "$s" >/dev/null 2>&1; rc=$?
n=${#API[@]}; e=$(( $(now_ms) - t ))
printf '  mv with a full path   %6d ms  rc=%s  %d renames  %d ms each\n' "$e" "$rc" "$n" "$((e / n))"

s="cd ${prefix}src"$'\n'; for f in "${SRC[@]}"; do b="${f##*/}"; s+="mv $b.tmp $b"$'\n'; done
t=$(now_ms); lftp_run "$s" >/dev/null 2>&1; rc=$?
n=${#SRC[@]}; e=$(( $(now_ms) - t ))
printf '  cd once, bare names   %6d ms  rc=%s  %d renames  %d ms each\n' "$e" "$rc" "$n" "$((e / n))"

# --- L: can a listing-free gate rely on the exit status? --------------------
log "L. does cmd:fail-exit turn a failure into a non-zero exit?"
lftp_run "set cmd:fail-exit on; mv ${prefix}definitely-not-here.tmp ${prefix}x;" >/dev/null 2>&1
echo "  bad mv, fail-exit on            rc=$?  (want non-zero)"
lftp_run "mv ${prefix}definitely-not-here.tmp ${prefix}x;" >/dev/null 2>&1
echo "  bad mv, fail-exit off           rc=$?"
lftp_run "set cmd:fail-exit on; cd ${prefix}src; mv also-not-here.tmp x;" >/dev/null 2>&1
echo "  bad mv after cd, fail-exit on   rc=$?  (want non-zero)"
# The one that cannot be assumed: a failure inside a QUEUED transfer.
lftp_run "set cmd:fail-exit on; set cmd:queue-parallel 4;
    queue put /nonexistent/nope.txt -o ${prefix}nope.tmp
    wait all" >/dev/null 2>&1
echo "  bad queued put, fail-exit on    rc=$?  (want non-zero)"
# And a mkdir of a directory that already exists, which the deploy issues on
# every run - it must NOT abort the session under fail-exit.
lftp_run "set cmd:fail-exit on; mkdir -f -p ${prefix}src; cd ${prefix}src;" >/dev/null 2>&1
echo "  mkdir -f -p on an existing dir  rc=$?  (want zero)"

# --- M: what the verification listing costs ---------------------------------
log "M. cost of the verification listing"
t=$(now_ms); lftp_run "find $prefix;" >/dev/null 2>&1; echo "  recursive find over the tree  $(( $(now_ms) - t )) ms"
t=$(now_ms); lftp_run "cls -1 ${prefix}src;" >/dev/null 2>&1; echo "  cls -1 of one directory       $(( $(now_ms) - t )) ms"
t=$(now_ms); lftp_run "cls -1 ${prefix}src; cls -1 ${prefix}assets;" >/dev/null 2>&1
echo "  cls -1 of two, one session    $(( $(now_ms) - t )) ms"
t=$(now_ms); lftp_run "quote noop;" >/dev/null 2>&1; echo "  a bare login and nothing else $(( $(now_ms) - t )) ms"

lftp_run "rm -f ${prefix}.htprobe-manifest;" >/dev/null 2>&1
echo
echo "probe done"
