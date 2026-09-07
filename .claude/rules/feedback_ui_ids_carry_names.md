# An id on screen always carries its name

Wherever a player id appears in the admin UI, the name it resolves to
appears beside it. The tables are keyed on ids; an operator reads about
people.

**Why:** a bare id cannot be triaged. Asked for on 2026-09-07, when the
item popup offered "assign to 52fdd48a" with nothing to say who that is.

**How to apply:** `idCell(id, name)` and `partyCell(v, name)` in
public/assets/admin.js put the name after the id - feed them the name the
payload carries. Where a payload has none, add it server-side:
`AdminData::namesFor()` answers a whole set of ids in one query, and the
admin payloads carry it as a `names` map beside the rows. A missing name
is a real answer, not an error - `Presence::forget` keeps what somebody
owned and drops the person - and then the id stands alone.

Related: [[feedback_live_test_user_naming]]
