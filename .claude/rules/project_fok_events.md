# Events: identifiers, the budget, the two waits, and what is terminal

An event is a room an operator opens; a player gets in by scanning its QR.
Shipped in 1.10.0 (API 4.11, schema 44/45). docs/PLAN-events.md is the full
design and docs/API.md is the contract; this file is the handful of things
that will bite somebody who changes the code without reading either.

## The identifiers, and the byte budget that fixes their lengths

    eid    4 chars   public, grants NOTHING on its own
    key   16 chars   printed on the poster, long-lived
    pass   6 chars   derived from the clock, valid 20 s, no row stored

`GAME_URL#event=<eid>.<code>` is 42 + 4 + 1 + 6 = 53 bytes, and the live pass
QR is rendered by the CLIENT, whose encoder is a fixed QR version 3 in byte
mode: 53 bytes, no more. THERE IS NOT ONE BYTE SPARE. That is why a pass
names no issuer and why none of the three lengths may grow - lengthening any
of them silently breaks the client's ability to draw the code at all.

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

## State is derived, never swept

`Events::stateOf` is a pure function of (mode, starts, ends, now). There is
no cron here, so a scheduled event's start and end are computed at read time
and NOTHING is pushed when either moment arrives - the client derives it from
the same three values. Caching the CARD is safe; caching the STATE would be
putting a clock in shared memory.

## What is terminal, and what merely freezes

- ENDING an event is terminal and keeps everything. A tournament running at
  the moment of the end FINISHES and is archived: it began while the event
  was live, and a clock must not stop two players mid match.
- DELETING one PURGES it - rows, caches, and the tournament it is running.
  The two get different warnings in the admin popup on purpose.
- Freezing an item instance is unrelated and stays the item registry's.

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
