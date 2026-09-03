# The item registry (API 4.0): server-authoritative ownership of item
# instances. Runs entirely over real HTTP, so the same file is what verifies a
# deployment - the staging run in CI walks this ladder on the real host before
# live is touched.
#
# Deliberately kept to ID1/ID2, the two registered players: the admin section
# that follows asserts an exact registered count, and every extra id would
# have to be cleaned up again.

# A transfer is attested with a truncated HMAC over "mid|tick|ws_digest",
# keyed by the RAW 16 bytes of the 32-hex match secret and cut to the first 16
# hex characters (docs/API.md spells out both encoding traps). openssl's
# hexkey does the decode, so this helper is also an independent second
# implementation of the client side of the formula.
tag() { # tag <secret-hex> <mid> <tick> <ws-digest>
    printf '%s' "$2|$3|$4" | openssl dgst -sha256 -mac HMAC -macopt "hexkey:$1" -r | cut -c1-16
}
items() { # items <json-body> : POST to api/items.php, print the body
    curl -s -X POST -H 'Content-Type: application/json' -d "$1" "$BASE/api/items.php"
}
itemscode() { # like items, but prints the HTTP status instead
    curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
        -d "$1" "$BASE/api/items.php"
}
# A no-match grep exits 1, and under `set -e` that would abort the whole run
# silently instead of failing an assertion - so every extractor swallows it
# and yields an empty string the caller can report on.
field() { echo "$1" | grep -oE "\"$2\":\"[0-9a-f]{32}\"" | head -1 | cut -d'"' -f4 || true; }
firstuid() { field "$1" uid; }
uidcount() { echo "$1" | grep -o '"uid"' | wc -l | tr -d ' ' || true; }
mint() { # mint <id> <item_id> <origin>
    items "{\"id\":\"$1\",\"action\":\"mint\",\"item_id\":\"$2\",\"origin\":\"$3\"}"
}
# One claim. MID is set below; peer_tag is omitted entirely when not given,
# which is what "no peer evidence yet" looks like on the wire.
claim() { # claim <caller> <secret> <uid> <from> <to> <tick> <seq> <ws> [peer-tag]
    local mytag extra
    mytag=$(tag "$2" "$MID" "$6" "$8")
    extra=""
    if [ -n "${9:-}" ]; then extra=",\"peer_tag\":\"$9\""; fi
    items "{\"id\":\"$1\",\"action\":\"claim\",\"mid\":\"$MID\",\"uid\":\"$3\",\"from\":\"$4\",\"to\":\"$5\",\"tick\":$6,\"seq\":$7,\"ws_digest\":\"$8\",\"my_tag\":\"$mytag\"$extra}"
}
# A well-formed 32-hex value that names nothing.
NOTHING=00000000000000000000000000000000

R=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/items.php")
expect "the registry is POST only" '405' "$R"
R=$(items "{\"id\":\"nothex\",\"action\":\"list\"}")
expect "registry rejects a malformed id" '"error":"invalid id"' "$R"
R=$(items "{\"id\":\"$ID1\",\"action\":\"nope\"}")
expect "registry rejects an unknown action" '"error":"invalid action"' "$R"

# --- The one-time legacy amnesty. Everyone playing today owns items the
# registry has never heard of, so the first client to ask gets them minted -
# once, and never again however often it asks.
R=$(items "{\"id\":\"$ID1\",\"action\":\"list\"}")
expect "a player with no instances lists an empty wardrobe" '"items":[]' "$R"
R=$(items "{\"id\":\"$ID1\",\"action\":\"seed\",\"items\":[\"cap\",\"scarf\",\"cap\",\"NOT AN ID\"]}")
expect "the legacy seed grandfathers what the client already owned" '"item_id":"cap"' "$R"
expect "the legacy seed keeps the second id too" '"item_id":"scarf"' "$R"
N=$(uidcount "$R")
if [ "$N" -eq 2 ]; then
    echo "ok   seeding dedupes repeats and drops malformed ids"
else
    echo "FAIL seed minted $N instances, expected 2: $R"
    fail=1
fi
R=$(items "{\"id\":\"$ID1\",\"action\":\"seed\",\"items\":[\"jetpack\"]}")
if echo "$R" | grep -q 'jetpack'; then
    echo "FAIL a second seed minted more items: $R"
    fail=1
