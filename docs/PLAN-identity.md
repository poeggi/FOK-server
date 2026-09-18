# Identity - proof of ownership for a player id

Status: steps 1-7 SHIPPED in 1.17.0 (API 4.20, schema 49, 2026-09-16).
Step 8 collects every client-side topic for FOK-snake. Step 9 is the
cleanup release after the cutoff. Where the code differs from the text
below, the code is right and this list says how:

- The class is `Ident` (src/Ident.php), not `Auth`: Auth is the admin
  login. `Ident::require` / `Ident::hello` answer 401; `Ident::verify` /
  `Ident::register` are the decisions behind them, which the unit tests
  call.
- A row schema 49 copied off the vault is UNCONFIRMED (bound_ip ''), and
  T1 covers it: the owner's client from before the token sends the vault
  token on backup.php and on nothing else, so a bound-and-enforced copy
  would have locked every player with a cloud backup out of hello until
  their client updated. The first hello presenting the token CONFIRMS
  the row (bound_ip set), and from then on a request without it is 401.
  The admin shows the difference (Bound column, a tilde; the client
  popup).
- T2 mints UNCONFIRMED, like a copy, and only for a request with no `tok`
  member at all (whatever it sent as `token`): a client that speaks 4.20
  stores only what a hello answers, so its next hello is what binds.
- `ident_fails_per_min` (default 10) exists since 4.20 as the threshold
  of the log line; 5.0 turns it into the 429.
- The live harness keeps its tokens in `~/.fok-server-livetest.tok`, one
  line per (base, id), not JSON: bash reads it without a parser.
- The smoke carries `tok` on every request of every part (lib.sh jt / qt,
  `bind`, `bound`); test/smoke/10_ident.sh is the new part and runs as a
  fourth parallel group against staging.
- hello validates EVERY input before the gate: a 400 after a bind would
  leave an id bound to a token the client was never answered.
- 4.21 / 1.18.0 (2026-09-16, asked for by the client side): the token
  leaves the request line. poll.php, the relay's held read and the vault
  restore were GETs with `tok` in the query, which the web server's
  access log records on every hit - the plan's "in no server log" was
  wrong about that log. Each answers a POST with the same members as a
  JSON body; the GETs are TEMPORARY(ident) and go with 5.0. So the
  cleanup release is 5.0 / 1.20.0, not 1.18.0 (1.19.0 went to 4.22, the
  TURN credentials).

THE CUTOFF IS 2026-10-01. Everything marked TEMPORARY(ident) exists to
carry clients over and is deleted after that date (step 9).

## The problem

A player id is 8 hex chars and public by design: it is the friend code,
it is on every roster, every tournament sheet, every friend list. No
request proves that the caller owns the id it names. So anyone who has
seen an id can act as that player: drain their signal mailbox (invites,
ICE), send friend requests, report tournament results, claim items,
end their duel, mint event passes as a member, approve a roster as the
organizer. The event pass is cryptographically sound (an HMAC on a
32-byte secret); the identity behind it is a self-declared string.

## What exists, and is reused

The config vault already has exactly the token this needs
(docs/API.md, Stats backup / restore): 16 random bytes minted by the
server, only its SHA-256 stored (`vault.token_hash`), constant-time
compare, an operator reset (`vault_reset`), and the rule that it never
changes for an id. The client (FOK-snake js/storage.js) already keeps
it beside the id - cookie master `fok_tok`, localStorage
`fok-snake-tok`, carried in the file backup as `tok`, restored by
`_applyRestoredConfig`. It is sent to backup.php and nowhere else.

The plan is: that token proves the id on EVERY request. Nothing new is
invented on the client. What moves is where the hash lives (from the
backup row to an identity row) and who checks it (every endpoint).

## Design

    tok    32 hex chars (16 random bytes), minted by the server on the
           first hello of an unbound id, returned once. Sent by the
           client on every request that names its id. Only the SHA-256
           hash is stored. It never changes for an id; only an operator
           reset makes the next hello mint again.

