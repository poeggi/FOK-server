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
count=0
while IFS= read -r f; do
    rel="${f#public/}"
    curl -sS --ssl-reqd --user "$FTP_USER:$FTP_PASS" --ftp-create-dirs \
        -T "$f" "ftp://$FTP_HOST/$prefix$rel"
    count=$((count + 1))
    echo "  $prefix$rel"
done < <({ find public/src -type f | sort; find public/assets -type f | sort; \
    find public -type f -not -path 'public/src/*' -not -path 'public/assets/*' | sort; })
echo "Deployed $count file(s) [${prefix:+staging}${prefix:-live}]"