else
    echo "ok   the amnesty is one-time: a second seed mints nothing"
fi
N=$(uidcount "$R")
if [ "$N" -eq 2 ]; then
    echo "ok   a repeated seed just returns the same wardrobe"
else
    echo "FAIL re-seed returned $N instances, expected 2: $R"
    fail=1
fi

# --- Minting. Client-trusted for now (the coin economy is client-side), so
# the guards here are shape and rate, not proof.
R=$(mint "$ID1" crown box)
expect "a box open mints an instance" '"ok":true' "$R"
expect "a fresh instance starts at seq 0" '"seq":0' "$R"
U1=$(firstuid "$R")
if [ "${#U1}" -eq 32 ]; then
    echo "ok   the mint hands back a 32-hex uid"
else
    echo "FAIL mint returned no usable uid: $R"
    fail=1
fi
R=$(mint "$ID1" 'NOT AN ID' box)
expect "mint rejects a malformed item_id" '"error":"invalid item_id"' "$R"
# 'legacy' is the server's own amnesty origin: a client must not be able to
# claim its items came in that way.
R=$(mint "$ID1" crown legacy)
expect "a client cannot mint under the legacy origin" '"error":"invalid origin"' "$R"
R=$(items "{\"id\":\"$ID1\",\"action\":\"list\"}")
expect "the minted instance is in its owner's wardrobe" "\"uid\":\"$U1\"" "$R"

# --- The match. A claim only exists inside one, and a match is minted where
# play BEGINS - so the duel start is what hands each side its own secret. Two
# begins in a row, at epochs nothing else in the suite uses: each must mint
# its own match, and the in-run halt after them must carry the second forward.
R=$(start_req "$ID1" "$ID2" 7 first "$(now_ms)")
MIDOLD=$(field "$R" mid)
S1=$(start_req "$ID1" "$ID2" 8 first "$(now_ms)")
S2=$(start_req "$ID2" "$ID1" 8 first "$(now_ms)")
MID=$(field "$S1" mid)
MIDB=$(field "$S2" mid)
SEC1=$(field "$S1" secret)
SEC2=$(field "$S2" secret)
if [ -n "$MID" ] && [ "$MID" = "$MIDB" ]; then
    echo "ok   both peers are told the same match id"
else
    echo "FAIL peers got different match ids: '$MID' vs '$MIDB'"
    fail=1
fi
if [ -n "$SEC1" ] && [ "$SEC1" != "$SEC2" ]; then
    echo "ok   each peer gets its own secret, never the other's"
else
    echo "FAIL match secrets are not per-peer: '$SEC1' vs '$SEC2'"
    fail=1
fi
if [ -n "$MIDOLD" ] && [ "$MID" != "$MIDOLD" ]; then
    echo "ok   every begin mints its own fresh match"
else
    echo "FAIL the second begin reused the first match: '$MID' vs '$MIDOLD'"
    fail=1
fi
R=$(start_req "$ID1" "$ID2" 9 level "$(now_ms)")
expect "an in-run halt carries the same match forward" "\"mid\":\"$MID\"" "$R"

# --- Claims. The direction rule first: from == caller means "I lost it", and
# nobody lies to lose an item, so it settles on the spot.
R=$(claim "$ID1" "$SEC1" "$U1" "$ID1" "$ID2" 100 0 ws-100)
expect "losing an item settles at once" '"state":"settled"' "$R"
expect "the transfer advances the instance seq" '"seq":1' "$R"
R=$(items "{\"id\":\"$ID1\",\"action\":\"list\"}")
if echo "$R" | grep -q "$U1"; then
    echo "FAIL the item never left the loser's wardrobe: $R"
    fail=1
else
    echo "ok   the item left the loser's wardrobe"
fi
R=$(items "{\"id\":\"$ID2\",\"action\":\"list\"}")
expect "and arrived in the winner's" "\"uid\":\"$U1\"" "$R"
# Conservation: exactly one row moved, so the item exists once, not twice.
R=$(claim "$ID1" "$SEC1" "$U1" "$ID1" "$ID2" 100 0 ws-100)
expect "re-sending a settled transfer is idempotent, not counterfeit" '"state":"confirmed"' "$R"

