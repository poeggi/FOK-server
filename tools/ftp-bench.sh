#!/usr/bin/env bash
# Times deploy.sh's phase 1 - uploading every public/ file to its <name>.tmp
# sibling - under several strategies against the REAL host, so the upload
# strategy is chosen on measurements rather than on an assumption about what
# the host charges per connection or per transfer.
#
# Run from CI only (.github/workflows/ftp-bench.yml, manual dispatch): the
# numbers that matter are a runner's, not a workstation's, and the runner is
# where the deploy actually happens.
#
# It is phase 1 ONLY and it writes into staging/, so nothing the server
# serves changes: a .tmp sibling is exactly what a real deploy leaves there
# between its two phases, it is never served, and the next staging deploy
# renames every one of them into place. So it needs no cleanup of its own -
# the one strategy that cannot use .tmp names (lftp mirror) gets a scratch
# directory that it removes itself.
set -uo pipefail
cd "$(dirname "$0")/.."

: "${FTP_HOST:?}" "${FTP_USER:?}" "${FTP_PASS:?}"
prefix='staging/'
export FTP_HOST FTP_USER FTP_PASS prefix

mapfile -t FILES < <(find public -type f | sort)
BYTES=$(find public -type f -printf '%s\n' | awk '{s+=$1} END {print s}')
echo "payload: ${#FILES[@]} files, $BYTES bytes"

now_ms() { date +%s%3N; }
RESULTS=()
record() { # $1 = label, $2 = start ms, $3 = rc
    local ms=$(( $(now_ms) - $2 ))
    printf '%-14s %6d ms  rc=%s\n' "$1" "$ms" "$3"
    RESULTS+=("$(printf '%s\t%s\t%s' "$1" "$ms" "$3")")
}

curl_ftp() { curl -sS --ssl-reqd --user "$FTP_USER:$FTP_PASS" "$@"; }
export -f curl_ftp

# --- strategy bodies -------------------------------------------------------

# One curl process per file (the pre-change baseline), N in parallel.
one_per_file() { curl_ftp --ftp-create-dirs -T "$1" "ftp://$FTP_HOST/$prefix${1#public/}.tmp"; }
export -f one_per_file

# One curl process per batch: curl pairs each -T with the URL after it and
# reuses the single control connection for the whole slice.
put_batch() {
    local f args=()
    for f in "$@"; do args+=(-T "$f" "ftp://$FTP_HOST/$prefix${f#public/}.tmp"); done
    curl_ftp --ftp-create-dirs "${args[@]}"
}
export -f put_batch

# Same, but the login is encrypted and the data channel is clear (no per-file
# data TLS handshake). Measured to size the handshake cost; sends file bodies
# in plaintext, so it is a data point, not automatically a recommendation.
put_batch_clear() {
    local f args=()
    for f in "$@"; do args+=(-T "$f" "ftp://$FTP_HOST/$prefix${f#public/}.tmp"); done
    curl -sS --ftp-ssl-control --user "$FTP_USER:$FTP_PASS" --ftp-create-dirs "${args[@]}"
}
export -f put_batch_clear

run_procs() { # $1 = parallelism
    printf '%s\0' "${FILES[@]}" | xargs -0 -r -P "$1" -n 1 bash -c 'one_per_file "$1"' _
}
run_batches() { # $1 = parallelism, $2 = fn
    local chunk=$(( (${#FILES[@]} + $1 - 1) / $1 ))
    printf '%s\0' "${FILES[@]}" | xargs -0 -r -P "$1" -n "$chunk" bash -c "$2 \"\$@\"" _
}
run_single() { put_batch "${FILES[@]}"; }

# --- run -------------------------------------------------------------------

t=$(now_ms); run_procs 6                    ; record procs-p6    $t $?
t=$(now_ms); run_batches 6 put_batch        ; record batch-p6    $t $?
t=$(now_ms); run_single                     ; record single      $t $?
t=$(now_ms); run_procs 12                   ; record procs-p12   $t $?
t=$(now_ms); run_batches 12 put_batch       ; record batch-p12   $t $?
t=$(now_ms); run_batches 24 put_batch       ; record batch-p24   $t $?
t=$(now_ms); run_batches 6 put_batch_clear  ; record clear-p6    $t $?

if command -v lftp >/dev/null; then
    # lftp keeps a pool of logged-in connections and pipelines commands over
    # them - the strongest "one login, many files" candidate. It mirrors real
    # names, so it goes to a scratch dir that is removed right after.
    t=$(now_ms)
    lftp -u "$FTP_USER,$FTP_PASS" "$FTP_HOST" -e "
        set ssl:verify-certificate no; set ftp:ssl-force true; set ftp:ssl-protect-data true;
        set net:timeout 20; set mirror:parallel-transfer-count 6;
        mirror -R --no-perms --parallel=6 public ${prefix}_bench; bye" >/dev/null 2>&1
    record lftp-p6 $t $?
    lftp -u "$FTP_USER,$FTP_PASS" "$FTP_HOST" -e "
        set ssl:verify-certificate no; set ftp:ssl-force true;
        rm -rf ${prefix}_bench; bye" >/dev/null 2>&1 || true
    echo "lftp scratch dir removed"
fi

# --- report ----------------------------------------------------------------
{
    echo "## FTPS phase-1 upload benchmark"
    echo "payload: ${#FILES[@]} files, $BYTES bytes, host \$FTP_HOST, staging prefix"
    echo
    echo "| strategy | ms | rc |"
    echo "|---|---:|---|"
    printf '%s\n' "${RESULTS[@]}" | awk -F'\t' '{printf "| %s | %s | %s |\n", $1, $2, $3}'
} | tee -a "${GITHUB_STEP_SUMMARY:-/dev/null}"
