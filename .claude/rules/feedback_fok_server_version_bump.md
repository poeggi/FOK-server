# Version bump rule

"Minor version" means bump the LAST digit: 0.16.1 -> 0.16.2, never a
semver minor (0.17.0). The scheme is <major>.<feature>.<fix>.

A CHANGE TO THE API CONTRACT BUMPS THE MIDDLE DIGIT, and the fix digit
goes back to 0: a release that moves FOK_API_VERSION at all - a MINOR of
the contract counts - is 1.4.18 -> 1.5.0, never 1.4.19. Asked for on
2026-09-08, after the API 4.6 friend-delta release went out as 1.4.19.
Outside an API change the middle digit still moves only on explicit
instruction; ask if a change looks feature-sized.

Tag releases with the bare number (`0.16.2`, no `v` prefix).

Versions only ever move FORWARD (the deploy's live-verify compares the
reported version), so a wrong bump is corrected with a new commit, never a
history rewrite. Any change under public/assets/ needs its own bump - see
the cache-busting rule in CLAUDE.md.