R=$(items "{\"id\":\"$ID1\",\"action\":\"claim\",\"mid\":\"$MID\",\"uid\":\"$U1\",\"from\":\"$ID1\",\"to\":\"$ID2\",\"tick\":101,\"seq\":1,\"ws_digest\":\"ws-101\",\"my_tag\":\"0123456789abcdef\"}")
expect "a claim the caller cannot attest to is refused" '"error":"bad self tag"' "$R"
R=$(items "{\"id\":\"$ID1\",\"action\":\"claim\",\"mid\":\"$MID\",\"uid\":\"$U1\",\"from\":\"$ID1\",\"to\":\"$ID2\",\"tick\":101,\"seq\":1,\"ws_digest\":\"\",\"my_tag\":\"0123456789abcdef\"}")
expect "a claim with no ownership digest is malformed" '"error":"invalid claim"' "$R"
R=$(items "{\"id\":\"$ID1\",\"action\":\"claim\",\"mid\":\"$NOTHING\",\"uid\":\"$U1\",\"from\":\"$ID1\",\"to\":\"$ID2\",\"tick\":101,\"seq\":1,\"ws_digest\":\"ws-101\",\"my_tag\":\"0123456789abcdef\"}")
expect "a claim outside any open match is refused" 'item_out_of_match' "$R"
R=$(claim "$ID1" "$SEC1" "$NOTHING" "$ID1" "$ID2" 102 0 ws-102)
expect "a claim on an item that was never minted is refused" '"error":"no such item"' "$R"

R=$(mint "$ID1" boots box)
U2=$(firstuid "$R")
R=$(claim "$ID1" "$SEC1" "$U2" "$ID1" "$ID2" 103 7 ws-103)
expect "a claim at the wrong seq is refused rather than applied" 'stale seq' "$R"

# The other direction: a GAIN with no peer evidence is held, not granted.
R=$(mint "$ID1" cape shop)
U3=$(firstuid "$R")
R=$(claim "$ID2" "$SEC2" "$U3" "$ID1" "$ID2" 200 0 ws-200)
expect "an unwitnessed gain is held, not granted" '"state":"held"' "$R"
expect "a held claim leaves the seq where it was" '"seq":0' "$R"
R=$(items "{\"id\":\"$ID1\",\"action\":\"list\"}")
expect "the item stays with the sender while the claim is held" "\"uid\":\"$U3\"" "$R"
PEERTAG=$(tag "$SEC1" "$MID" 200 ws-200)
R=$(claim "$ID2" "$SEC2" "$U3" "$ID1" "$ID2" 200 0 ws-200 "$PEERTAG")
expect "the peer's attestation confirms the held claim" '"state":"confirmed"' "$R"
expect "and the transfer goes through" '"seq":1' "$R"

# --- Provable tampering. A packet that ARRIVED cannot have been corrupted
# into a valid-shaped wrong tag, so a bad peer tag freezes the instance.
R=$(mint "$ID1" halo box)
U4=$(firstuid "$R")
R=$(claim "$ID2" "$SEC2" "$U4" "$ID1" "$ID2" 300 0 ws-300 0123456789abcdef)
expect "a forged peer attestation is rejected as tampering" '"error":"tag invalid"' "$R"
R=$(claim "$ID1" "$SEC1" "$U4" "$ID1" "$ID2" 301 0 ws-301)
expect "and the instance is frozen against every later claim" '"error":"item frozen"' "$R"

# One simulation moment has one outcome, so two directions for the same
# (match, item, tick) is tampering too.
R=$(mint "$ID1" visor box)
U5=$(firstuid "$R")
R=$(claim "$ID2" "$SEC2" "$U5" "$ID1" "$ID2" 400 0 ws-400)
expect "the disputed gain is held first" '"state":"held"' "$R"
R=$(claim "$ID1" "$SEC1" "$U5" "$ID2" "$ID1" 400 0 ws-400)
expect "the opposite direction at the same tick is a contradiction" '"error":"contradiction"' "$R"

# --- Rate ceiling. Minting is client-trusted, so it must fail loudly rather
# than let one client fill the registry.
if [ "$ADMIN" -eq 1 ]; then
    setting mint_max_per_hour 1
    R=$(itemscode "{\"id\":\"$ID1\",\"action\":\"mint\",\"item_id\":\"crown\",\"origin\":\"box\"}")
    expect "minting past the hourly ceiling fails loudly with 429" '429' "$R"
    setting mint_max_per_hour 60
else
    echo "skip mint rate ceiling (needs admin to lower the cap)"
fi
