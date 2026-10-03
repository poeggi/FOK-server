# Events: identifiers, the budget, the two waits, what is terminal

An event is a room an operator opens; a player gets in by scanning its
QR. docs/PLAN-events.md is the design, docs/API.md the contract. This
file is what bites somebody who changes the code without reading either.

## The identifiers, and the byte budget that fixes their lengths

    eid    4 chars   public, grants NOTHING on its own
    key   11 chars   printed on the poster, long-lived, NAMES ITS OWN EVENT
    pass   6 chars   derived from the clock, no row stored

The eid is the OPERATOR'S TO NAME: on `event_create`, or by
`event_rename` while the event is UPCOMING. A named eid may carry 0 and
1 (`Events::EID_ALPHABET`); the poster alphabet drops them because a key
is typed back and an eid never is. I, L and O stay out. An assigned eid
stays within ALPHABET. The client checks the eid's shape in three
regexes (the pass URL parse in assets.js, the `ev_<eid>` achievement id
in events.js and storage.js), which accept [0-9A-Z]{4}. `Events::isEid`
is the ONE shape check on the server.

`Events::rename` moves the events, event_members and event_results rows
in one transaction with the free check under the same lock, and drops
the card, count, monitor and every member's `em:` cache. The admin
action holds the gate: after the start the eid is in the players' hands
(the pass URL, the `ev_<eid>` achievement - a rename would grant it
twice - and the live tournament's tag), so it answers 409 `started`.
Nothing is pushed: the next `events` read carries the new eid, and the
old one answers 404 like any eid with no row.

Every QR an event shows is read by the GAME'S OWN SCANNER, which falls
back to a decoder built for a fixed version 3 at level L where the
browser has no BarcodeDetector: 53 text bytes. `GAME_URL#event=` is 42
of them, so THE CODE'S BUDGET IS 11 CHARACTERS, not one byte spare. A
pass spends all 11 on `<eid>.<pass>` (4 + 1 + 6), so it names no
issuer. The printed key spends all 11 on itself, so it carries no eid
and `join` takes `{id, code}` alone. THE DOT TELLS THE TWO APART. None
of the lengths may grow.

The server encodes the poster at exactly version 3 / L / mask 0
(`Qr::svg(url, 'L', 3, 0, ...)` in public/admin/event.php). A correct QR
too big for that decoder is unreadable by the one scanner it is printed
for. The unit block asserting 53 bytes at version 3 must stay true.

## THE KEY IS IN NO JSON ANSWER

Not the admin API, not the organizer's client, no payload this server
produces. An operator reads it in exactly one place:
public/admin/event.php, behind the login, no-store. The per-match
attestation secrets follow the same rule. `Events::card()` carries
`ekey` and `secret`, so never hand a card to a projection without
stripping them. EventView and EventAdmin build their answers field by
field for this reason; neither may become a spread of the card.

## Two different words for two different waits

An EVENT before its start is `upcoming`. A MEMBER ROW before approval
is `pending`. They meet in one answer as `state` and `you.state` and
never share a name.

UPCOMING IS NOT A CLOSED DOOR. A scan of the PRINTED KEY joins one: the
poster is on a wall days before the event, and that key is the only
code a passer-by can have. A PASS cannot: a member mints it from the
clock, and an upcoming event mints none. So `join` takes the state gate
and the via together. The achievement waits for the start, gated in
EventView::forCaller on the state rather than a moment, because it
rides every member answer.

The pass half of that gate is NEARLY UNREACHABLE and must stay. Reaching
the 409 takes a pass minted while the event was active and the event
then scheduled into the future within its slot; the smoke engineers
exactly that. A wrong or expired pass answers 404 long before. It is a
guard, not dead code: the day something else can mint a pass, it is the
only thing between a pass and a room that is not open.

## State is derived, never swept

`Events::stateOf` is a pure function of (mode, starts, ends, now). No
cron: a scheduled start and end are computed at read time and NOTHING
is pushed when either arrives; the client derives it from the same
three values. Caching the CARD is safe; caching the STATE would put a
clock in shared memory.

## What is terminal, and what merely freezes

- ENDING an event freezes it and keeps everything. Only the operator
  undoes it: `event_reopen` sets it active again and clears a scheduled
  end that has passed, or the next read would end it again. Nothing in
  the game has that verb. A tournament running at the end FINISHES and
  is archived: it began while the event was live, and a clock must not
  stop two players mid match.
- DELETING one PURGES it: rows, caches, and the tournament it runs. The
  two get different warnings in the admin popup on purpose.
- Freezing an item instance is unrelated and stays the registry's.

## The audience is the members AND the monitor

`Events::audience` is who a transition is told about, and it is NOT the
roster. A monitor is absent from every member list and count and is
still in it: a screen not told what changed shows the wrong room. The
`event` signal fan-out (EventView::announce) and the local tournament
announce (Tournament::announce, hello `tourneys` / poll `tl=1`) both use
it. Pending rows are in neither: a door tells you nothing about the
room. It is one set on purpose: a reserved monitor and a free one (a
member holding the lease) get the same. A monitor still cannot JOIN a
tournament: that tests `Events::isMember`, members only. Seeing a lobby
and taking a seat stay different things.

The monitor is also in the audience of the EVENT TOURNAMENT'S `tourney`
signals, and the roles sheet names it (`monitor`), so every client
grants it a feed, private duels included. Tournament.php resolves the
holder through `monitorOf` at the flush and at the sheet, so it follows
a slot that changes hands; `event()` adds it to every broadcast and
`deal()` sends it its own sheet with `you: idle`. It is in none of the
sheet's lists and takes no tree slot: that is the whole invisibility,
and it keeps "a monitor takes no seat" true.

## The roster is the server's, and one path writes it

`Events::setMember` is the ONE path behind the organizer's `roster` verb
and the operator's `event_roster` action, so the two cannot drift.
`none` means decline, remove AND unban: the row is dropped and the
person may scan again. The organizer approves, never adds.

Named people are PRE-SUBSCRIBED: an organizer and a designated monitor
get their row when named, not when they scan. The `events` list on
hello is how a client learns it is in an event at all, so an organizer
without a row could not see the event it runs.

A MONITOR is a row that is not a participant: absent from every member
list and count, no achievement, and exactly three actions (`state`,
`monitor`, `pass` - the wall shows the live code between tournaments).
It never takes a tournament seat, so the cap of 8 is untouched; that
falls out of the row state, not from seat arithmetic.

## An event tournament is an ordinary tournament

`eid` is a tag on the stored entry plus a membership check on the way
in. No second state machine, no event-only branch in Tournament.php or
Bracket.php. Multi-staged tournaments are later work that must not
shape this schema.

## The live layer is APCu, and it is a CACHE

`ev:` (card), `em:` (the caller's rows), `en:` (counts), `ex:` (the fail
throttle), `emon:` (the monitor lease: the ONE hold for reserved and
free alike; the `monitor` column is a right of way that preempts the
lease and, while its screen is online, refuses new takers; a stand-in
may hold the lease while the screen is offline). All namespaced, all
falling through to SQLite when APCu is down. This is not moved state,
so it must never grow a no-fallback path.

The wrong-code throttle exists so an attempt is ON RECORD, not because
the codes could be guessed: a 54-bit key (31^11) and a 30-bit pass
valid 30 s make brute force irrelevant. If it stops writing its line,
it does nothing.

The pass timing is two DEFS defaults that ride the `pass` answer as
`step` (20 s on screen) and `valid` (30 s accepted: the step plus 10 s
of grace). A client hard-codes neither, so moving them is a default
change and a contract sentence, never a contract number. `valid` need
not be a multiple of `step`: Events::verifyPass accepts slot S on
[S*step, S*step + valid).
