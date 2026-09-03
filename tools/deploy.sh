#!/usr/bin/env bash
# CI deploy: mirrors public/ to the webroot via FTPS in three phases, so the
# live site is untouched while the upload runs and then flips in a short,
# ordered burst instead of trickling file-by-file across the whole upload -
# which is what let a request briefly load a new class that called into a
# not-yet-uploaded one (the transient 'undefined method' / 'null setting'
# faults seen mid-deploy).
#
# WHY lftp AND NOT curl. Measured against this host from a runner with
# tools/ftp-probe.sh - re-run it before changing any of this:
#
#   One upload is ~19 SERIALIZED round trips of ~95 ms - TCP, a ~300 ms
#   greeting, AUTH SSL, control TLS, USER, PASS, PBSZ, PROT, PWD, CWD, EPSV,
#   data connect, TYPE, STOR, 226 - so ~2.0 s per file, of which the 360 KB
#   payload is nothing. The cost is round trips, not bytes, and no flag
#   removes it: --disable-epsv, --ipv4, --ftp-skip-pasv-ip, --tcp-nodelay,
#   TLS 1.2 and a clear data channel all measured within noise of each other
#   (2071-2162 ms/file). Two things this comment used to assert are false:
#   the data channel does NOT renegotiate TLS (the trace says "SSL reusing
#   session ID"), and curl cannot reuse an FTP connection at all - asked for
#   three files in one invocation it opens three connections with three
#   logins, so batching -T pairs saves nothing.
#
#   That is what rules curl out. It needs one login per file, this host
#   rate-limits logins, and 52 files 24 ways trips it (530 Access denied), so
#   curl tops out near 12 ways / ~18 s. lftp really does reuse its pooled
#   connections - the same 52 files cost it ~24 logins, not 52 - so it runs
#   clean at 24 and uploads the whole tree in ~11 s, verified 52/52 landed.
#
#   A login is ~1.3 s of that, so the phases below are packed into as few FTP
#   sessions as the ordering allows (4), not one per file or per directory.
#
# PHASE 0  DIFF: fetch the manifest the last successful deploy left in the
#   webroot and act only on the files whose sha256 differs. A release usually
#   touches a handful of the 52 files, which is what takes an ordinary deploy
#   down to a few seconds. The manifest is written LAST, only after the swap
#   verified, so a half-finished deploy leaves it describing the last
#   known-good tree and the next run redoes whatever it missed. DEPLOY_FULL=1
#   ignores it and pushes everything.
# PHASE 1  UPLOAD every changed file to a <name>.tmp sibling over lftp's
#   parallel queue (DEPLOY_PARALLEL, default 24). Nothing the server serves
#   changes yet: a request sees the whole OLD site throughout the upload. The
#   same session then lists the tree, so a short upload is caught and cannot
#   reach the swap.
# PHASE 2  SWAP: rename the .tmp files into place over ONE session, in
#   dependency order - src/ (the shared classes) before the api/admin/root
#   pages that require them, assets/ before any HTML naming their new ?v=
#   URL - then list again to confirm every .tmp was consumed.
#
# Renaming (never overwriting) keeps each file's swap atomic: a request reads
# the whole old or the whole new file, never a half-written upload. What
# shared FTP CANNOT give is one atomic flip of the WHOLE tree - a request
# landing in the swap burst may still catch a mix (the include graph even has
# a cycle, Db<->Load, so no total dependency order exists). True zero would
# need a webroot symlink swap the host does not expose; this shrinks the
# exposure from the entire trickling upload to a short ordered burst.
set -euo pipefail
cd "$(dirname "$0")/.."

prefix=''
[ "${1:-}" = "staging" ] && prefix='staging/'
if [ -z "${FTP_HOST:-}" ] || [ -z "${FTP_USER:-}" ] || [ -z "${FTP_PASS:-}" ]; then
    echo "FTP_HOST/FTP_USER/FTP_PASS must be set" >&2
    exit 1
fi
if ! command -v lftp >/dev/null; then
    echo "lftp is required: curl cannot reuse an FTP connection and this host" >&2
    echo "rate-limits logins (see the measurements at the top of this file)." >&2
    exit 1
fi
par="${DEPLOY_PARALLEL:-24}"

# Named .ht* so the stock rule that hides .htaccess hides it too. It holds
# only public/ paths and their hashes - files the repo already publishes - so
# it is housekeeping, not a secret.
MANIFEST='.htdeploy-manifest'
MARK='===LIST==='

# stdout is captured by callers; lftp's progress and errors stay on stderr and
# flow straight into the CI log.
lftp_run() {   # $1 = commands
    lftp -u "$FTP_USER,$FTP_PASS" "$FTP_HOST" -e "
        set ssl:verify-certificate no; set ftp:ssl-force true;
        set ftp:ssl-protect-data true; set net:timeout 20;
        set cmd:queue-parallel $par;
        $1
        bye"
}
# Everything after the marker is the listing, so upload chatter cannot be
# miscounted as a landed file.
count_tmp() { printf '%s\n' "$1" | sed -n "/^$MARK\$/,\$p" | grep -c '\.tmp$' || true; }

