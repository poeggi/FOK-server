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

# src/ uploads FIRST: shared classes and schema migrations must land
# before the endpoints that depend on them, so the seconds-long deploy
# window can never serve new endpoints against old code.
count=0
while IFS= read -r f; do
    rel="${f#public/}"
    curl -sS --ssl-reqd --user "$FTP_USER:$FTP_PASS" --ftp-create-dirs \
        -T "$f" "ftp://$FTP_HOST/$prefix$rel"
    count=$((count + 1))
    echo "  $prefix$rel"
done < <({ find public/src -type f | sort; find public -type f -not -path 'public/src/*' | sort; })
echo "Deployed $count file(s) [${prefix:+staging}${prefix:-live}]"
