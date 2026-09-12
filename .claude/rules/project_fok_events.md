# Events: identifiers, the budget, the two waits, and what is terminal

An event is a room an operator opens; a player gets in by scanning its QR.
Shipped in 1.10.0 (API 4.11, schema 44/45); the printed key was shortened
to 11 characters in 1.11.0 (API 4.12) so the poster is scannable in-game.
docs/PLAN-events.md is the full design and docs/API.md is the contract;
this file is the handful of things that will bite somebody who changes the
code without reading either.

## The identifiers, and the byte budget that fixes their lengths

    eid    4 chars   public, grants NOTHING on its own
    key   11 chars   printed on the poster, long-lived, NAMES ITS OWN EVENT
    pass   6 chars   derived from the clock, valid 20 s, no row stored

Every QR an event shows has to be read by the GAME'S OWN SCANNER, which falls
back to a decoder built for a fixed version 3 at level L wherever the browser
offers no BarcodeDetector: 53 text bytes, no more. `GAME_URL#event=` is 42 of
them, so THE CODE'S ENTIRE BUDGET IS 11 CHARACTERS and there is not one byte
spare. A pass spends all 11 on `<eid>.<pass>` (4 + 1 + 6), which is why it
names no issuer; the printed key spends all 11 on itself, which is why it
carries no eid and `join` takes `{id, code}` alone. THE DOT IS WHAT TELLS THE
TWO APART. None of the lengths may grow.

The server encodes the poster at exactly version 3 / L / mask 0 for the same
reason (`Qr::svg(url, 'L', 3, 0, ...)` in public/admin/event.php). 1.10.x had
a 16-character key in a 63-byte URL: correct QR, unreadable by the one
scanner it was printed for. If a length ever changes again, the unit block
asserting 53 bytes at version 3 is what has to stay true.

## THE KEY IS IN NO JSON ANSWER

Not the admin API, not the organizer's client, not any payload this server
can produce. It exists in exactly one place an operator can read it:
public/admin/event.php, behind the login, no-store. The same rule the
per-match attestation secrets follow. `Events::card()` carries `ekey` and
`secret`, so never hand a card to a projection without stripping them -
EventView and EventAdmin both build their answers field by field for this
reason, and neither may be turned into a spread of the card.

## Two different words for two different waits

An EVENT before its start is `upcoming`. A MEMBER ROW before approval is
`pending`. They meet in one answer as `state` and `you.state` and must never
share a name.

UPCOMING IS NOT A CLOSED DOOR. Since 1.12.0 (API 4.13) a scan of the
PRINTED KEY joins one, because the poster is on a wall days before the
event and that key is the only code somebody walking past can have. A PASS
still cannot: it is minted from the clock by a member, and an upcoming
event mints none - so `join` takes the state gate and the via together.
The achievement waits for the start as well, gated in EventView::forCaller
on the state rather than on a moment, because it rides every member answer
and therefore arrives by itself.

The pass half of that gate is NEARLY UNREACHABLE and must stay. A pass is
valid for 20 s and an upcoming event mints none, so reaching the 409 takes
a pass minted while the event was active and the event then scheduled into
the future within its slot - which is precisely what the smoke engineers to
test it. A wrong or expired pass answers 404 long before this line. It is a
guard, not dead code: the day something else can mint one, it is the only
thing standing between a pass and a room that is not open.

## State is derived, never swept

`Events::stateOf` is a pure function of (mode, starts, ends, now). There is
no cron here, so a scheduled event's start and end are computed at read time
and NOTHING is pushed when either moment arrives - the client derives it from
the same three values. Caching the CARD is safe; caching the STATE would be
putting a clock in shared memory.

## What is terminal, and what merely freezes

- ENDING an event freezes it and keeps everything. Only the operator undoes
  it: `event_reopen` (1.15.0, API 4.16) sets it active again and clears a
  scheduled end that has passed, or the next read would end it again.
  Nothing in the game has that verb. A tournament running at the moment of
  the end FINISHES and is archived: it began while the event was live, and
  a clock must not stop two players mid match.
- DELETING one PURGES it - rows, caches, and the tournament it is running.
  The two get different warnings in the admin popup on purpose.
- Freezing an item instance is unrelated and stays the item registry's.

## The audience is the members AND the monitor

`Events::audience` is who a transition is told about, and it is NOT the
roster: a monitor is absent from every member list and count and is still
in it, because a screen that is not told what changed shows the wrong room.
The `event` signal fan-out (EventView::announce) and the local tournament
announce (Tournament::announce, hello `tourneys` / poll `tl=1`) both use
it. Pending rows are in neither - a door tells you nothing about the room.

This is one set on purpose. Before 1.11.3 both filtered on state 'member'
exactly, so a RESERVED monitor got neither while a FREE one - an ordinary
member holding a lease - got both. Nobody chose that; it fell out of the
row state. A monitor still cannot JOIN a tournament: that tests
`Events::isMember`, which is members only, so seeing a lobby and taking a
seat stay different things.

Since 1.13.0 (API 4.14) the monitor is also in the audience of the EVENT
TOURNAMENT'S `tourney` signals, and the roles sheet names it (`monitor`)
so every client grants it a feed, private duels included. Tournament.php
resolves the holder through `monitorOf` at the flush and at the sheet, so
it follows a slot that changes hands; `event()` adds it to every broadcast
and `deal()` sends it its own sheet with `you: idle`. It is in none of the
sheet's lists and takes no tree slot - that is the whole invisibility, and
it is what keeps 'a monitor takes no seat' true.

## The roster is the server's, and one path writes it

`Events::setMember` is the ONE path behind the organizer's `roster` verb and
the operator's `event_roster` action, so the two remote controls cannot
drift. `none` means decline, remove AND unban: the row is dropped and the
person may scan again. The organizer approves, never adds.

Named people are PRE-SUBSCRIBED: an organizer and a designated monitor get
their row when they are named, not when they scan. The `events` list on hello
is how any client learns it is in an event at all, so an organizer without a
row could not see the event it runs.

A MONITOR is a row that is not a participant: absent from every member list
and count, no achievement, and exactly two actions (`state`, `monitor`).
It never takes a tournament seat, so the cap of 8 is untouched - that falls
out of the row state, not from seat arithmetic anywhere.

## An event tournament is an ordinary tournament

`eid` is a tag on the stored entry plus a membership check on the way in.
There is no second state machine and no event-only branch in Tournament.php
or Bracket.php. Multi-staged tournaments are later work that must not shape
this schema.

## The live layer is APCu, and it is a CACHE

`ev:` (card), `em:` (the caller's rows), `en:` (counts), `ex:` (the fail
throttle), `emon:` (the monitor lease) - all namespaced, all falling through
to SQLite when APCu is down. Unlike Signals or ConnTrack this is not moved
state, so it must never grow a no-fallback path.

The wrong-code throttle exists so an attempt is ON RECORD, not because the
codes could be guessed - 79 bits and a 20 s pass make brute force
irrelevant. If it ever stops writing its line, it is doing nothing.
