# Events - server-side plan

Status: PLAN ONLY (2026-09-10). Nothing here is implemented. Steps 1-7
are this repo. Step 8 collects every client-side topic of the writeup
for FOK-snake, to be done after the server work is live.

An event is a room an operator opens on the server. A player gets in by
scanning its QR - straight in when the event is OPEN, after the
organizer's approval when it is CLOSED, the way a friend request works.
Inside, the organizer runs tournaments only members can see or join,
results are archived, joining grants a secret achievement, and members
can pass the event on with a QR that lives 20 seconds. The roster is the
server's: event_members is the only truth, the organizer's client only
manages it.

## What the client already has, and what that fixes

- The client's QR encoder (FOK-snake js/qr.js) is a FIXED shape: version
  3, byte mode, 53 text bytes max. Its friend link is 51 bytes. The live
  pass QR is rendered by the client, so its payload MUST fit 53 bytes.
  `https://poeggi.github.io/FOK-snake/#event=` is 42 bytes. That leaves
  11: a 4-char event id, a dot, a 6-char pass. Exactly 53.
- The client already parses `#friend=` and `#tourney=` hashes on load and
  in its scanner (game.js, input.js). `#event=` is the third of the same
  kind. A phone camera opens the game URL in the browser, which IS the
  game (PWA), so no server landing page is needed.
- The client already has an achievements table (assets.js ACHIEVEMENTS:
  id, name, desc, 8x8 icon with palette). The event achievement rides
  the same shape.
- Names are max 15 chars (FOK_MAX_NAME_LEN).

## Identifiers

    eid    4 chars from Tournament::CODE_ALPHABET (31^4 = 923k events).
           Public. Grants nothing on its own.
    key    16 chars, same alphabet (~79 bits). Printed ONLY on the admin
           print page. Long-lived: works while the event admits members.
    pass   6 chars, same alphabet. HMAC-SHA256(event secret, eid|slot),
           mapped onto the code alphabet and cut to 6.
           slot = floor(server_s / 10).
           Accepted while now is inside [slot*10, slot*10 + 20): the
           current slot and the one before it. Stateless, no row per pass.
    secret 32 random bytes per event, in the events row. Never leaves the
           server (like match secrets: not in any admin payload, not
           logged).

One URL shape for both QRs: `GAME_URL#event=<eid>.<code>`. The client
sends `{eid, code}` and the server tells key from pass by LENGTH (16 vs
6). The printed QR is rendered by the server (any QR version), the live
QR by the client (must fit version 3).

## Storage: SQLite, schema 44

Events outlive any request, live for days and their results are kept, so
they are rows, not APCu. Tournaments themselves stay in APCu exactly as
today; an event tournament is an ordinary tournament with an `eid` on
its stored entry.

    events         eid PK, name (<=40), descr (<=500), organizer (8-hex,
                   nullable), key, secret, closed (0/1), starts (unix s,
                   nullable), ends (nullable), mode ('upcoming'|'active'|
                   'paused'|'ended'), ach_name (<=15, nullable),
                   ach_desc (<=40), ach_icon (JSON {p,d} in the client's
                   shape, nullable), created, ended_at (nullable)
    event_members  eid, id, state ('pending'|'member'|'banned'), asked
                   (row created), joined (nullable until member), via
                   ('key'|'pass'|'admin'), PRIMARY KEY (eid, id),
                   INDEX (id)
    event_results  id PK, eid, tid, host, started, finished, seats (int),
                   played (int), podium (JSON list of ids), standings
                   (JSON rows: id, pts, diff, rank, until), INDEX (eid)

Db::COUNTED gains all three. Presence::forget deletes the caller's
event_members rows; archive rows keep the id (a missing name is a real
answer, AdminData::namesFor as everywhere). An organizer who expires
leaves the event without one - it keeps running on its schedule, nobody
can open a tournament or work the door until the operator names another
(event_organizer).

### The live layer is APCu, as everywhere else

