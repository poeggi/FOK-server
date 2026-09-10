#!/usr/bin/env bash
# Writes public/api/version.txt for one deploy target.
#
#   bash tools/make-version.sh live|staging
#
# The file is what /api/version.txt serves: the same JSON version.php used to
# answer, as a STATIC file Apache hands out without starting PHP. A probe -
# the deploy's own live-verify, a monitor, a person - then costs no worker and
# is answered while the pool is busy.
#
# It is GENERATED rather than committed because two of its three fields have a
# single source of truth in public/src/Config.php and the third is the target,
# which the tree cannot know: the same public/ is copied to live and to
# staging. Generating it means the numbers cannot drift from the code the
# deploy is uploading beside them.
#
# ONE generator, called by both deploy paths (tools/deploy.sh in CI,
# tools/deploy.ps1 by hand), because two would drift the moment a field is
# added.
set -euo pipefail
cd "$(dirname "$0")/.."

env_name="${1:-}"
if [ "$env_name" != 'live' ] && [ "$env_name" != 'staging' ]; then
    echo "usage: $0 live|staging" >&2
    exit 2
fi

field() { # field <constant name>
    grep -oE "$1 = '[^']+'" public/src/Config.php | cut -d"'" -f2
}
server=$(field FOK_SERVER_VERSION)
api=$(field FOK_API_VERSION)
if [ -z "$server" ] || [ -z "$api" ]; then
    echo "could not read the versions out of public/src/Config.php" >&2
    exit 1
fi

# The same shape and key order version.php answered with, so anything that
# parsed that one parses this one.
printf '{"ok":true,"server":"%s","api":"%s","env":"%s"}\n' \
    "$server" "$api" "$env_name" > public/api/version.txt
echo "public/api/version.txt: $server / api $api / $env_name"
