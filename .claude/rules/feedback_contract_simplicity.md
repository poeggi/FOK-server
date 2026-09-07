# The contract makes fewer demands, never more cases

When docs/API.md changes, the direction is towards freedom for the
client: state what the server checks, state the facts a client needs
(drift, sleep), and leave WHEN and HOW OFTEN to the client. Do not add
a list of situations a client MUST act in.

**Why:** asked for on 2026-09-07 while the clock-sync cadence was
rewritten. A six-case "when to sweep" list was replaced by the two
things start.php actually verifies (no future pts; a begin-play reading
younger than 2 s) plus two facts, and the client decides the rest.

**How to apply:** before writing a MUST, ask what the server would do
if the client did not. If the answer is "nothing it can see", it is
not a demand - describe the check that exists, or say it is the
client's business. Procedure (how to sample) may stay normative where
a bad input hurts a third party; cadence is not.
