#!/usr/bin/env bash
# CI deploy: mirrors public/ to the webroot via FTPS in ONE login, so the live
# site is untouched while the upload runs and then flips in a short, ordered
# burst instead of trickling file-by-file across the whole upload - which is
# what let a request briefly load a new class that called into a not-yet-
# uploaded one (the transient 'undefined method' / 'null setting' faults seen
# mid-deploy).
#
# EVERY NUMBER BELOW IS MEASURED against this host from a runner with
# tools/ftp-probe.sh - re-run it before changing any of this, because most of
# what looks obvious here turned out to be wrong the first time.
#
# WHY lftp AND NOT curl. One upload is ~19 SERIALIZED round trips of ~95 ms -
#   TCP, a ~300 ms greeting, AUTH SSL, control TLS, USER, PASS, PBSZ, PROT,
#   PWD, CWD, EPSV, data connect, TYPE, STOR, 226 - so ~2.0 s per file, of
#   which the 360 KB payload is nothing. The cost is round trips, not bytes,
#   and no flag removes it: --disable-epsv, --ipv4, --ftp-skip-pasv-ip,
#   --tcp-nodelay, TLS 1.2 and a clear data channel all measured within noise
#   of each other. The data channel does NOT renegotiate TLS ("SSL reusing
#   session ID"), and curl cannot reuse an FTP connection AT ALL - asked for
#   three files in one invocation it opens three connections with three
#   logins. That is what rules it out: this host rate-limits logins and 52
#   files 24 ways trips it (530 Access denied). lftp pools and reuses its
#   connections - the same 52 files cost it ~24 logins, not 52 - and uploads
#   the whole tree at 24 ways in ~7.3 s. 32 and 48 ways are no faster, so 24
#   is not a ceiling to raise; it is already past the knee.
#
# WHY ONE LOGIN. A login is 2.2 s, and the deploy used to spend three of them:
#   one to read the manifest, one to upload, one to swap. The split existed
#   because the upload cannot be planned until the manifest has been read -
#   but lftp will run a shell command mid-session and `source` the file it
#   writes, so the planner runs INSIDE the session, between the get and the
#   commands it generates. `--plan` below is that planner.
#
# WHY QUEUED RENAMES. A serial rename is ~337 ms of wire time; the same rename
#   through the queue at 24 ways is ~80 ms. 52 of them serial is ~17 s - more
#   than the upload they follow. They go through the queue in dependency
#   TIERS with a `wait all` barrier between tiers, which keeps the ordering
#   guarantee the phase exists for while making the burst ~4x shorter. A
#   shorter burst is the point: ordering only ever mitigated the window, and
#   the include graph has a cycle (Db<->Load) so no total order exists anyway.
#
# WHY NO LISTING. Both phases used to verify by listing what landed. A
#   recursive listing of this tree costs 13 s against a 2.2 s login - more
#   than the upload it was checking. `cmd:fail-exit` earns that trust
#   instead: it returns non-zero for a bad rename, for a failure inside a
#   queued transfer, AND for a failure inside a sourced file - where it also
#   stops the commands that follow, which is what keeps a failed upload from
#   being followed by its own rename. It still returns zero for `mkdir -f -p`
#   over an existing directory. And a failure BEFORE `set cmd:fail-exit on`
#   does not poison the exit code, which is what lets the manifest `get` be
#   allowed to fail on the very first deploy.
#
# WHAT THE SESSION DOES, in order:
#   get the manifest the last successful deploy left in the webroot (allowed
#   to fail: there is none the first time), then plan from it - only the files
#   whose sha256 differs are touched, and a directory is only created if the
#   manifest does not already prove it exists (166 ms each, and they all do
#   after the first deploy). Upload every changed file to a <name>.tmp
#   sibling: nothing the server serves changes yet, a request sees the whole
#   OLD site throughout. Then swap, renaming .tmp into place tier by tier -
#   src/ by dependent count (the shared classes before the pages that require
#   them), then assets/ (before any HTML naming their new ?v= URL), then the
#   other endpoint directories, then the webroot root last. The manifest is
#   the final command, so fail-exit means it can only be written once every
#   rename has succeeded; anything short leaves it describing the last
#   known-good tree and the next deploy redoes the difference.
#
# Renaming (never overwriting) keeps each file's swap atomic: a request reads
# the whole old or the whole new file, never a half-written upload. What
# shared FTP CANNOT give is one atomic flip of the WHOLE tree - a request
# landing in the swap burst may still catch a mix. True zero would need a
# webroot symlink swap the host does not expose; this shrinks the exposure
# from the entire trickling upload to a burst now measured in a few seconds.
#
# DEPLOY_FULL=1 ignores the manifest and pushes everything.
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT=$(pwd)

