# friend-cooldown-hard alerts can be a client migration, not a prober

A `friend-cooldown-hard` admin alert ("blocked for 3600s") can be a FALSE
POSITIVE from the client's own friends reconciliation, not an abuser.
Closed as an edge case - do not re-propose a fix without a new field
report.

The chain, if it ever comes up again:

- A config restore (cloud or file) replaces the local friends list
  wholesale (FOK-snake js/storage.js `_applyRestoredConfig`, up to 64 ids)
  and does not restore the `fok-snake-friend-ok` synced markers - they are
  not in the manifest.
- 3.5 s after every startup the client reconciles (js/net-api.js
  `_netFrRefresh(true)` -> `_netFrAdopt(list, migrate)`), firing
  `netFriendRequest` for every local id the server roster does not list.
  Not through the background gate, so the whole pass leaves at once.
- Server: `Friends::rateHit` runs BEFORE the exists and ban checks
  (public/api/friend.php). Defaults: interval 1 s, burst 10, cooldown
  60 s, repeat window 600 s, hard 3600 s. A too-fast request still
  advances the streak, so request 11 of a parallel pass trips; a second
  pass within 600 s escalates and raises.
- Precondition: 11+ local ids the server does not list. Two ways that
  happens: the player was away past `player_ttl_days` (default 365) so
  `Presence::forget` deleted both sides of every friendship, or the list
  holds dead/mistyped ids, which return `exists:false`, record nothing,
  and are therefore re-requested at EVERY launch.
- Side effect of the same pattern: the roster never converges (only one
  request per second clears the interval gate, so each pass syncs one
  friend and buys a cooldown).

Telling it apart in admin: the player card shows friendships
accepted/pending and `friend_ban_until`. Migration = a named player,
same-size bursts an app-launch or ~60 s apart. A prober walks fresh ids
and usually also trips `friend-spam`.