# ---- PHASE 0: what actually changed ----------------------------------------
# No path in this tree has a space, which is what lets the manifest be
# "<sha256> <path>" per line and be read back with a two-field read. The sed
# normalises sha256sum's mode marker - two spaces in text mode, " *" in the
# binary mode it defaults to on Windows - so the manifest is byte-identical
# whichever machine writes it and a path never keeps a leading '*'.
mf_local=$(mktemp); mf_remote=$(mktemp)
trap 'rm -f "$mf_local" "$mf_remote"' EXIT
(cd public && find . -type f -printf '%P\n' | LC_ALL=C sort | xargs sha256sum) \
    | sed 's/^\([0-9a-f]*\) [ *]/\1 /' > "$mf_local"

if [ "${DEPLOY_FULL:-0}" = 1 ]; then
    echo "PHASE 0  DEPLOY_FULL set, treating the whole tree as changed"
else
    lftp_run "get $prefix$MANIFEST -o $mf_remote;" >/dev/null 2>&1 || true
    if [ -s "$mf_remote" ]; then
        echo "PHASE 0  manifest found, comparing"
    else
        echo "PHASE 0  no manifest on the server, treating the whole tree as changed"
    fi
fi
[ -s "$mf_remote" ] || : > "$mf_remote"

declare -A remote=()
while read -r h p; do [ -n "${p:-}" ] && remote["$p"]="$h"; done < "$mf_remote"
CHANGED=()
while read -r h p; do
    [ -n "${p:-}" ] || continue
    [ "${remote[$p]-}" = "$h" ] || CHANGED+=("$p")
done < "$mf_local"

total=$(wc -l < "$mf_local")
if [ ${#CHANGED[@]} -eq 0 ]; then
    echo "         all $total files identical, nothing to upload"
    echo "Deployed public/ to [${prefix:-live}] (no change)"
    exit 0
fi
echo "         ${#CHANGED[@]} of $total files changed"

# ---- PHASE 1: upload each changed file to its .tmp name (no rename yet) -----
script=''
while read -r d; do script+="mkdir -f -p $prefix$d"$'\n'; done \
    < <(printf '%s\n' "${CHANGED[@]}" | xargs -r -n1 dirname | grep -vx '\.' | sort -u)
for rel in "${CHANGED[@]}"; do script+="queue put public/$rel -o $prefix$rel.tmp"$'\n'; done
script+="wait all"$'\n'"echo $MARK"$'\n'"find $prefix"$'\n'
out=$(lftp_run "$script") || true
before=$(count_tmp "$out")
if [ "$before" -lt "${#CHANGED[@]}" ]; then
    echo "PHASE 1  FAILED: ${#CHANGED[@]} files sent, only $before .tmp on the server;" >&2
    echo "         nothing renamed, the live tree is untouched" >&2
    exit 1
fi
echo "PHASE 1  uploaded ${#CHANGED[@]} files (parallel $par), $before .tmp present"

# ---- PHASE 2: swap the uploaded files into place, in dependency order ------
# Basenames of the CHANGED files directly in public/<dir> ('' = webroot). The
# tree is flat (public/<dir>/file), so one level is all there is.
changed_in() {   # $1 = dir relative to public ('' = webroot root)
    local d="$1" rel rest
    for rel in "${CHANGED[@]}"; do
        if [ -z "$d" ]; then
            [[ "$rel" == */* ]] || printf '%s\n' "$rel"
        else
            [[ "$rel" == "$d"/* ]] || continue
            rest="${rel#"$d"/}"
            [[ "$rest" == */* ]] || printf '%s\n' "$rest"
        fi
    done
}

# src/ ordered most-depended-upon first (Config/Db/Settings before their
# users). Not a perfect order - the Db<->Load cycle forbids one - but it puts
# the base classes first so the src burst is least likely to catch a new
# consumer ahead of a new provider. A non-.php file (.htaccess) has no
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

swap=''; nswap=0
add_swap() {   # $1 = dir relative to public ('' = webroot); $2.. = basenames
    local dir="$1" base; shift
    for base in "$@"; do
        swap+="mv $prefix${dir:+$dir/}$base.tmp $prefix${dir:+$dir/}$base"$'\n'
        nswap=$((nswap + 1))
    done
}

# Swap order: src/ (deps) -> assets/ (?v= URLs) -> the other endpoint dirs ->
# webroot root last (its pages are entrypoints into a now-live src/).
mapfile -t ORDER < <(src_bases)
mapfile -t HAVE  < <(changed_in src)
for base in "${ORDER[@]}"; do
    if printf '%s\n' "${HAVE[@]}" | grep -qxF "$base"; then add_swap src "$base"; fi
done
mapfile -t B < <(changed_in assets); add_swap assets "${B[@]}"
for d in $(find public -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | grep -vxE 'src|assets' | sort); do
    mapfile -t B < <(changed_in "$d"); add_swap "$d" "${B[@]}"
done
mapfile -t B < <(changed_in ''); add_swap '' "${B[@]}"

swap+="echo $MARK"$'\n'"find $prefix"$'\n'
out=$(lftp_run "$swap") || true
after=$(count_tmp "$out")
if [ "$after" -gt $((before - nswap)) ]; then
    echo "PHASE 2  FAILED: $nswap renames sent but $after .tmp remain (expected" >&2
    echo "         $((before - nswap))); the tree may be mixed, re-run the deploy" >&2
    exit 1
fi
echo "PHASE 2  swapped $nswap files into place in dependency order"

# The manifest goes up only now, so it always describes a tree that fully
# landed AND fully swapped; anything short leaves the previous one standing
# and the next deploy redoes the difference.
lftp_run "put $mf_local -o $prefix$MANIFEST;" >/dev/null

echo "Deployed public/ to [${prefix:-live}] (${#CHANGED[@]} of $total files, parallel $par)"