# Named .ht* so the stock rule that hides .htaccess hides it too. It holds
# only public/ paths and their hashes - files the repo already publishes - so
# it is housekeeping, not a secret.
MANIFEST='.htdeploy-manifest'

# ---- planner: run by lftp from inside the session --------------------------
# Reads the manifest the `get` just fetched and writes the commands that
# depend on it. It writes the plan only once it is complete, so if this dies
# there is no plan to source and the session fails instead of half-running.
if [ "${1:-}" = '--plan' ]; then
    mf_local="$2"; mf_remote="$3"; prefix="$4"; plan="$5"; status="$6"; mark="$7"
    par="${DEPLOY_PARALLEL:-24}"

    # No path in this tree has a space, which is what lets the manifest be
    # "<sha256> <path>" per line and be read back with a two-field read.
    declare -A remote=() rdir=()
    if [ -s "$mf_remote" ]; then
        while read -r h p; do
            [ -n "${p:-}" ] || continue
            remote["$p"]="$h"
            [[ "$p" == */* ]] && rdir["${p%/*}"]=1
        done < "$mf_remote"
    fi

    CHANGED=()
    while read -r h p; do
        [ -n "${p:-}" ] || continue
        [ "${remote[$p]-}" = "$h" ] || CHANGED+=("$p")
    done < "$mf_local"

    total=$(wc -l < "$mf_local")
    if [ ${#CHANGED[@]} -eq 0 ]; then
        printf '0 %s 0\n' "$total" > "$status"
        : > "$plan"          # sourcing nothing is the whole deploy
        exit 0
    fi

    # Basenames of the CHANGED files directly in public/<dir> ('' = webroot).
    # The tree is flat (public/<dir>/file), so one level is all there is.
    changed_in() {
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

    # src/ ranked by how many other src files require it, so Config/Db/Settings
    # land before their users. A non-.php file (.htaccess) has no dependents
    # and sorts last. Files of equal rank are independent of each other and
    # share a tier.
    src_ranks() {
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
        done | sort -t"$(printf '\t')" -k1,1nr -k2,2
    }

    p=''; tier=''
    add_tier() {   # $1 = dir relative to public ('' = webroot); $2.. basenames
        local dir="$1" b; shift
        for b in "$@"; do
            [ -n "$b" ] || continue
            tier+="queue mv $prefix${dir:+$dir/}$b.tmp $prefix${dir:+$dir/}$b"$'\n'
        done
    }
    flush() {   # close the current tier: nothing in the next one starts until
        [ -n "$tier" ] || return 0   # everything in this one has landed.
        p+="$tier"'wait all'$'\n'; tier=''
    }

    # Only directories the manifest does not already prove exist.
    while read -r d; do
        [ -n "${rdir[$d]-}" ] || p+="mkdir -f -p $prefix$d"$'\n'
    done < <(printf '%s\n' "${CHANGED[@]}" | xargs -r -n1 dirname | grep -vx '\.' | sort -u)

    p+="set cmd:queue-parallel $par"$'\n'
    for rel in "${CHANGED[@]}"; do
        p+="queue put $ROOT/public/$rel -o $prefix$rel.tmp"$'\n'
    done
    p+='wait all'$'\n'
    # Marks the upload as complete from inside the session, so a failure can be
    # reported as "nothing was renamed" or "the swap is partway" rather than
    # guessed at. fail-exit stops before this if any upload failed.
    p+="!touch $mark"$'\n'

    mapfile -t HAVE < <(changed_in src)
    prev=''
    while IFS=$'\t' read -r n base; do
        printf '%s\n' "${HAVE[@]}" | grep -qxF "$base" || continue
        [ -n "$prev" ] && [ "$n" != "$prev" ] && flush
        prev="$n"
        add_tier src "$base"
    done < <(src_ranks)
    flush

    mapfile -t B < <(changed_in assets); add_tier assets "${B[@]}"; flush
    for d in $(find public -mindepth 1 -maxdepth 1 -type d -printf '%f\n' \
               | grep -vxE 'src|assets' | sort); do
        mapfile -t B < <(changed_in "$d"); add_tier "$d" "${B[@]}"
    done
    flush
    mapfile -t B < <(changed_in ''); add_tier '' "${B[@]}"; flush

    p+="put $mf_local -o $prefix$MANIFEST"$'\n'

    printf '%s %s %s\n' "${#CHANGED[@]}" "$total" \
        "$(printf '%s' "$p" | grep -c '^queue mv')" > "$status"
    printf '%s' "$p" > "$plan.part" && mv "$plan.part" "$plan"
    exit 0
fi

# ---- driver ----------------------------------------------------------------
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

mf_local=$(mktemp); mf_remote=$(mktemp); plan=$(mktemp)
status=$(mktemp);   mark=$(mktemp)
trap 'rm -f "$mf_local" "$mf_remote" "$plan" "$plan.part" "$status" "$mark"' EXIT
# The sed normalises sha256sum's mode marker - two spaces in text mode, " *"
# in the binary mode it defaults to on Windows - so the manifest is
# byte-identical whichever machine writes it and a path never keeps a
# leading '*'.
(cd public && find . -type f -printf '%P\n' | LC_ALL=C sort | xargs sha256sum) \
    | sed 's/^\([0-9a-f]*\) [ *]/\1 /' > "$mf_local"
# lftp's xfer:clobber defaults to off, so `get -o` onto a file mktemp just
# created fails with "File exists" and the delta silently never engages.
# The plan and the mark must be absent for their existence to mean anything.
rm -f "$mf_remote" "$plan" "$mark"

script=''
if [ "${DEPLOY_FULL:-0}" = 1 ]; then
    echo "DEPLOY_FULL set, treating the whole tree as changed"
else
    script+="get $prefix$MANIFEST -o $mf_remote"$'\n'
fi
script+='set cmd:fail-exit on'$'\n'
script+="!bash $ROOT/tools/deploy.sh --plan $mf_local $mf_remote '$prefix' $plan $status $mark"$'\n'
script+="source $plan"$'\n'

if ! lftp -u "$FTP_USER,$FTP_PASS" "$FTP_HOST" -e "
    set ssl:verify-certificate no; set ftp:ssl-force true;
    set ftp:ssl-protect-data true; set net:timeout 20;
    $script
    bye" >/dev/null; then
    if [ ! -e "$mark" ]; then
        echo "FAILED before any rename: the live tree is untouched and the" >&2
        echo "manifest still describes it; re-running redoes the difference." >&2
    else
        echo "FAILED partway through the swap. The tree may be mixed and the" >&2
        echo "manifest was NOT updated; re-run the deploy." >&2
    fi
    exit 1
fi

read -r nchanged total nswap < "$status"
if [ "$nchanged" -eq 0 ]; then
    echo "Deployed public/ to [${prefix:-live}] (all $total files identical, no change)"
else
    echo "Deployed public/ to [${prefix:-live}] ($nchanged of $total files, $nswap swapped)"
fi