Storage: a new table `ident (id PRIMARY KEY, tok_hash, bound_at,
bound_ip)`, schema 49. Its own table because the token is PROPERTY,
not presence: `Presence::forget` drops the players row after
player_ttl_days and keeps what the player owns (items, vault, scores);
the binding belongs with those. `delete_player` (confiscation) drops it.

The check: ONE function, `Auth::require($id, $tok)`, called right after
`Util::isValidId` in every public endpoint that takes an id - hello,
poll, signal, start, match, friend, items, scores POST, tournament,
event, backup, relay (deprecated, but a hole is a hole). Nothing else
runs for an impostor: no beat, no row, no counter. The admin API is
outside it (its own session).

Cost: the presence entry carries `tok` (the hash) from the session
open, so the steady state is the apcu_fetch the beat does anyway.
A cold entry reads the ident row once - one SELECT beside the upsert a
session start already costs. poll.php stays database-free; the check
runs once at the top, never in the hold loop.

    unbound id, hello, tok null          -> mint, bind, answer tok
    unbound id, hello, tok present       -> mint, bind, answer tok
                                            (the client replaces its copy)
    bound id, any request, right tok     -> ok
    bound id, any request, wrong/no tok  -> 401 {"ok":false,"error":"bad token"}
    unknown id                           -> as unbound (hello registers)
    unbound id, non-hello, tok absent    -> TEMPORARY(ident): ok (a legacy
                                            client); after the cutoff 401

One rule for the client: whenever a hello answer carries `tok`, store
it. That covers the first bind, a re-bind after the operator's reset,
and a return after the row was forgotten.

Wrong tokens are counted per (id, IP) PAIR in a fixed 60 s window
(apcu_add plus apcu_inc, the Events::noteFail shape, namespaced: it is
judged against the ident table). The pair is the key on purpose: per
id alone, a stranger could lock an owner out from anywhere; per IP
alone, one phone on a venue's WiFi would lock out every member behind
that NAT. The count is taken AFTER the check fails, never before it, so
a request with the right token is never refused, from any IP.

    4.20  count, and `Alerts::note` ('ident') when a pair crosses
          `ident_fails_per_min` (a stolen id being tried, or a second
          device on a stale backup).
    5.0   the same key refuses: a WRONG token from a pair over the cap
          answers 429 {"ok":false,"error":"too many attempts",
          "retry_after":60} instead of 401.

What the 429 buys, stated honestly: a client on a stale backup stops
hammering (the contract's "stop and retry later"), and the operator has
a line. Against a guesser it changes nothing that 128 bits did not
settle. The one cost it does not bound: a wrong token on an id with no
live entry reads the ident row, so an attacker walking many ids from
one IP costs one SELECT per id - bounded by the worker pool like every
other request, and a new pair each time. Named under Residual.

signal.php already allow-lists types and sends `from` as the caller's
id; the token makes that id proven, which closes the `from` item on the
anti-DoS list without touching signal.php.

The vault: `Vault::backup` and `Vault::restore` compare against
`ident.tok_hash`. After the cutoff the vault never mints - the token
comes from hello - and `vault.token_hash` is dead (left in place; drop
it once the host's SQLite is confirmed >= 3.35, verified on the real
host, never assumed).

## The migration, and why it is short

Existing ids have no ident row. Trust on first use: the first hello
that carries `tok` (a value or null) binds the id. The race is "between
the server release and the owner's next launch": somebody who already
holds a stranger's id could bind it first.

Three things make that window small:

- Schema 49 COPIES every enrolled `vault.token_hash` into ident. A
  player who ever backed up to the cloud is bound before the first
  request, with a token their client already sends. No window at all.
- A bind onto an id whose players row is older than 24 h writes an
  `Alerts::note` ('ident', "id NAME bound N days after first sight from
  IP"). Permanent, not temporary: after the cutoff a late bind is
  exactly the row worth reading.
- The operator's `token_reset` (the renamed `vault_reset`, same
  semantics: clears the hash, the next hello mints, briefly claimable)
  hands a hijacked or lost id back.

What is TEMPORARY(ident), grep-able by that tag, deleted in step 9:

    T1  Auth::require accepts a request WITHOUT tok on an UNBOUND id
        (legacy clients keep working until they update).
    T2  backup.php mints a token on a first backup without one, and
        accepts `token` as an alias of `tok`.
    T3  the smoke's legacy-client assertions (a token-less hello on an
        unbound id is 200).
    T4  the contract paragraph in docs/API.md that states T1 and T2 and
        the date.

