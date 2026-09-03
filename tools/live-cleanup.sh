#!/usr/bin/env bash
# Removes the fixed-id test data the LIVE harnesses leave behind.
#
# The live harnesses deliberately reuse ONE fixed id set rather than random ids
# per run, so their setup is idempotent and a half-finished run leaves nothing
# to guess about. Setup being idempotent is not the same as the run being
# idempotent, though: minting is not. Every run that mints an item leaves
# another instance in the live registry owned by a player nobody deletes, and
# they accumulate - six were found where a note from an earlier session said
# three.
#
# test/smoke/06_admin.sh already deletes its OWN test players at the end of a
# remote run, which is why the smoke is not the source of this. It is the
# ad-hoc two-client harnesses, which have no such ending. This is that ending,
# kept as a tool so the sweep is one dispatch instead of a hand-typed session
# against production.
#
# delete_player is the only path that clears item instances: the public API's
# four actions are list|mint|seed|claim with no delete, while the admin
# delete_player drops the player row and then its instances, precisely so they
# are not stranded with an owner that no longer exists. The hash-chained ledger
# rows stay behind on purpose - it is append-only audit, it is never read to
# decide ownership, and items_verify walks only the chain's own integrity, so
# removing instances cannot break it.
#
# Run from CI (.github/workflows/ftp-bench.yml, manual dispatch) so the admin
# credentials stay in GitHub secrets and never touch a local file.
set -euo pipefail
cd "$(dirname "$0")/.."

: "${FOK_ADMIN_USER:?set FOK_ADMIN_USER}" "${FOK_ADMIN_PASS:?set FOK_ADMIN_PASS}"
BASE="${CLEANUP_BASE:-https://fok-server.poggensee.it}"
BASE="${BASE%/}"

# Hard-coded, never a pattern or a wildcard: this runs against production, and
# the only ids it may ever touch are the three the harnesses are pinned to.
IDS=(11117e57 22227e57 33337e57)

COOKIES=$(mktemp)
trap 'rm -f "$COOKIES"' EXIT

curl -s -o /dev/null -c "$COOKIES" -X POST \
    --data-urlencode "do=login" \
    --data-urlencode "user=$FOK_ADMIN_USER" \
    --data-urlencode "pass=$FOK_ADMIN_PASS" \
    "$BASE/admin/index.php"

items_of() { # items_of <id> : one line per instance, "item_id uid"
    curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$1\",\"action\":\"list\"}" "$BASE/api/items.php" \
    | grep -oE '"item_id":"[^"]*"' | cut -d'"' -f4
}

echo "sweeping $BASE"
found=0
for id in "${IDS[@]}"; do
    before=$(items_of "$id" | sort | uniq -c | tr -s ' ' | paste -sd', ' -)
    [ -n "$before" ] && { echo "  $id holds: $before"; found=1; } || echo "  $id holds nothing"
    curl -s -b "$COOKIES" -X POST -d "id=$id" \
        "$BASE/admin/api.php?action=delete_player" > /dev/null
done

# Proving it, rather than trusting the delete: a player that still lists items
# is a failed sweep, and a silent one is worse than a loud one.
rc=0
for id in "${IDS[@]}"; do
    after=$(items_of "$id")
    if [ -n "$after" ]; then
        echo "  FAILED: $id still holds $(printf '%s' "$after" | wc -l) instances" >&2
        rc=1
    fi
done
[ "$rc" -eq 0 ] && [ "$found" -eq 1 ] && echo "cleaned"
[ "$rc" -eq 0 ] && [ "$found" -eq 0 ] && echo "nothing to clean"
exit "$rc"
