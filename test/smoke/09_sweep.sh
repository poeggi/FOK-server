# The tournament sweep, on its own: the two things below decide by WHICH
# request fires it, and the sweep rides the deferred tail of ANY client
# request the server sees. That is why this file runs after the parallel
# phase and before the admin part, on a server nobody else is asking - a
# request from another part landing between "set the ttl to zero" and "the
# card still lists it" would end the tournament a line early and read as a
# flake. It uses ID1/ID2 like 07 did, which are free again by now.
#
# What these prove is unchanged (see the block comments); only their place
# in the run moved.
# --- The sweep for a tournament nobody is at. Its logic (who counts as gone,
# which seat keeps it alive) is unit-tested against the presence entries; what
# only real HTTP can show is the two things asserted here: that an ordinary
# client request carries the sweep at all, and that an admin one does NOT -
# reading the dashboard must never be what ends a tournament.
if [ "$ADMIN" -eq 1 ]; then
    setting tournament_create_cooldown 0
    R=$(tourney "{\"id\":\"$ID1\",\"action\":\"create\"}")
    T5=$(tfield "$R" tid)
    expect "one more lobby, to be swept" '"tid":' "$R"
    setting tournament_sweep_secs 0
    R=$(hellot "$ID2")
    expect "a client request with nobody idle sweeps nothing" '"ok":true' "$R"
    R=$(act "$ID1" state "$T5")
    expect "and the lobby is still open" '"state":"open"' "$R"
    # Everyone counts as gone from here on, so only the next request decides.
    setting tournament_idle_ttl 0
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=duels")
    expect "the dashboard still lists it" "\"tid\":\"$T5\"" "$R"
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=tourney&tid=$T5")
    expect "so reading the card is not what ends one" '"state":"open"' "$R"
    R=$(hellot "$ID2")
    expect "a client request is" '"ok":true' "$R"
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=tourney&tid=$T5")
    expect "and the tournament nobody was at is ended" '"state":"abandoned"' "$R"
    setting tournament_idle_ttl 180
    setting tournament_sweep_secs 30
    setting tournament_create_cooldown 10
fi

# The gate: at most one sweep every tournament_sweep_secs across the server,
# so a busy minute cannot turn this into per-request work. HELD since the
# first client request of the run (lib.sh sets the gap to 900 for exactly
# this), then proven to hold by a lobby that survives a sweep it would
# otherwise not have. The gate cannot be re-taken by setting a new gap -
# apcu_add never renews a key that exists - which is why it is taken once,
# early, rather than here.
if [ "$ADMIN" -eq 1 ]; then
    setting tournament_sweep_secs 300
    R=$(hellot "$ID2")
    expect "a client request takes the sweep gate" '"ok":true' "$R"
    setting tournament_create_cooldown 0
    R=$(tourney "{\"id\":\"$ID1\",\"action\":\"create\"}")
    T6=$(tfield "$R" tid)
    expect "a lobby opened behind the held gate" '"tid":' "$R"
    setting tournament_idle_ttl 0
    R=$(hellot "$ID2")
    expect "a second client request inside the gate" '"ok":true' "$R"
    R=$(act "$ID1" state "$T6")
    expect "sweeps nothing, however idle everyone is" '"state":"open"' "$R"
    setting tournament_sweep_secs 0
    R=$(hellot "$ID2")
    expect "and the request past the gate" '"ok":true' "$R"
    R=$(curl -s -b "$COOKIES" "$BASE/admin/api.php?action=tourney&tid=$T6")
    expect "is the one that ends it" '"state":"abandoned"' "$R"
    setting tournament_idle_ttl 180
    setting tournament_sweep_secs 30
    setting tournament_create_cooldown 10
fi

setting tournament_create_cooldown 10
