# Identity token: three states, one gate, and what closes on the date

Shipped in 1.17.0 (API 4.20, schema 49); the POST forms in 1.18.0 (API
4.21). docs/PLAN-identity.md is the
design and its status list; docs/API.md "Identity token" is the contract.
This is what bites somebody who changes the code without reading either.

## The states of an id (src/Ident.php)

    unbound     no ident row. hello binds it - the first hello carrying a
                `tok` member AT ALL (null or a value) mints and answers.
    copied      a row schema 49 copied off vault.token_hash, bound_ip ''.
                The owner's pre-4.20 client sends that token on backup.php
                and nowhere else, so a key-less request still passes on
                it until the cutoff. The first hello presenting the token
                CONFIRMS it (bound_ip set); T2's mint makes the same kind
                of row.
    confirmed   bound_ip set. A wrong or missing token is 401, any date.

`Ident::verify` / `Ident::register` are the decisions, `require` / `hello`
turn a no into 401. A wrong TOKEN (a string that did not match) is counted
per (id, address) pair, `Alerts::warn('ident')` once per window as the
pair crosses `ident_fails_per_min`; a missing token is never counted.

## Where the gate sits, and why hello is different

Every endpoint: right after Util::isValidId and noteCaller, BEFORE
Presence::touch - nothing runs for an impostor. hello: after EVERY input
is validated, because Ident::hello binds, and a 400 after a bind would
leave the id bound to a token the client was never answered.

## The entry carries the hash

The presence entry has `tok` (hash, null = unbound) and `tokc`
(confirmed). Presence::touch writes them at the session open through
Ident::entryFields, which hits the per-request memo the gate filled - one
row read per session, none while the player is here, none in poll.php's
hold. An entry from before 1.17.0 has no `tok` key: Ident::bindingOf reads
the row once and writes it in. bind / confirm / reset all call
Presence::setTok, so a live entry never lags the row.

## The vault is the strict case

backup.php passes the gate like everyone, then asks `Ident::proves`: a
bound id's backup is read and replaced by its token and nothing else,
whatever T1 lets through elsewhere. Vault.php stores and hands back; it
judges nothing. vault.token_hash is dead (written as '' since 1.17.0),
left in place until the host's SQLite is known to drop columns.

## The token is never on a request line (4.21)

A GET query is the request line, and the host's Apache access log
records it on every hit - so `tok` in a query was the token of every
player in a log this server does not write. Since 4.21 poll.php, the
relay's held read (a POST with NO payload member) and the vault restore
(`restore: true`) answer POST bodies with the same members; every harness
here speaks POST. The GET forms still answer for clients built before
4.21 and are TEMPORARY(ident).

## TEMPORARY(ident)

Grep-able. T1 (Ident::verify / register), T2 (backup.php's mint and the
`token` alias), T3 (the smoke's legacy assertions), T4 (the contract
paragraph), and the three GET forms above. All gated at runtime on
Ident::LEGACY_UNTIL, which Ident::setLegacyUntil moves for the unit
tests; test/checks.sh fails from 2026-10-01 while any tagged line
survives outside the plan and itself. Step 9 deletes them, bumps to
5.0 / 1.19.0, and turns the count into 429.

## The harnesses are 4.20 clients

The smoke carries `tok` on every request: lib.sh `TOK[]`, `jt` (body
member), `qt` (query parameter), `bind` (the tok:null hello, sets R and
TOK - never in a `$(...)`), `bound` (group heads). An id delete_player
removes loses its binding: `unset "TOK[$id]"` beside it. The remote run
hands TOK[] through the env files. test/live-protocol.sh keeps the 7e57
cast's tokens per base in ~/.fok-server-livetest.tok, adopting whatever a
hello answers; an id somebody else bound is a 401 there, and a finding
for the operator (token_reset).
