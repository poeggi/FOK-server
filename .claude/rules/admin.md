# The admin dashboard: one vocabulary, names beside ids

## Tiles share one vocabulary, never their own

Every card looks and behaves the same because it is built from the same
parts, not because each one was styled to match. A new tile reaches for
an existing helper. If none fits, ADD one and use it everywhere it
applies; never a local variant. A fix worth making on one tile goes into
the framework, where every tile inherits it.

The parts (public/assets/admin.js): `pane()` for the one scroll region
of a body, `toolbar()` for the bar above it, `bubbles()` for a figure
grid, `tabs()` for a tabbed card, `sortable()` for a sortable table,
`row()` / `idCell()` / `ipCell()` / `iconBtn()`, `makeModal()` /
`infoModal()` / `confirmModal()` for popups, `flash()` for the pulse
every refresh gives its own button, `follows()` for a popup that runs on
its parent card's interval, and the scroll memory keyed on card plus
open tab.

The look lives in admin.css and only there: the height model at the top
of the file, `--ctl-h` / `--ctl-w` for head controls, `button.small` for
every small button, the semantic colour pairs. A card needing a new size
or colour adds a variable; it never hard-codes one.

Before writing markup in a module, look for the helper. Before writing
a rule in admin.css, look for the variable.

## An id on screen always carries its name

Wherever a player id appears in the admin UI, the name it resolves to
appears beside it. The tables are keyed on ids; an operator reads about
people, and a bare id cannot be triaged.

`idCell(id, name)` and `partyCell(v, name)` put the name after the id:
feed them the name the payload carries. Where a payload has none, add it
server-side: `AdminData::namesFor()` answers a whole set of ids in one
query, and the payloads carry it as a `names` map beside the rows. A
missing name is a real answer, not an error (`Presence::forget` keeps
what somebody owned and drops the person); then the id stands alone.

## Decided

- NEVER CHAIN TWO confirm() DIALOGS: a browser may suppress the second,
  and a suppressed confirm() returns CANCEL. Arm-then-confirm in the
  card, with the armed state outside the DOM.
- No admin surface for a frozen knockout node.
- No explanatory prose under the admin tournament tables.
