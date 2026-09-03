#!/usr/bin/env bash
# Explains WHERE a deploy upload spends its seconds, instead of shopping for a
# strategy that happens to be less slow. tools/ftp-bench.sh measured the cost;
# this one takes it apart.
#
# The arithmetic that motivates it, from the bench run against this host:
#   one session, 52 files serial   131658 ms  -> 2532 ms per file
#   52 logins, 6 at a time          31961 ms  -> 3551 ms per file (9 per worker)
#   52 logins, 12 at a time         17858 ms  -> 3572 ms per file (5 per worker)
# The payload is 360 KB total, so ~0 of that is the bytes. Per file there is a
# ~1030 ms login and a ~2530 ms transfer, both pure latency, and doubling the
# workers divided the wall time by 1.79 - no server-side ceiling anywhere near
# 12. So this asks two questions the bench could not:
#   1. what are the 2530 ms actually made of (sections A, B, C)?
#   2. why does a REUSED session 530 on its second transfer (section D)?
#   3. does per-file concurrency keep scaling to a single wave (E, F, G)?
#
# Run from CI only (.github/workflows/ftp-bench.yml, manual dispatch) and
# against staging/: a .tmp sibling is exactly what a real deploy leaves there
# between its phases, it is never served, and the next staging deploy renames
# them away - so this needs no cleanup of its own.
set -uo pipefail
cd "$(dirname "$0")/.."

: "${FTP_HOST:?}" "${FTP_USER:?}" "${FTP_PASS:?}"
prefix='staging/'
export FTP_HOST FTP_USER FTP_PASS prefix

mapfile -t FILES < <(find public -type f | sort)
echo "payload: ${#FILES[@]} files, $(find public -type f -printf '%s\n' | awk '{s+=$1} END {print s}') bytes"

now_ms() { date +%s%3N; }
log() { printf '\n===== %s =====\n' "$*"; }
# The trace prints the login verbatim. GitHub masks registered secrets, but do
# not rely on that being the only thing between a password and a public log.
scrub() { sed -e "s/$FTP_PASS/<pass>/g" -e "s/$FTP_USER/<user>/g" -e 's/\(PASS \).*/\1<pass>/'; }

url() { printf 'ftp://%s/%s%s.tmp' "$FTP_HOST" "$prefix" "${1#public/}"; }
one_per_file() { curl -sS --ssl-reqd --user "$FTP_USER:$FTP_PASS" --ftp-create-dirs \
    -T "$1" "$(url "$1")" -o /dev/null; }
export -f one_per_file url

# --- A: split one upload into its phases -----------------------------------
# curl's timers are cumulative from the start of the request, so the phase
# costs are the differences: appconnect-connect is the control TLS handshake,
# pretransfer-appconnect is login + CWD + PASV/EPSV + the DATA connection and
# its own TLS, and total-pretransfer is the bytes plus the closing 226.
log "A. phases of one upload (seconds, cumulative)"
printf '%-26s %9s %9s %9s %9s %9s\n' file dns tcp ctl-tls pre-xfer total
for f in "${FILES[@]:0:6}"; do
    printf '%-26s ' "${f#public/}"
    curl -sS --ssl-reqd --user "$FTP_USER:$FTP_PASS" --ftp-create-dirs \
        -T "$f" "$(url "$f")" -o /dev/null \
        -w '%{time_namelookup} %{time_connect} %{time_appconnect} %{time_pretransfer} %{time_total}\n' \
        || echo " FAILED"
done

# --- B: the protocol conversation, timestamped ------------------------------
log "B. timestamped protocol trace of ONE upload (read the gaps)"
curl -v --trace-time --ssl-reqd --user "$FTP_USER:$FTP_PASS" --ftp-create-dirs \
    -T "${FILES[0]}" "$(url "${FILES[0]}")" -o /dev/null 2>&1 | scrub

# --- C: does a flag remove the stall? ---------------------------------------
# Each candidate is a specific suspect for a multi-second per-transfer floor:
# an EPSV that the host answers but whose port is unreachable (curl falls back
# to PASV only after waiting), an AAAA the runner cannot route, a PASV reply
# advertising an address curl should ignore, Nagle interacting with delayed
# ACK, a TLS 1.3 negotiation quirk, and a data channel that need not be
# encrypted once the login was.
log "C. per-file cost under each suspect flag (6 files, serial)"
variant() {
    local label="$1"; shift
    local t f rc=0
    t=$(now_ms)
    for f in "${FILES[@]:0:6}"; do
        curl -sS --ssl-reqd --user "$FTP_USER:$FTP_PASS" --ftp-create-dirs "$@" \
            -T "$f" "$(url "$f")" -o /dev/null || rc=$?
    done
    printf '%-24s %6d ms/file  rc=%s\n' "$label" $(( ($(now_ms) - t) / 6 )) "$rc"
}
variant baseline
variant disable-epsv   --disable-epsv
variant ipv4           --ipv4
variant skip-pasv-ip   --ftp-skip-pasv-ip
variant tcp-nodelay    --tcp-nodelay
variant tls1.2         --tlsv1.2 --tls-max 1.2
variant clear-data     --ftp-ssl-control

