# Relay: DEPRECATED and facade-isolated; APCu is the default transport

DO NOT extend the relay. It is deprecated (still shipped) and slated for
replacement by a persistent async/WebSocket hub on a VPS. Fix it if it
breaks; never grow it.

- All relay logic sits behind ONE boundary, the `Relay` facade
  (public/src/Relay.php): `markRelaying`, `activePairs`, `isRelaying`,
  `peerLeft`, `capReached`, `pairEnded`, `msgsFor`, `rateDetail`.
  Everything outside the relay's own files (signal.php, relay.php,
  ConnTrack::listDuels, AdminData) calls `Relay::` only. ConnTrack keeps
  just two flagged residual relay_seen touches (bye zero-out, stateOf
  read). Delete manifest: docs/DEPRECATED-relay.md - removal is delete 4
  files (Relay / relay.php / RelayStore / RelayRate) + remove the marked
  call sites + one drop-migration.
- The client side (FOK-snake) is deprecated too: the whole relay
  transport lives in js/net-relay.js so removal is a file delete; every
  residual hook elsewhere carries a `DEPRECATED(relay)` marker. It is
  UNTESTED BY INTENT - a frozen transport earns no regression budget; its
  pathologies were server-side anyway. Several client invite/lobby tests
  still call `__setRelay(true)` purely as a vehicle (the relay handshake
  completes without WebRTC mocks) and must move to a mocked p2p path on
  removal.
- APCu is the DEFAULT transport, trusted when usable. `usingApcu()` is
  just `relay_apcu==1 (default) && Caps::apcu()` (an in-worker
  store/fetch round-trip). The old cross-worker sharing proof
  (`Caps::apcuShared()`, SHARED_KEY/MARK_KEY pid-marks) and
  `relay_apcu_assume_shared` were REMOVED - do not reference them.
  Accepted trade-off: if a host's APCu were ever per-worker, cross-worker
  messages would be lost with NO runtime signal (undetectable from one
  worker, by design). Escape hatch: `relay_apcu=0` forces the (lossless)
  DB transport.
- APCu requested but unusable -> DB fallback raises a 'perf' Alert (admin
  tab) AND an error_log() line. `Alerts::raise()` returns bool (true =
  fresh, not deduped); the log line is gated on that so it inherits the
  alert_cooldown dedup and cannot flood.
- The `relay_age_ms` server diagnostic was removed - it re-added an
  unsampled per-delivery DB write on the APCu path. The client-facing
  WIRE `age` field (ms on server, in relay replies) STAYS - removing it
  would be an api-breaking change.
- Smoke: the relay admission-cap block is pinned to the DB transport
  (`setting relay_apcu 0` in test/smoke/05_admin.sh). The cap is
  transport-independent (counts relay_seen in connection tracking), and
  single-process `php -S` keeps APCu queue/throttle state across
  back-to-back sub-tests, which breaks those tests under the APCu
  default. The dedicated transport section (`relay_apcu 1`) still
  exercises APCu delivery; staging (real FPM) does it on real shared
  memory.
- Relay API behaviors to preserve: POST `pull:true` piggybacks the
  poster's own inbound as `messages:[...]` (drained on return, so the
  client MUST opt in and consume); `age` rides both replies; seq/ack
  desync guard in `hasAny`; `429 "relay store full, resend"` means retry,
  NOT the give-up 503. A held relay GET returns `{"ok":true,"gone":true}`
  once the pair is torn down (`Relay::peerLeft`: state ended/declined AND
  not relaying again, so a stale row cannot kill a fresh match); the
  client reads `gone` and ends the session. `Starts::request` RESETS a
  leftover higher-epoch line for a begin-play reason (first/rematch)
  instead of answering 409, so a relay rematch cannot strand one peer in
  the menu.
- Deploy is two-phase (tools/deploy.sh: upload all `.tmp`, then batched
  per-directory rename); this shrank the mid-deploy fault window but
  CANNOT be fully atomic over FTP - a sub-second swap-burst race is the
  residual.
