# Admin tiles share one vocabulary, never their own

Every card looks and behaves the same because it is built from the same
parts, not because each one was styled to match. A new tile reaches for an
existing helper. If none fits, ADD one and use it everywhere it applies -
never a local variant.

**Why:** asked for on 2026-09-08, beside the scroll-position and
refresh-flash fixes. Both read as one tile's bug and both were only fixed
once the fix sat in the framework, where every tile inherits it.

The parts that exist (public/assets/admin.js): `pane()` for the one scroll
region of a body, `toolbar()` for the bar above it, `bubbles()` for a
figure grid, `tabs()` for a tabbed card, `sortable()` for a sortable table,
`row()` / `idCell()` / `ipCell()` / `iconBtn()`, `makeModal()` /
`infoModal()` / `confirmModal()` for popups, `flash()` for the pulse every
refresh gives its own button, `follows()` for a popup that runs on its
parent card's interval, and the scroll memory keyed on card plus open tab.

The look lives in admin.css and only there: the height model at the top of
the file, `--ctl-h` / `--ctl-w` for head controls, `button.small` for every
small button, the semantic colour pairs. A card needing a new size or
colour adds a variable; it does not hard-code one.

**How to apply:** before writing markup in a module, look for the helper.
Before writing a rule in admin.css, look for the variable. If a fix is
worth making on one tile, make it in the framework so every tile has it.
