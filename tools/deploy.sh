#!/usr/bin/env bash
# CI deploy: mirrors public/ to the webroot via FTPS.
# Usage: deploy.sh [staging]
# Credentials come from the environment: FTP_HOST, FTP_USER, FTP_PASS
# (GitHub Actions secrets in CI; never stored in the repo).
set -euo pipefail
cd "$(dirname "$0")/.."

prefix=''
[ "${1:-}" = "staging" ] && prefix='staging/'
if [ -z "${FTP_HOST:-}" ] || [ -z "${FTP_USER:-}" ] || [ -z "${FTP_PASS:-}" ]; then
    echo "FTP_HOST/FTP_USER/FTP_PASS must be set" >&2
    exit 1
fi

# Upload order matters during the seconds-long deploy window:
# 1. src/    shared classes + schema migrations before their consumers
# 2. assets/ versioned-immutable files before any HTML that references
#            their new ?v= URL (else a mid-window fetch caches old
#            content under the new URL for a year)
# 3. rest    endpoints and pages last
# One curl invocation for the whole tree, kept in the order above: curl
# holds a SINGLE FTPS control connection open and resumes the TLS session
# per file, instead of a full handshake + login for every one. The old
# file-by-file loop paid that setup 35 times for a few hundred KB (~85 s);
# the bytes were never the cost. No --parallel, so curl still uploads the
# -T transfers strictly in order (src before assets before rest matters).
# --fail-early keeps the loop's stop-on-first-error, so a failed src/
# upload never lets its consumers land.
args=()
while IFS= read -r f; do
    rel="${f#public/}"
    args+=(-T "$f" "ftp://$FTP_HOST/$prefix$rel")
done < <({ find public/src -type f | sort; find public/assets -type f | sort; \
    find public -type f -not -path 'public/src/*' -not -path 'public/assets/*' | sort; })

curl -sS --ssl-reqd --fail-early --user "$FTP_USER:$FTP_PASS" --ftp-create-dirs "${args[@]}"
echo "Deployed $(( ${#args[@]} / 2 )) file(s) [${prefix:+staging}${prefix:-live}]"
