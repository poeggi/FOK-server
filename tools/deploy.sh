#!/usr/bin/env bash
# CI deploy: mirrors public/ to the webroot via FTPS.
# Usage: deploy.sh [staging]
# Credentials come from the environment: FTP_HOST, FTP_USER, FTP_PASS
# (GitHub Actions secrets in CI; never stored in the repo).
#
# Uploads run CONCURRENTLY (DEPLOY_PARALLEL, default 6). This FTPS host does
# not resume the per-file data-channel TLS session, so every transfer pays a
# full handshake (~2 s); running several at once hides that latency and takes
# the deploy from ~85 s to ~15 s. It is always a FULL upload, so the server
# can never drift. Each phase is a barrier - all of it lands before the next
# starts - so the ordering guarantee below still holds.
set -euo pipefail
cd "$(dirname "$0")/.."

prefix=''
[ "${1:-}" = "staging" ] && prefix='staging/'
if [ -z "${FTP_HOST:-}" ] || [ -z "${FTP_USER:-}" ] || [ -z "${FTP_PASS:-}" ]; then
    echo "FTP_HOST/FTP_USER/FTP_PASS must be set" >&2
    exit 1
fi
export FTP_HOST FTP_USER FTP_PASS prefix
par="${DEPLOY_PARALLEL:-6}"

# One transfer. --retry rides out a transient connection cap: a shared host
# may refuse the Nth simultaneous FTPS connection, so back off and retry.
put_one() {
    local f="$1" rel="${1#public/}"
    curl -sS --ssl-reqd --retry 3 --retry-delay 1 --user "$FTP_USER:$FTP_PASS" \
        --ftp-create-dirs -T "$f" "ftp://$FTP_HOST/$prefix$rel"
    echo "  $prefix$rel"
}
export -f put_one

# A phase reads NUL-delimited paths on stdin and uploads them concurrently,
# but as a BARRIER: xargs returns only once every file is on the server, so
# the next phase never overtakes this one.
upload_phase() {
    xargs -0 -r -P "$par" -n1 bash -c 'put_one "$0"'
}

# Upload order matters during the deploy window:
# 1. src/    shared classes + schema migrations before their consumers
# 2. assets/ versioned-immutable files before any HTML that references
#            their new ?v= URL (else a mid-window fetch caches old content
#            under the new URL for a year)
# 3. rest    endpoints and pages last
find public/src -type f -print0 | sort -z | upload_phase
find public/assets -type f -print0 | sort -z | upload_phase
find public -type f -not -path 'public/src/*' -not -path 'public/assets/*' -print0 | sort -z | upload_phase
echo "Deployed public/ to [${prefix:-live}] (parallel $par)"
