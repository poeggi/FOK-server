#!/usr/bin/env bash
# CI deploy: mirrors public/ to the webroot via FTPS in two phases, so the
# live site is untouched while the (slow) upload runs and then flips in a
# short, ordered burst instead of trickling file-by-file across the whole
# upload - which is what let a request briefly load a new class that called
# into a not-yet-uploaded one (the transient 'undefined method' / 'null
# setting' faults seen mid-deploy).
#
# PHASE 1  UPLOAD every file to a <name>.tmp sibling, concurrently. This host
#   renegotiates the data-channel TLS per transfer (~2 s), so parallelism
#   (DEPLOY_PARALLEL, default 6) hides it. Nothing the server serves changes
#   yet: a request sees the whole OLD site throughout the upload.
# PHASE 2  SWAP: rename the .tmp files into place, ONE FTP session per
#   directory, so a whole directory flips in a single burst (rename is a
#   cheap metadata op) rather than over the many seconds an upload takes. The
#   directories go dependency-first: src/ (the shared classes) before the
#   api/admin/root pages that require them; assets/ before any HTML naming
#   their new ?v= URL.
#
# Renaming (never overwriting) keeps each file's swap atomic: a request reads
# the whole old or the whole new file, never a half-written upload. What
# shared FTP CANNOT give is one atomic flip of the WHOLE tree - a request
# landing in the sub-second swap burst may still catch a mix (the include
# graph even has a cycle, Db<->Load, so no total dependency order exists).
# True zero would need a webroot symlink swap the host does not expose; this
# shrinks the exposure from the entire trickling upload to a short ordered
# burst.
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

curl_ftp() { curl -sS --ssl-reqd --retry 3 --retry-delay 1 --user "$FTP_USER:$FTP_PASS" "$@"; }
export -f curl_ftp

# ---- PHASE 1: upload each file to its .tmp name (no rename yet) ------------
put_tmp() {
    local f="$1" rel="${1#public/}"
    curl_ftp --ftp-create-dirs -T "$f" "ftp://$FTP_HOST/$prefix$rel.tmp"
    echo "  up $prefix$rel"
}
export -f put_tmp
find public -type f -print0 | xargs -0 -r -P "$par" -n1 bash -c 'put_tmp "$0"'

# ---- PHASE 2: swap a directory into place in one session ------------------
# Listing the directory makes curl CWD into it (the same CWD a single-file
# upload does); the RNFR/RNTO renames then run as post-transfer commands in
# that one session - the exact mechanism the single-file upload+rename used,
# just batched and with a directory listing instead of an upload as the
# carrier, so no carrier file is left behind.
swap_dir() {   # $1 = dir relative to public ('' = webroot root); $2.. = basenames
    local dir="$1"; shift
    [ $# -eq 0 ] && return 0
    local base qs=()
    for base in "$@"; do qs+=(-Q "-RNFR $base.tmp" -Q "-RNTO $base"); done
    curl_ftp -o /dev/null "ftp://$FTP_HOST/$prefix${dir:+$dir/}" "${qs[@]}"
    echo "  swap $prefix${dir:+$dir/} ($# files)"
}

# Basenames of files directly in public/<dir> ('' = webroot). The tree is flat
# (public/<dir>/file), so one level is all there is.
dir_bases() { find "public${1:+/$1}" -maxdepth 1 -type f -printf '%f\n' | sort; }

# src/ ordered most-depended-upon first (Config/Db/Settings before their
# users). Not a perfect order - the Db<->Load cycle forbids one - but it puts
# the base classes first so the sub-second src burst is least likely to catch
# a new consumer ahead of a new provider. A non-.php file (.htaccess) has no
# dependents and sorts last.
src_bases() {
    local base n
    # find (not a * glob) so the dotfile .htaccess is included too.
    find public/src -maxdepth 1 -type f -printf '%f\n' | while read -r base; do
        if [ "${base##*.}" = php ]; then
            n=$(grep -lE "__DIR__ \. '/${base%.php}\.php'" public/src/*.php 2>/dev/null \
                | grep -vc "/$base\$" || true)
        else
            n=-1
        fi
        printf '%s\t%s\n' "$n" "$base"
    done | sort -t"$(printf '\t')" -k1,1nr -k2,2 | cut -f2
}

# Swap order: src/ (deps) -> assets/ (?v= URLs) -> the other endpoint dirs ->
# webroot root last (its pages are entrypoints into a now-live src/).
mapfile -t B < <(src_bases);        swap_dir src "${B[@]}"
mapfile -t B < <(dir_bases assets); swap_dir assets "${B[@]}"
for d in $(find public -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | grep -vxE 'src|assets' | sort); do
    mapfile -t B < <(dir_bases "$d"); swap_dir "$d" "${B[@]}"
done
mapfile -t B < <(dir_bases ''); swap_dir '' "${B[@]}"

echo "Deployed public/ to [${prefix:-live}] (upload parallel $par, then ordered swap)"
