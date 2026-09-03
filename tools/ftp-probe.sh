#!/usr/bin/env bash
# Round 2. Round 1 answered why the upload is slow and what the ceiling is:
#
#   One upload is ~19 SERIALIZED round trips of ~95 ms - TCP, a 304 ms
#   greeting, AUTH SSL, control TLS, USER, PASS, PBSZ, PROT, PWD, CWD, EPSV,
#   data connect, TYPE, STOR, 226 - so ~2.0 s per file of which the bytes are
#   nothing. None of the usual suspects was to blame: --disable-epsv, --ipv4,
#   --ftp-skip-pasv-ip, --tcp-nodelay, TLS 1.2 and a clear data channel all
#   measured within noise of the baseline (2071-2162 ms/file).
#
#   Two things the deploy's own comments asserted turned out to be false. The
#   data channel does NOT renegotiate TLS - the trace says "SSL reusing
#   session ID". And curl cannot reuse an FTP connection at all: asked for
#   three files in one invocation it opened connections #0, #1 and #2, each
#   with its own USER/PASS, so batching never saved a login.
#
#   That is what caps curl. It needs one login per file, the host rate-limits
#   logins, and at 24-way curl trips it (rc=123, 530 Access denied) while
#   lftp - which really does reuse its pooled connections, so 52 files cost
#   ~12-24 logins instead of 52 - runs clean at the same width and is the
#   fastest measured: 8.4 s against curl's 14.1 s at 12-way.
#
# So the upload should be lftp's. What is NOT yet measured is how to keep the
# deploy's atomicity while it is: the swap depends on uploading beside the
# live file and renaming over it, and lftp's mirror writes real names. Two
# mechanisms could preserve it, and this measures both rather than picking the
# one that reads better:
#
#   H  lftp's parallel queue running explicit puts to <name>.tmp - keeps the
#      current layout and the whole swap phase byte for byte, but relies on
#      queue semantics this repo has never exercised.
#   I  mirror into a scratch directory (the shape already measured at 8.4 s)
#      and rename ACROSS directories into place - keeps mirror, but needs the
#      host to accept a pathname in RNFR/RNTO rather than a bare basename.
#
# Both are verified by listing what actually landed, not by trusting rc.
#
# Run from CI only (.github/workflows/ftp-bench.yml, manual dispatch), against
# staging/. Everything it writes is either a .tmp sibling (what a real deploy
# leaves between its phases, never served, renamed away by the next staging
# deploy) or a scratch directory it removes itself.
set -uo pipefail
cd "$(dirname "$0")/.."

: "${FTP_HOST:?}" "${FTP_USER:?}" "${FTP_PASS:?}"
prefix='staging/'
export FTP_HOST FTP_USER FTP_PASS prefix

mapfile -t FILES < <(find public -type f | sort)
NFILES=${#FILES[@]}
echo "payload: $NFILES files, $(find public -type f -printf '%s\n' | awk '{s+=$1} END {print s}') bytes"

now_ms() { date +%s%3N; }
log() { printf '\n===== %s =====\n' "$*"; }
lftp_run() { lftp -u "$FTP_USER,$FTP_PASS" "$FTP_HOST" -e "
    set ssl:verify-certificate no; set ftp:ssl-force true;
    set ftp:ssl-protect-data true; set net:timeout 20;
    $1
    bye"; }

# Counts what actually arrived, so a mechanism cannot pass on rc alone.
count_remote() { # $1 = path under the webroot, $2 = suffix to match
    lftp_run "find $1;" 2>/dev/null | grep -c "$2\$"
}

# --- H: lftp's parallel queue, explicit puts to .tmp -------------------------
log "H. lftp queue, explicit puts to <name>.tmp"
for n in 12 24; do
    script="set cmd:queue-parallel $n;"$'\n'
    while read -r d; do script+="mkdir -f -p $prefix$d"$'\n'; done \
        < <(find public -mindepth 1 -type d -printf '%P\n')
    for f in "${FILES[@]}"; do script+="queue put $f -o $prefix${f#public/}.tmp"$'\n'; done
    script+="wait all"$'\n'
    t=$(now_ms); lftp_run "$script" >/dev/null 2>&1; rc=$?
    printf 'lftp-queue-p%-3s %6d ms  rc=%s  landed=%s/%s\n' \
        "$n" $(( $(now_ms) - t )) "$rc" "$(count_remote "$prefix" '\.tmp')" "$NFILES"
done

# --- I: mirror to a scratch dir, then rename across directories -------------
log "I. mirror to a scratch dir + cross-directory rename"
# The carrier URL makes curl CWD into the deploy prefix, so every path the
# rename names is relative to THAT, not to the FTP root.
scratch_rel='_probe_new'
scratch="$prefix$scratch_rel"
t=$(now_ms)
lftp_run "mirror -R --no-perms --parallel=24 public $scratch;" >/dev/null 2>&1; rc=$?
printf 'mirror-p24  %6d ms  rc=%s  landed=%s/%s\n' \
    $(( $(now_ms) - t )) "$rc" "$(count_remote "$scratch" '')" "$NFILES"

# The swap needs RNFR/RNTO to accept a pathname, not just a basename in the
# current directory. Tried on one file per directory shape the tree has.
echo "cross-directory rename (RNFR/RNTO with a pathname):"
probed=()
for rel in src/Config.php assets/admin.js api/hello.php; do
    out=$(curl -sS --ssl-reqd --user "$FTP_USER:$FTP_PASS" -o /dev/null \
        "ftp://$FTP_HOST/$prefix" \
        -Q "-RNFR $scratch_rel/$rel" -Q "-RNTO $rel.probed" 2>&1)
    printf '  %-20s %s\n' "$rel" "${out:-OK}"
    probed+=("$prefix$rel.probed")
done
echo "left in the scratch dir: $(count_remote "$scratch" '') of $NFILES"

lftp_run "$(printf 'rm -f %s\n' "${probed[@]}") rm -rf $scratch;" >/dev/null 2>&1 || true
echo "scratch and probed files removed"

echo
echo "probe done"