The rows are the truth and the durability; the READS run out of shared
memory, the way presence, the tournament store and the friend delta do.
Every key is namespaced (FOK_APCU_NS) because every one of them is
DB-derived. Unlike the moved state (Signals, ConnTrack, Matchmaking)
this is a CACHE, so it falls through to SQLite when APCu is unusable -
the Settings/Caps rule, not the no-fallback rule.

    ev:<eid>   the event card: name, descr, organizer, closed, mode,
               starts, ends, key, secret, ach. Read by join, state,
               pass, the membership check and the tournament create.
               Dropped on any write to the events row.
    em:<id>    the caller's rows: [{eid, state, organizer}]. This is
               what answers `events` on hello and poll WITHOUT a query,
               and what the tournament join checks membership against.
               Exactly the `fl:<id>` shape and, like it, invalidated at
               every event_members write - never aged into a stale
               roster.
    en:<eid>   the member count (and the pending count for the
               organizer). Dropped with any roster write.
    ex:<id>    the join-failure throttle, a plain counter.

So the steady state - a client setting `events: true` on every hello -
costs ONE apcu_fetch and no connection at all, which is the same
promise the friend delta makes. SQLite is touched when a cache is cold,
when somebody joins, and when a roster or an event changes.

`stateOf` stays a pure function of (mode, starts, ends, now) and is
computed at read time from the cached card: caching the CARD is safe,
caching a STATE would be a clock in shared memory.

Two different words for two different waits, on purpose: an EVENT before
its start is `upcoming`, a MEMBER row before approval is `pending`. They
meet in one state answer (`state` beside `you.state`) and must not share
a name.

## State: derived, never swept

There is no cron, so the effective state is a pure function read at
request time (Events::stateOf):

    mode == 'ended'                      -> ended   (manual or operator)
    ends set and now >= ends             -> ended
    mode == 'paused'                     -> paused
    starts set and now < starts          -> upcoming
    starts set (and not ended)           -> active
    no schedule                          -> mode (upcoming|active)

Scheduled event: the operator sets starts/ends (UTC). Organizer run /
pause / end answer 409 'scheduled' then; the operator may still end it.
Unscheduled event: the organizer (and the operator) drive mode with
run/pause/end. Nothing announces the scheduled moments: members get
starts/ends in every state answer and derive them on the synced server
clock (the contract states facts, not cadence).

    upcoming visible to members, no joins yet (key and pass both 409
             'not started'), no passes, no tournaments
    active   joins, passes, tournaments
    paused   no joins, no passes, no new tournaments; a running
             tournament plays on
    ended    FROZEN: no joins, no passes, no new tournaments. A
             tournament running at the moment of the end finishes and
             is archived (it began while the event was live).

DECIDED: the last line stands. The alternative - aborting the running
tournament at the end moment - punishes two players mid match for a
clock, so record() archives such a match whatever the event's state is.

## The door: open or closed

`closed` is a property of the event, set by the operator at create or
edit and by the organizer from the client. It decides what a scanned
code does, and nothing else about the event changes with it:

    open     key or pass -> a member row at once, ach in the answer
    closed   key or pass -> a PENDING row; the organizer is told
             ('event' signal, event 'request', from); the organizer or
             the operator approves or declines. Approval makes the row a
             member, stamps joined, grants the achievement and tells the
             requester ('event' signal, event 'accepted'). A decline
             drops the row and tells nobody - the friend logic exactly.

A pending requester sees the PUBLIC FACE only: name, description,
organizer, schedule, state and "waiting for approval". No count, no
members, no tournaments, no archive, no pass, no achievement. A repeat
scan while pending answers pending again. Switching an event from closed
to open does not approve what is pending - the organizer still decides
those; new scans go straight in. The code stays the capability in both
modes: nobody can request without one.

## The roster is the server's

event_members is the ONLY roster. The organizer's client is a remote
control for it - approve, decline, remove, ban, through event.php - and
holds no copy: every screen reads `members` fresh, nothing reconciles at
startup, the vault carries nothing. That is deliberately NOT the
friends-list pattern, whose local copy plus startup reconciliation is
what trips the friend-request cooldown after a config restore
(.claude/rules/project_fok_friend_cooldown_alert.md). The operator's
dashboard is a second remote control for the same rows, with the same
verbs. The `events` list on hello is the same truth read from the other
side: a member's client learns it was removed by the event vanishing
from that list.

## Access rules

- join by key: event active, not banned. Open event: a member row.
  Closed event: a pending row (see The door). Idempotent either way: a
  repeat join answers what the first did, achievement included for a
  member - a client that lost the response asks again.
