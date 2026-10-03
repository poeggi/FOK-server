# Identity token: the states, the gate, and the pair throttle

docs/API.md "Identity token" is the contract; src/Ident.php is the whole
implementation. docs/PLAN-identity.md is the design it came from.

## The states of an id

    unbound   no ident row. The first hello carrying a `tok` member AT
              ALL (null or a value) binds it: mints and answers. Every
              other request on an unbound id is 401.
    bound     a row. Every request needs its token; a missing or wrong
              one is 401.

bound_ip is where the binding hello came from, '' on a row a schema step
copied off the vault. It is shown to the operator and decides nothing.

`Ident::verify` / `Ident::register` are the decisions and answer an HTTP
status (200, 401, 429); `require` / `hello` turn it into the answer.
`Ident::proves` is the strict yes/no the vault, account.php and the
moderation actions ask beside the gate. It counts nothing.

## The pair throttle

A wrong TOKEN (a string that did not match a bound id) is counted per
(id, address) pair in a fixed 60 s window. The count reaching
`ident_fails_per_min` writes one `Alerts::warn('ident')` line; past it a
wrong token answers 429 `too many attempts` with `retry_after` 60. The
right token is never refused, from any address. A missing token and any
token for an unbound id are never counted. Without APCu nothing is
counted and every wrong token is a 401.

## Where the gate sits, and why hello is different

Every endpoint: right after Util::isValidId and noteCaller, BEFORE
Presence::touch, so nothing runs for an impostor. hello: after EVERY
input is validated, because Ident::hello binds, and a 400 after a bind
would leave the id bound to a token the client was never answered.

## The entry carries the hash

The presence entry has `tok` (the hash, null = unbound). Presence::touch
writes it at the session open through Ident::entryFields, which hits the
per-request memo the gate filled: one row read per session, none while
the player is here, none in poll.php's hold. An entry without a `tok`
key makes Ident::bindingOf read the row once and write it in. bind and
reset call Presence::setTok, so a live entry never lags the row.

## The vault, and the token off the request line

Vault.php stores and hands back; it judges nothing. vault.token_hash is
dead (written as ''), left in place until the host's SQLite is known to
drop columns (>= 3.35). Db::adoptVault stays: the schema 49 step calls it.

Every request that names an id is a POST with `tok` in the body. A GET
query is the request line, and the host's Apache access log records it
on every hit; poll.php and backup.php answer GET with 405.

## The owner's delete, and no device move

`Account::remove` is the ONE removal transaction. The operator's
delete_player keeps scores and vault; the owner's delete (account.php)
takes them too.

There is no device move and no transfer code; do not propose one. The
client's shell mirrors id + tok into the platform keychain, which is
backed up and moves with the device, and a first run with an identity
and no config restores from the vault under it. The token proves the id
from any address, so nothing server-side is needed.

## The harnesses carry the token

The smoke binds every id it uses and carries `tok` on every request:
lib.sh `TOK[]`, `jt` (body member), `qt` (query parameter, only for the
GET-is-405 assertions), `bind` (the tok:null hello, sets R and TOK -
never in a `$(...)`), `bound` (binds what is not bound yet). An id
delete_player removes loses its binding: `unset "TOK[$id]"` beside it.
The remote run hands TOK[] through the env files. 10_ident.sh counts its
wrong tokens exactly: the eleventh from its pair is the 429 it asserts.
test/live-protocol.sh keeps the 7e57 cast's tokens per base in
~/.fok-server-livetest.tok, adopting whatever a hello answers. An id
somebody else bound is a 401 there, and a finding for the operator
(token_reset).
