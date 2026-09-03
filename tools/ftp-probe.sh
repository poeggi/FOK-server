#!/usr/bin/env bash
# Round 5. What the earlier rounds settled:
#
#   An upload is ~19 serialized ~95 ms round trips, curl needs one login per
#   file and gets 530d at 24 ways, lftp pools connections and does the whole
#   tree at 24 ways in ~7.3 s - and 32 and 48 ways are no faster, so 24 is not
#   a ceiling, it is past the knee. A bare login is 2.2 s. A serial rename is
#   ~337 ms of wire time; the SAME rename through the queue at 24 ways is
#   ~80 ms, and fail-exit still returns non-zero for a bad one. A recursive
#   listing is 13 s, which is why nothing lists any more. And lftp will run a
#   shell command mid-session and `source` the file it writes - measured, it
#   read the manifest the `get` had just fetched and ran the commands the
#   escape generated from it.
#
# Together those say the three sessions can become one: get the manifest, plan
# from it in a shell escape, source the plan. That is ~4.4 s of login gone from
# every deploy and ~13 s of serial renaming gone from a full one. Two things
# have to hold first, and neither is safe to assume, because the whole deploy
# is gated on exit status alone:
#
#   Q  On the first deploy ever there is no manifest to get, so the get has to
#      be allowed to fail. Does a failure BEFORE `set cmd:fail-exit on` still
#      poison the exit code after every later command succeeded? If it does,
#      a successful first deploy would report failure.
#   R  Does fail-exit reach INSIDE a sourced file - non-zero exit, and the
#      commands after the failure not run? The plan is sourced, so if it does
#      not, a failed upload could be followed by its own rename.
#   S  What the per-run `mkdir -f -p` of directories that already exist costs,
#      since the manifest already says which ones do.
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

# --- Q: can a deliberately-allowed failure be un-done? ----------------------
log "Q. does a failure before fail-exit poison the exit code?"
g=$(mktemp); rm -f "$g"
lftp_run "get ${prefix}.htnothing-here -o $g;
    set cmd:fail-exit on;
    cls -1 ${prefix}src;" >/dev/null 2>&1
echo "  failed get, then fail-exit on, then a good command  rc=$?  (want zero)"
# The control: the same shape with nothing failing at all.
lftp_run "set cmd:fail-exit on; cls -1 ${prefix}src;" >/dev/null 2>&1
echo "  nothing failing at all                              rc=$?  (want zero)"
rm -f "$g"

# --- R: does fail-exit reach inside a sourced plan? -------------------------
log "R. fail-exit inside a sourced file"
plan=$(mktemp); marker=$(mktemp); rm -f "$marker"
cat > "$plan" <<EOF
set cmd:queue-parallel 4
queue put /nonexistent/nope.txt -o ${prefix}nope.tmp
wait all
!touch $marker
EOF
lftp_run "set cmd:fail-exit on; source $plan;" >/dev/null 2>&1
echo "  sourced plan with a failing queued put   rc=$?  (want non-zero)"
echo "  the command after the failure ran        $( [ -e "$marker" ] && echo 'YES - fail-exit does NOT reach inside' || echo 'no, as wanted' )"
rm -f "$plan" "$marker"

# --- S: what the per-run mkdir of existing directories costs ----------------
log "S. mkdir -f -p over directories that already exist"
mapfile -t DIRS < <(find public -mindepth 1 -type d -printf '%P\n' | sort)
s=''; for d in "${DIRS[@]}"; do s+="mkdir -f -p $prefix$d"$'\n'; done
t=$(now_ms); lftp_run "$s" >/dev/null 2>&1; e=$(( $(now_ms) - t ))
t2=$(now_ms); lftp_run "quote noop;" >/dev/null 2>&1; login=$(( $(now_ms) - t2 ))
printf '  %d mkdirs %6d ms, bare login %d ms -> %d ms of wire each\n' \
    "${#DIRS[@]}" "$e" "$login" "$(( (e - login) / ${#DIRS[@]} ))"

echo
echo "probe done"