# --- D: why does a reused session fail? -------------------------------------
# The bench's batched variants all died with 530 on their later transfers, yet
# the single serial session pushed all 52 fine. Three files in one session,
# traced, says which command the server actually rejects.
log "D. three files in ONE session, traced (the 530 case)"
dargs=()
for f in "${FILES[@]:0:3}"; do dargs+=(-T "$f" "$(url "$f")" -o /dev/null); done
curl -v --trace-time --ssl-reqd --user "$FTP_USER:$FTP_PASS" --ftp-create-dirs \
    "${dargs[@]}" 2>&1 | scrub

# --- E: does per-file concurrency scale to one wave? ------------------------
# The bench never took the WORKING per-file path above 12. If the cost really
# is per-file latency and the host sets no low ceiling, 52 workers means every
# file is in flight at once and the whole upload costs what one file costs.
# Ramped, and it stops at the first level that errors, so a host limit is
# found rather than hammered.
log "E. per-file uploads, ramping the parallelism"
err=$(mktemp)
for p in 12 24 36 52; do
    t=$(now_ms)
    printf '%s\0' "${FILES[@]}" | xargs -0 -r -P "$p" -n 1 bash -c 'one_per_file "$1"' _ 2>"$err"
    rc=$?
    printf 'procs-p%-3s %6d ms  rc=%s  errors=%s\n' \
        "$p" $(( $(now_ms) - t )) "$rc" "$(grep -c . "$err" || true)"
    if [ "$rc" != 0 ]; then
        echo "  ceiling found at $p, first errors:"
        head -3 "$err" | scrub | sed 's/^/    /'
        break
    fi
done

# --- F: one process, many connections ---------------------------------------
# curl's own parallel engine over all 52 pairs: same concurrency as E without
# 52 process spawns, and unlike the bench's batching it gives each transfer
# its own connection rather than reusing one.
log "F. curl --parallel (one process, its own connection pool)"
pargs=()
for f in "${FILES[@]}"; do pargs+=(-T "$f" "$(url "$f")" -o /dev/null); done
for pm in 24 52; do
    t=$(now_ms)
    curl -sS --parallel --parallel-max "$pm" --ssl-reqd --user "$FTP_USER:$FTP_PASS" \
        --ftp-create-dirs "${pargs[@]}" 2>"$err"
    rc=$?
    printf 'curl-parallel-%-3s %6d ms  rc=%s  errors=%s\n' \
        "$pm" $(( $(now_ms) - t )) "$rc" "$(grep -c . "$err" || true)"
    [ "$rc" = 0 ] || { head -3 "$err" | scrub | sed 's/^/    /'; break; }
done

# --- G: lftp above 6 --------------------------------------------------------
# lftp beat curl at HALF the parallelism in the bench (14.2 s at 6 vs 17.9 s at
# 12), so its pool is worth pushing. It mirrors real names, hence a scratch
# directory it removes itself.
if command -v lftp >/dev/null; then
    log "G. lftp mirror, higher parallelism"
    for n in 12 24; do
        t=$(now_ms)
        lftp -u "$FTP_USER,$FTP_PASS" "$FTP_HOST" -e "
            set ssl:verify-certificate no; set ftp:ssl-force true;
            set ftp:ssl-protect-data true; set net:timeout 20;
            set mirror:parallel-transfer-count $n;
            mirror -R --no-perms --parallel=$n public ${prefix}_probe$n; bye" >/dev/null 2>&1
        printf 'lftp-p%-3s %6d ms  rc=%s\n' "$n" $(( $(now_ms) - t )) "$?"
        lftp -u "$FTP_USER,$FTP_PASS" "$FTP_HOST" -e "
            set ssl:verify-certificate no; set ftp:ssl-force true;
            rm -rf ${prefix}_probe$n; bye" >/dev/null 2>&1 || true
    done
fi

rm -f "$err"
echo
echo "probe done"
