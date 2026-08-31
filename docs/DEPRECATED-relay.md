# DEPRECATED: server-side relay fallback

Status: DEPRECATED (still live and functional; no removal date set).

The in-duel message relay lets a duel fall back to forwarding its
input-level messages through this server when the peer-to-peer WebRTC
DataChannel cannot be established. It is a blocking one-worker-per-long-poll
design on shared PHP-FPM hosting, so it can never scale past a fraction of
the worker pool (relay_max_duels, default 9). The intended replacement is a
persistent async hub (a single event loop / WebSocket process holding many
connections at once) on a VPS or container, where a duel costs a socket
instead of a blocked worker. Until that exists the relay stays in place as a
best-effort fallback; new work should not build on it.

This file is the delete manifest: exactly what has to change the day the
relay is removed, and how cleanly separated each part is.


## How separable is it, really?

Cleanly, now. The relay logic is concentrated behind one boundary: the
`Relay` facade (`public/src/Relay.php`). Everything OUTSIDE the relay's own
files that needs a relay concept calls a `Relay::` static and nothing else,
so removing it is deleting a marked call site of a line or two per file - not
untangling relay code from shared logic. Four files are wholly owned by the
relay and delete outright (the facade, the endpoint, the store, the rate
guard). The only relay references that are NOT a `Relay::` call are, by
design, a short and enumerated list: two residual `conn.relay_seen` touches
in ConnTrack (a column the connection tracker owns for display and liveness),
the two contract-visible signal types in `Signals.php`, the admin.js UI rows,
the config/settings constants, and the DB schema. The schema is the one seam
that cannot be removed with "no impact" (the migration ladder is append-only;
see the Schema section). So a clean deletion is: delete four files, remove the
marked `Relay::` call sites and the handful of enumerated non-facade touches,
then run one drop-migration.


## A. Files wholly owned by the relay (delete the whole file)

- `public/src/Relay.php`       - the facade; the single boundary every other
  file goes through, and the home of the relay slot accounting (the four
  methods that used to sit in ConnTrack).
- `public/api/relay.php`      - the POST/GET relay endpoint.
- `public/src/RelayStore.php`  - the message store (APCu + DB transports).
- `public/src/RelayRate.php`   - the per-client send-rate guard.

Nothing else `require`s these four except the shared seams listed in B,
so once B is cleaned they drop with no dangling references.


## B. Shared files that call the relay (delete the marked call sites)

Each of these calls the `Relay` facade at a marked spot; removing the relay
means deleting that call (and its `require_once .../Relay.php`), nothing more.

- `public/api/signal.php`
  - Remove the `invite-relay` / `accept-relay` capacity block
    (`Relay::capReached` -> 503).
  - Remove the `Relay::pairEnded` call on `bye`.
  - Remove the `!Relay::isRelaying` guard on the `accept` peer-net hint
    (it reverts to an unconditional announce).
  - Drop `require_once .../Relay.php` (keep ConnTrack - `note()` stays).

- `public/src/Signals.php`
  - Remove `invite-relay` and `accept-relay` from `TYPES` and from
    `NEEDS_RECEIPT`. (These are contract-visible signal types: removing them
    is a MAJOR API bump - see docs/API.md Versioning.)

- `public/src/ConnTrack.php`  (only two residual column touches remain)
  - This class tracks ALL 1:1 connections (p2p and relay) plus presence, so
    the file stays. The four relay methods already moved to `Relay.php`, so
    what is left here is only: `markEnded`'s `relay_seen = 0` (drop the column
    from that UPDATE), `stateOf`'s `relay_seen` read and return field, the
    `'relay'` mode arm in `BY_TYPE`, and in `listDuels` the `Relay::msgsFor`
    call plus the `msgs` output field. Revert `require_once .../Relay.php`.
  - `mode` becomes always `'p2p'` (or drop the column entirely, another
    migration).

- `public/src/Settings.php`
  - Remove the seven `relay_*` defs: `relay_max_duels`, `relay_max_payload`,
    `relay_pending_cap`, `relay_ttl`, `relay_rate_max`, `relay_rate_block_secs`,
    `relay_apcu`.

- `public/src/Config.php`
  - Remove `FOK_RELAY_WINDOW`, `FOK_RELAY_TRACK_THROTTLE`,
    `FOK_POLL_CHECK_USEC_APCU`.
  - Trim the relay lines from the `FOK_API_VERSION` changelog comment (v2,
    v3.2, v3.3) - history only, safe to leave, but stale once the feature is gone.

- `public/src/AdminData.php`
  - Remove `'relay'` from `TABLES`; the `'relaying' => Relay::activePairs()`
    stat; the `Relay::rateDetail()` read and the `relay_rate` field in the
    client detail. Drop `require_once .../Relay.php`.

- `public/assets/admin.js`
  - Remove the `Relaying` stat tile, the `Relay messages` / `Rate-limited`
    popup rows, the `Last relay` row, and the `mode === 'relay'` rendering.

- `public/src/Load.php`
  - The `msg_out` gauge (`'out'`) is fed only by the relay drain. Optional to
    remove; harmless if left (it just reads 0).

- Counters: `Util::bump('relay')` in relay.php goes with the file; the
  `relay` row in the `counters` table is orphaned data, harmless.


## C. Schema (CANNOT be deleted with zero impact - append-only ladder)

`public/src/Db.php` migrations are append-only and a fresh DB replays them
all, so you never edit a past step. The relay left three schema objects:

- v6:  `relay` table + `idx_relay_to` / `idx_relay_pair` indexes.
- v10: `conn.relay_seen` column + `idx_conn_relay` index.
- v11: `idx_relay_created` index.
- v13: `relay_rate` table.

Options the day it is removed:
1. Leave them as orphan tables/columns (simplest; a little dead schema).
2. Add a NEW forward migration that DROPs the `relay` and `relay_rate`
   tables and (via table rebuild, since SQLite drops columns awkwardly)
   `conn.relay_seen`. This is the only way to actually reclaim them, and it
   is itself a schema change to test on a real DB copy.

Either way, existing production databases keep the columns/tables until a
drop-migration runs. This is the one seam where "delete without any impact"
is not literally achievable.


## D. Contract, docs, tests

- `docs/API.md` - the `## Relay fallback` section, plus the relay lines in
  the endpoint list, signal-type table, and connection-tracking notes.
- `README.md` - the `Relay fallback` feature bullet and relay mentions.
- `CLAUDE.md` - the relay-fallback invariant note.
- Tests carrying relay coverage (remove alongside): `test/unit.php`,
  `test/smoke.sh`, `test/smoke/lib.sh`, `test/smoke/05_admin.sh`,
  `test/smoke/03_start_duel.sh`, `test/smoke/02_signals_friends.sh`,
  `test/load.php`.

Removing the `invite-relay` / `accept-relay` signal types and the relay
endpoint is a client-visible contract change: it requires a MAJOR API bump
(FOK_API_VERSION) and a coordinated FOK-snake release, since a client still
configured for relay-mode play would break.


## Client dependency

FOK-snake (separate repo) drives all of this: it decides when to fall back,
sends the `-relay` signal types, and POST/GET `relay.php`. The server side
cannot be removed until the client no longer relies on relay fallback.