A runtime backstop: `Auth::LEGACY_UNTIL` (2026-10-01 00:00 UTC) gates
T1 and T2, so the cutoff holds on the date even if step 9 slips. And
test/checks.sh fails once the date has passed and the tag is still in
the tree, so it cannot be forgotten.

After the cutoff an updated client on a never-bound id still binds on
its first hello (that is the permanent registration path); it is not
locked out, it only missed the protected window. A client that never
updated gets 401 on everything until it does; the PWA updates itself on
launch.

## Versions

- Step 1-7: FOK_SERVER_VERSION 1.17.0, FOK_API_VERSION 4.20, schema 49.
  The jump from 4.16 to 4.20 is deliberate: this MINOR is the
  PREPARATION for 5.0 and the number says so. Additive on the wire:
  `tok` optional everywhere, one new answer (401 bad token) that only
  a bound id can meet.
- Step 9 (on or after 2026-10-01): 1.20.0 (1.18.0 went to 4.21, 1.19.0 to 4.22, see the
  status list), FOK_API_VERSION 5.0. `tok` required, the pair throttle
  refuses, the three GET forms gone, and NO migration path is left in
  the tree. A field that was optional becoming required is the
  MAJOR the contract defines.

The server ships first (it accepts both), the client right after:
FOK-snake 4.5 (from 4.4.x) rides API 4.20, and FOK-snake 5.0 rides
API 5.0 - the client's major moves with the contract's.

## Contract changes (docs/API.md)

- Conventions: the "planned, not part of this version" sentence goes.
  Player identity stays the public id; `tok` is the proof.