- join by pass: as above, plus the pass verifies for a current slot. The
  pass carries no issuer, so the server never learns who showed it. If
  naming the passer matters, the pass would have to be minted per
  member (HMAC over eid|slot|issuer, issuer in the URL) and the budget
  above has no room for it. Not in v1.
- Wrong key or pass: 404 'no such event' - the same answer as an eid
  that does not exist, so nothing enumerates. Failures are throttled
  per id (APCu counter, `event_join_fails_per_min`, default 10; over
  it 429 with retry_after and an Alerts::note 'event'). 79 bits and a
  6-char pass valid 20 s make brute force irrelevant; the throttle is
  for the log.
- banned: 403 'banned', the row stays. Removed or declined: the row is
  gone, the person may scan again (and lands pending again on a closed
  event - the organizer bans a pest instead).
- Only members read state (in full), members, pass, and may leave. A
  pending row reads the public face of state and nothing else; its
  leave is the same call and drops the row. Only the organizer runs
  run/pause/end, flips open/closed, works the roster and creates event
  tournaments.
- The operator can do everything the organizer can, and: create, edit,
  print, make organizer, delete.

## Endpoint: POST /api/event.php

Same shape as tournament.php: `{id, action, ...}`, one switch.

    join     {id, eid, code}     -> {ok, ...state}   member or pending,
                                     `you.state` says which
    state    {id, eid}           -> {ok, eid, name, descr, organizer,
                                     organizer_name, closed, state,
                                     starts, ends, now, you: {state:
                                     'pending'|'member', organizer}}
                                     and, for a MEMBER only: members
                                     (count), ach?, tourney: {tid, code,
                                     state, players, max} | null,
                                     archive: [{tid, finished, seats,
                                     played, podium:[{id,name}]}, ...]
    members  {id, eid}           -> {ok, members:[{id, name, state,
                                     joined, organizer, friend:
                                     'none'|'pending'|'accepted'}]}
                                     pending and banned rows only for
                                     the organizer
    roster   {id, eid, peer, set} -> {ok}   organizer. set is 'member'
                                     (approve a pending row), 'none'
                                     (decline or remove) or 'banned'.
                                     Unban is set 'none': the row goes,
                                     the person scans again. A peer with
                                     no row is 404: the organizer
                                     approves, never adds - the code
                                     stays the only way in
    access   {id, eid, closed}   -> {ok}   organizer, flips the door
    pass     {id, eid}           -> {ok, step: 10, valid: 20, slots:
                                     [{at, code}, ...6]}
    run      {id, eid}           -> {ok}   organizer, unscheduled only
    pause    {id, eid}           -> {ok}
    end      {id, eid}           -> {ok}   terminal
    leave    {id, eid}           -> {ok}   a member removes itself; the
                                     same setMember path as a decline,
                                     the row goes and the person may
                                     scan again. The organizer cannot
                                     leave its own event

`pass` hands out the next six slots (60 s) so the client's QR screen
makes one request a minute and rotates locally on the synced clock -
one request at a time (4.10) holds. `at` is server ms of the slot start.

`members` names no online state: presence is friendship-gated and stays
so. `friend` is what the client's "ask to be friends" button reads; the
request itself is friend.php, unchanged.