- New section "Identity token (4.20)" after Conventions: what tok is,
  where it is sent (`tok` in every POST body, `&tok=` on poll.php's GET
  and backup.php's GET), the mint on hello, the one answer 401
  `bad token`, the client rule "store whatever hello answers", the
  operator reset, the TEMPORARY(ident) paragraph with the date (T4).
- hello: `tok` in the request, `tok` in the answer when minted.
- Stats backup / restore: `token` becomes `tok`; the mint moves to hello;
  403 `bad token` becomes 401.
- Errors list: 401 added (4.20); the pair throttle's 429 (5.0).
- Versioning: 4.20 is listed as "the identity token, the preparation for
  5.0, where it is required".
- README.md's sketch: `tok` on the hello line.
- Disclosure, stated: a `tok: null` hello on a bound id answers 401, so
  "this id exists and is bound" is learnable. Ids are public; the
  binding state is the one new fact, and it is the fact the owner wants
  an impostor to hit.

## Steps

1. Schema 49: `ident` table; copy enrolled vault hashes; `bound_at` from
   `vault.updated`, `bound_ip` empty for copied rows.
2. src/Auth.php: `require`, `bind`, `reset`, `hashOf`; the LEGACY_UNTIL
   constant; the wrong-token counters (APCu, per id and per IP, fixed
   window like Events::noteFail) and the two notes.
3. Presence: the entry carries `tok` from `open()`; `forget` leaves
   ident alone; `bind` and `reset` refresh a live entry. admin
   delete_player drops the ident row.
4. Endpoints: the one call after isValidId in each of the twelve;
   hello mints and answers `tok`. Vault reads ident. backup.php: `tok`
   (and T2).
5. Admin: Players card shows bound-since; `token_reset` replaces
   `vault_reset` (one action, one modal); a figure "bound / registered"
   beside the presence counts so the migration can be watched converge.
   Version bump for assets.
6. Tests. Unit: unbound+none ok until the date and 401 after (the
   constant is injectable); bound+wrong 401; bound+right ok; hello
   mints once and refuses a second mint; the vault verifies against
   ident; reset clears; the copy step is idempotent; the late-bind
   note fires. Smoke: a NEW group part whose ids and settings nothing
   else touches - bind, 401, reset via admin in 06 - and every existing
   group's first hello adopts `tok` into the env hand-off in smoke.sh.
   Against staging the cast is bound from the first run onwards, so
   smoke.sh resets the 7e57 cast's tokens through the admin API before
   the groups start; against php -S the database is fresh. Locally in
   order, then staging as the three parallel groups.
7. test/live-protocol.sh has no admin: it keeps the cast's tokens per
   environment in ~/.fok-server-livetest.json (beside the deploy
   credentials, never in the repo), adopting what hello mints on the
   first run.

8. Client (FOK-snake) 4.5, one release, after the server is live:
   - send `tok` (getCloudToken) on every request that names the id:
     every POST body in net-api.js, `&tok=` on the poll GET, the relay
     GET and POST (net-relay.js, DEPRECATED(relay) marker as usual);
   - on a hello answer carrying `tok`, setCloudToken(tok) - the one
     rule;
   - on 401 in _netPostRes/_netGet: stop the wire, show one line
     ("THIS ID IS BOUND TO ANOTHER DEVICE - RESTORE YOUR BACKUP OR
     RESET YOUR ID" in the settings row where the id is shown), never
     retry in a loop; resetPlayerId() clears the token with the id;
   - cloudBackup: the retry-without-token path goes (after the cutoff
     backup.php never mints); a 401 there is the same message;
   - the file backup already carries `tok` and _applyRestoredConfig
     already restores it - verify, do not re-implement; a restored
     file WITHOUT `tok` (predating enrolment) leaves the client at
     `tok: null`, which binds if the id is free and 401s if not;
   - the client's live harness (the c1e7 cast) keeps its tokens the
     way step 7 does, in a file outside the repo;
   - the second-device story is unchanged: id plus token travel in the
     file backup; a fresh device has neither until a file is restored.

9. Cleanup release, on or after 2026-10-01: delete every
   TEMPORARY(ident) line (T1-T4), FOK_API_VERSION 5.0, 1.20.0, the
   contract says `tok` is required, the smoke asserts a token-less
   hello is 401. `Auth::LEGACY_UNTIL` goes with T1. The checks.sh grep
   stays as the guard that nothing tagged survives. The pair throttle
   starts refusing: `ident_fails_per_min` (Settings::DEFS, default 10,
   with a label) turns the 4.20 count into the 429; unit and smoke
   assert that a right token passes from a pair over the cap and a
   wrong one is 429 with retry_after. The client follows as
   FOK-snake 5.0: `tok` is no longer optional in what it sends, and
   a 429 `too many attempts` on the wire backs off by retry_after.

## Residual, named

- Dormant ids: an id nobody bound before the cutoff is bound by the
  first hello after it, whoever sends it. Same exposure as today,
  narrowed to ids whose owner was away, and on record via the
  late-bind note.
- A compromised device is the owner. The token proves the device, not
  the person; it does nothing against a cheating owner (minting stays
  client-trusted, see the item registry).
- Bearer over TLS: a token in a log or a captured body is the id. It is
  in no line this server writes (the worst-access rows name the action,
  not the body), and since 4.21 in no request line either - a GET query
  is what the host's access log records, which 4.20 had overlooked; the
  client keeps it out of the payload it backs up.
- An id walk: wrong tokens for many ids from one IP cost one ident
  SELECT per id with no live entry, and the pair throttle never meets
  the same pair twice. Bounded by the worker pool, like every request;
  the per-IP question belongs to the anti-DoS review.

## Decided, do not re-open

- Not asymmetric keys with a self-certifying id (id = hash of pubkey):
  8 hex chars is 32 bits, a laptop grinds a key for any target id in
  minutes. Without that property a keypair buys only "secret never on
  the wire", which TLS gives.
- Not HMAC-signed requests: replay protection for what TLS already
  protects. Possible later hardening on the same token.
- Not binding to IP or network: dual-stack and mobile make it lie.
- Not a second token beside the vault's: one secret per id, one store
  on the client, one reset for the operator.
- Not a throttle per id or per IP alone: the first lets a stranger
  lock an owner out, the second locks a venue out. The (id, IP) pair,
  after the check, is the only key that refuses nobody with the right
  token.