`roster` carries the whole verb set the operator has, because the
organizer runs the door from the client and the server is the only
roster (see The roster is the server's). A `set` that changes nothing
answers ok, like a repeated friend accept.

`ach` = {id: 'ev_<eid>', name, desc, icon?}. Carried on join AND on
state, so a reinstalled client re-grants it. Never on the pass QR, never
to a non-member. The server records nothing about granting: being a
member is the record.

### On hello and poll

- `events: true` on hello (body) and poll (`ev=1`), like `tourneys`:
  answers `events: [{eid, name, closed, state, starts, ends, you:
  {state, organizer}, members?}]`, the caller's rows - member AND
  pending, `you.state` tells them apart, and `members` (the count) only
  on a member row. This is how the client knows whether to show the
  menu entry, and how a removed member learns it (the row is gone). One
  indexed SELECT per request that sets the flag; if the gauge ever
  shows it, cache the caller's eid list in APCu like `fl:<id>`
  (invalidate on every event_members write).
- A reserved 'event' signal type, server-generated, rejected from a
  client like 'tourney'. Payloads, every one carrying `eid`:
  `{event:'state', state}` on run / pause / end to every member;
  `{event:'tourney', tid, code}` to every member when the organizer
  opens a lobby; `{event:'request', from}` to the organizer when a
  closed event gets a pending row; `{event:'accepted'}` to the
  requester on approval - no more fields, the client reads state, which
  carries the achievement. Fan-out is one Signals::send per recipient, in
  Util::defer - transitions are rare. An open-event join fans out
  nothing (the count is read), a decline or removal sends nothing (the
  friend logic). The scheduled moments push nothing (derived).

### Tournaments inside an event

THE RULE, stated first because everything below is only how it is
carried: AN EVENT TOURNAMENT IS AN ORDINARY TOURNAMENT. Same state
machine, same bracket math, same deadlines, same caps, same client
screens. `eid` is a TAG on the stored entry and a membership check on
the way in - there is NO second implementation, no event-only branch in
Tournament.php or Bracket.php beyond that tag and that check. Anything
that would need one is out of scope.

- `create {..., eid}`: caller must be the event's organizer and the
  event active; the entry stores `eid`. The answer, the lobby
  projection and the announce card carry `eid`.
- `join`: a tournament with an eid refuses a non-member with 403 'not
  in the event' - by tid AND by code. That is the whole secrecy.
- `Tournament::announce` adds the caller's events' open lobbies
  regardless of network (members only), so the normal tournament screen
  shows it too. `event state` names the same lobby.
- `finish` and `abort` (played): `record()` also inserts the
  event_results row (afterUnlock, one write). podium and standings come
  from what the tournament already computes.
- Cap and shape unchanged (2..8, one at a time, one live per host).

LATER, and deliberately not now: MULTI-STAGED tournaments - a long-lived
event tournament whose winners are seeded into a further round, so an
evening plays out as several linked brackets rather than one. That is
the reason the archive exists and the reason `eid` sits on the entry
rather than the bracket. Do not design for it in v1 and do not let it
shape the schema beyond event_results; when it comes it is new work on
top of an ordinary tournament, not a second kind of one. Its smaller
sibling, an event leaderboard aggregated across the archive, is likewise
not in v1 - it is one GROUP BY over event_results when wanted.

## Named people are pre-subscribed

An ORGANIZER and a DESIGNATED MONITOR are named by the operator, not by
a scan, so naming them IS granting them access: each gets its row at
that moment, whether or not it ever scans anything, and keeps it while
it holds the job.

This is not a convenience. The `events` list on hello is how ANY client
learns it is in an event at all, and an event lobby is announced against
the same rows - so an organizer without one could not see the event it
runs, nor the tournament it just opened. It never scans, so nothing else
would ever give it a row.

- The organizer is seated as an ordinary `member`: it is a participant
  and appears in every roster. Naming one leaves a `banned` or `monitor`
  row alone - that is not the place to overrule either.
- The monitor is seated as `monitor`, which is a row and not a
  participant (see below). Naming a member as the monitor takes them out
  of the participant list in the same write; clearing the reservation
  puts them back as a member rather than dropping them.

## Ending and purging are two different decisions

ENDING an event freezes it and KEEPS everything: the roster, the
archive, the record of every evening it ran. It is terminal but it is
not destructive, and the admin popup says so.

DELETING one PURGES it, and the operator gets a different and harder
warning that itemises what goes: the event itself (so its printed QR
opens nothing), every roster row including the bans, every archived
tournament, and the tournament it is running right now, which is ended
for its players. Nothing may be left describing an event that is gone -
which is why a purge is not three DELETEs: a live tournament tagged with
the eid would go on refusing joins in the name of a room nobody can read
any more, and a monitor claim would hold a slot on it. The APCu card,
counts, monitor claim and every member's list cache go with the rows.

## The event monitor

A screen somebody puts on a TV in the room. It shows the event live and,
once a tournament is running, becomes an INVISIBLE SPECTATOR of it: it
sees the match, it follows the bracket, it never plays and it is never
seated. Nobody attends it - it needs no key pressed, ever, and it always
shows whatever is interesting at that moment.

- `monitor_allowed` is an event property, DEFAULT TRUE, set at create or
  edit. An event that does not offer one answers 403 `no monitor`.
- ONE monitor at a time. Two ways of holding the slot, and the difference
  is the whole feature:
  - RESERVED (`monitor` on the events row, an operator names it): that
    player holds the slot whether or not it is switched on. A screen in a
    hall is still that hall's screen while it is dark, and nobody can take
    its place by being quicker.
  - FREE (`monitor` null): a LEASE in shared memory (`emon:<eid>`,
    FOK_ONLINE_WINDOW), taken by whoever asks first and given up by simply
    not asking again. A TV that is unplugged frees the slot with nobody
    pressing anything.
- THE RESERVED MONITOR IS A ROW, NOT A PARTICIPANT. It scans a code like
  anybody else and is admitted at once - the operator already decided, so
  a closed door does not apply to it - but its row state is `monitor`,
  which means: absent from every participant list and from the member
  count, no achievement (it did not join, it was posted), and exactly TWO
  actions available to it, `state` and `monitor`. Everything else answers
  403 `monitor only`. Naming a monitor converts an existing row; clearing
  one puts that row back to `member`.
- `monitor {id, eid}` is one request that both TAKES OR RENEWS the slot
  and answers everything the screen shows: the event's public face, the
  member and pending counts, the archive, and - while one is running - the
  WHOLE tournament projection, the same one a participant reads.
- THE MONITOR VIEW IS INERT. An ordinary `state` settles whatever deadline
  has come due, because a participant asking is a participant still being
  there. Tournament::monitorView does not: a screen on a wall must never
  be what forfeits somebody's match, for the same reason the admin
  dashboard is excluded from the sweep. The players' own requests run the
  clock.
- IT NEVER TAKES A SEAT. A monitor's row is `monitor`, so it is not a
  member, so Tournament::join refuses it - by tid and by code alike. It
  therefore cannot count towards tournament_max_players, and a
  tournament being watched still seats its full eight. This falls out of
  the row state rather than being a rule of its own, which is why there
  is no seat arithmetic anywhere that knows what a monitor is.
- NO MATCH TRAFFIC, as everywhere else. The projection's `roles` names the
  two players; the monitor asks one of them for a feed with the ORDINARY
  'watch' signal and the feed is P2P. The server carries nothing for a
  monitor that it would not carry for anybody.

Admin: both id fields on the event form (organizer and monitor) SEARCH BY
NAME - `player_find&q=` matches a name or an id prefix, newest seen first,
capped at 20 - because an operator thinks in names and the schema is keyed
on ids. The popup says whether a monitor is offered, and whether the slot
is reserved, live or free.

## QR encoder: public/src/Qr.php

A self-contained byte-mode encoder emitting inline SVG. The print page
uses ECC M and the smallest version that fits (the 63-byte printed URL
needs version 5 at M); level, version and mask are parameters. No
composer, no CDN, ASCII only, ~300 lines: the client's qr.js is the
reference (same GF(256), same RS divisor, same format BCH), generalised
over versions and masks. Unit-tested against fixed vectors: the client's
friend URL at version 3 / L / mask 0 must give the client's exact module
matrix, which the client's own test already verifies, so the two
encoders are pinned to each other.

DECIDED: written, not vendored - the repo has no dependencies and only a
written encoder can be pinned to the client's. It lives in src/, never in
assets/: the only consumer is the admin print page.

## Admin

Settings::DEFS: `event_join_fails_per_min` (10), `event_pass_step_secs`
(10), `event_pass_valid_secs` (20). The last two ride the `pass` answer;
the client hard-codes nothing.

admin/api.php, all POST where they write, all in AUDIT:

    events                 list: eid, name, closed, state,
                           organizer(+name), members, pending, banned,
                           tournaments archived, starts, ends, created
    event&eid=             popup: the row, the roster (id, name, state,
                           asked, joined, via, online, last_seen),
                           archive, the live tournament (tid) if any
    event_create           name, descr?, organizer?, closed?, starts?,
                           ends?, ach_name?, ach_desc?, ach_icon?
    event_edit             same fields; the key is never edited (new
                           event = new key)
    event_run / event_pause / event_end    (scheduled event: end only,
                           the other two 409 'scheduled')
    event_roster           eid, id, set ('member'|'none'|'banned') - the
                           organizer's `roster` verb, same values, same
                           code path (Events::setMember). One power more
                           than the organizer has: set 'member' on an id
                           with no row CREATES the row (via 'admin'), so
                           an operator can seat somebody without a scan
    event_organizer        eid, id (a member, or any id)
    event_delete           confirmModal; drops the roster and archive

The KEY is in no JSON answer. It is rendered into ONE page:
public/admin/event.php?eid= (Auth::requireLogin, no-store): name,
description, dates, the QR (inline SVG from Qr.php, the URL printed under
it in the code alphabet), print CSS. Print-to-PDF is the export. The
popup's Print button opens it in a new tab.

admin.js: one module `events` appended to MODULES (table, Create
button, click a row for the popup). Popup buttons: Print, Edit, Run /
Pause / End, per roster row Approve / Decline (pending), Remove / Ban
(member), Unban (banned), Make organizer, and Delete. A pending count
on the card row, so a closed event's queue is seen without opening it.
Built from pane / toolbar / makeModal / confirmModal / row / idCell /
sortable / follows; ids carry names. New look needs a variable, not a
rule. Time fields are `<input type="datetime-local">` read and shown as
UTC (the operator writes UTC, the page says so).

## Versions

Contract change: FOK_API_VERSION 4.10 -> 4.11, FOK_SERVER_VERSION
1.9.2 -> 1.10.0 (middle digit, last reset). Schema 43 -> 44. Assets move
too, which needs a bump on its own account.

## Decided 2026-09-10, before step 1

All five are settled. They are the plan now, not options - reopen one
only with a new reason.

- A tournament RUNNING at the end moment FINISHES and is archived. The
  event freezes for everything else (no joins, no passes, no new
  tournaments) but a match that began while the event was live plays
  out, and record() inserts its event_results row whatever the event's
  state is. Ending must not punish two players mid match for a clock.
- ANY MEMBER may show the pass QR. That is what makes an event spread
  in a room: one person scans in and hands it on. The pass still
  carries no issuer, so the server never learns who passed it on.
- The ORGANIZER may flip the door (`access` in event.php), and so may
  the operator at create/edit. The organizer runs the door from a
  phone - open at the start of a session, closed once the room is full.
- Qr.php is WRITTEN, mirroring the client's js/qr.js, not vendored. The
  repo has no dependencies, and a written encoder can be unit-pinned to
  the client's: the friend URL at version 3 / L / mask 0 must give the
  client's exact module matrix.
- A member MAY LEAVE: `leave {id, eid}` on event.php, the same
  Events::setMember path with set 'none'. The row goes and the person
  may scan again. Leaving is not a resignation the organizer processes.

## Steps, in order

1. CONTRACT FIRST. docs/API.md: a new "Events" section (identifiers, the
   URL shape and its 53-byte budget, the door and the two waits, join/
   state/members/roster/access/pass/run/pause/end, the `events` flag on
   hello and poll, the 'event' signal and its four payloads, `eid` on
   tournament create/lobby/announce, the errors, what ended means),
   the Versioning paragraph, README sketch and Layout. Bump the two
   versions. This is what the client half reads; hand it over once it
   is committed.
2. Schema 44 in Db::migrate, COUNTED, Presence::forget. New
   public/src/Events.php: stateOf, create, edit, join (key/pass, open
   or closed), verifyPass, mintPasses, members, setMember (the one path
   behind `roster` and event_roster), setClosed, setOrganizer,
   run/pause/end, archive(insert), listFor(id), the fail throttle.
   test/unit.php: stateOf over every (schedule, mode, now) cell; pass
   mint/verify at slot edges (9.9 s, 10 s, 19.9 s, 20 s); the fail
   throttle; join idempotence open and closed; pending sees the public
   face only; approve grants, decline drops, banned refuses; closing
   and reopening leaves pending rows pending; forget removes rows.
3. public/src/Qr.php + unit vectors (step 2 and 3 are independent).
4. public/api/event.php; hello/poll `events`; Signals 'event' reserved
   (rejected from clients, smoke-asserted); Tournament: eid on create,
   membership check on join, announce to members, archive on finish and
   on a played abort.
5. Admin: EventAdmin readers, admin/api.php actions and
   AUDIT lines, admin/event.php print page, admin.js module and popup,
   admin.css only if a variable is missing.
6. test/smoke/08_events.sh (shares the admin cookie jar from 06): create
   via admin, join by key, wrong key 404 and the throttle, pass minted
   and joins, expired slot refused, banned refused, non-organizer
   create 403, non-member join by code 403, member sees the lobby on
   hello `tourneys` and on event state, a finished tournament lands in
   the archive, a member leaves and its row is gone, end freezes joins,
   print page 200 with `<svg`, 'event' from a client is 400. Closed
   door: a scan lands pending, the organizer's mailbox holds the
   request, pending reads no count and no
   ach, a non-organizer `roster` is 403, approve makes a member and the
   requester's mailbox holds 'accepted', decline drops the row, the
   admin's event_roster does the same and seats an id without a scan,
   `access` flips the door. Rules files: a short project note
   (identifiers, the byte budget, the two waits, the freeze semantics,
   "the key is in no JSON").
7. bash test/checks.sh green, commit, push, watch CI, curl live
   version.txt. Then hand over step 8 as the FOK-snake prompt, with the
   API.md section beside it.
8. CLIENT FOLLOW-UP (FOK-snake, its own repo, after the server is live).
   Everything from the writeup that is client work, collected below.

Effort: steps 1-6 are each a commit-sized piece; 5 is the largest. One
server release, tagged 1.10.0. Step 8 is a client release of its own and
does not block the server one: every server addition is optional and
feature-detected.

## Step 8 - client follow-up (FOK-snake), collected

Nothing in this list touches this repo. It is the other half of the same
writeup, in the order a client session would take it. The server answers
all of it from API 4.11; the client feature-detects (`events` in the
hello answer, `eid` on a lobby), never gates on the minor.

Getting in:

- Deep link on load: `#event=<eid>.<code>` beside `#friend=` and
  `#tourney=` (js/game.js). Opening it in a browser on a phone with no
  snake installed lands in the game with the event page open - the
  game URL IS the events page. When the code is a key (16) or a pass (6)
  the client POSTs event.php join and shows the result; when the page
  was opened by a browser scan and the user is not yet online (offline
  setting, no id yet) the page explains what will happen first.
- Scanner: every QR scanner inside snake (the friend scanner and the
  tournament scanner, js/input.js) recognises the event URL as a third
  pattern and joins, whatever screen it was opened from. Regex:
  `/#event=([A-Z2-9]{4})\.([A-Z2-9]{6}|[A-Z2-9]{16})$/`.
- Join feedback: joined, already a member, waiting for approval (a
  closed event - the answer's `you.state` is 'pending'), not started
  yet, paused, ended (frozen, no new members), banned, no such event
  (wrong or expired code, the same answer for both), too many attempts.

Knowing you are in one:

- `events: true` on the hello or poll that precedes the multiplayer
  menu; the answer's `events` list decides whether the entry shows.
  The list is read fresh every time and never stored: the server is the
  roster, and a client that finds its event gone from the list has been
  removed. No local copy, no reconciliation pass at startup, nothing in
  the vault - the opposite of the friends list.
- The menu entry: MULTIPLAYER, two lines below FRIENDS, visible only
  while the list is non-empty. One event opens its page; several open a
  chooser. A row whose `you.state` is 'pending' shows as waiting for
  approval and opens the public face only.
- Handle the reserved 'event' signal: 'state' and 'tourney' refresh the
  open event page; 'request' nudges the organizer (a badge on the entry,
  a toast when the page is open); 'accepted' turns a waiting row into a
  member: read state, which now carries the achievement, and play the
  moment. Ignore it elsewhere.

The event page:

- Name, description, organizer name, member count ("N joined", the
  tournament-lobby wording), the schedule in local time converted from
  the UTC stamps, and the state - upcoming, active, paused, ended -
  DERIVED on the synced server clock from starts/ends, so a scheduled
  event flips without a push.
- Organizer only, unscheduled event only: RUN / PAUSE / END buttons
  (event.php run/pause/end), END behind a confirm - it freezes the
  event for everyone.
- The live tournament: a shortlink into its lobby when one is open or
  running (the state answer's `tourney`). Organizer only: CREATE
  TOURNAMENT, which is the normal create dialog posting `eid` too.
- The normal tournament page: a lobby carrying `eid` shows there as
  well, marked as the event's, and only members ever receive it.
- Archive: past tournaments of the event with date, seats, played and
  podium names, read from the state answer. Frozen after the end.
- LEAVE EVENT on the event page, behind a confirm - the row goes and
  the entry disappears with the next `events` list. The organizer's own
  page does not offer it.
- Ended rendering: the page stays readable, the pass button and the
  create button are gone, the state says ended.

The members list:

- Its own screen, the friends list as the model for the LOOK only: id
  with name, joined date, organizer marked. No online state - presence
  stays friendship gated and the server sends none. Read from `members`
  on every open; it is a view of the server's rows, never a list the
  client keeps.
- Tap a member: ASK TO BE FRIENDS, which is the ordinary friend.php
  request; the row's `friend` field (none, pending, accepted) picks the
  label and disables the button where a request is already out or the
  friendship exists.
- Organizer's view of the same screen: pending rows on top with APPROVE
  and DECLINE (the incoming friend-request screen as the model), REMOVE
  and BAN on a member, UNBAN on a banned row, every one a `roster`
  call followed by a re-read. An OPEN / CLOSED toggle on the event page
  (`access`), with a line saying what closed means.

Passing the event on:

- SHOW EVENT QR on the event page, for members while the event is
  active. It calls event.php pass once (six slots, 60 s) and rotates
  the QR every 10 s on the synced clock, picking the slot whose window
  contains now; asks again when the last slot is about to lapse; stops
  when the screen closes.
- Payload `GAME_URL#event=<eid>.<pass>`, 53 bytes: the existing version
  3 encoder (js/qr.js) renders it unchanged. A countdown or a wiping bar
  under the QR shows the 20 s the shown code still opens.
- The screen explains that the code only works while it is on screen.

The achievement:

- `ach` on the join and state answers: {id 'ev_<eid>', name, desc,
  icon?}. Grant it on join with the usual "Achievement get!" moment,
  render it in the achievements screen from the server-carried fields
  (default icon when none), persist it with the other achievements so
  it survives a reload, and re-grant silently from state if missing,
  which covers a reinstall or a restored config.
- It is secret: never listed before it is earned, not in the events
  chooser, not derivable from anything the client shows a non-member.

The event monitor - a screen for a TV:

- REUSE THE EXISTING NETCODE. This is the important one and it is not
  negotiable: the monitor is a SPECTATOR, so it goes through the spectator
  path the client already has - the ordinary 'watch' signal, the same P2P
  feed a tournament spectator gets, the same renderer. Do NOT write a
  second transport, a second feed format or a monitor-only netcode branch.
  The only thing that is new is the SCREEN; everything under it exists.
- A menu entry under the event page, shown while the event allows a
  monitor. It opens a screen meant to be left alone on a TV: no key is
  ever pressed on it, it never times out, and it always shows whatever is
  interesting at that moment.
- It calls `event.php monitor` on its own cadence. That one request both
  holds the slot and answers everything the screen shows, so there is
  nothing else to poll. Stop calling it and the slot frees itself; a
  reserved monitor keeps its place either way.
- Refused with 409 `monitor taken` - somebody else has the screen - or 403
  `no monitor` when the event does not offer one. Say which, and stop.
- What it shows, in the usual snake style: the event name, its state, how
  many have joined, how many are waiting to be let in, and the archive. As
  soon as a tournament runs, the answer's `tourney` is the WHOLE
  projection a participant reads - lobby, schedule, bracket, standings, the
  break board and `roles` - so the screen can follow it with no extra call.
- Spectating: `tourney.roles` names the two players of the match in flight,
  and `you` reads `idle` because the monitor is not seated. Ask one of them
  to watch, exactly as a tournament spectator does. When the cursor moves,
  follow it to the next pair.
- It never joins. The monitor is in no participant list, holds no pass, has
  no achievement and cannot approve, leave, or open anything - the server
  refuses all of it with `monitor only`. The screen should not offer it
  either.
- More is planned for it later. Build the screen so a section can be added
  without rearranging it.

Housekeeping on the client side:

- Tests: the hash parser and the scanner regex for all three patterns;
  the slot picker at 9.9 s / 10 s / 19.9 s / 20 s against a fixed
  clock; the events entry appearing and disappearing with the list; the
  53-byte payload still fitting the encoder.
- Docs: the client README's multiplayer section and its deep-link list.
- Nothing in the vault manifest changes: memberships live on the
  server and come back through `events`, the achievement through `ach`.
