# Version bump rule

"Minor version" means bump the LAST digit: 0.16.1 -> 0.16.2, never a
semver minor (0.17.0). The scheme is <major>.<feature>.<fix>; the middle
digit moves only on explicit instruction - ask if a change looks
feature-sized.

Tag releases with the bare number (`0.16.2`, no `v` prefix).

Versions only ever move FORWARD (the deploy's live-verify compares the
reported version), so a wrong bump is corrected with a new commit, never a
history rewrite. Any change under public/assets/ needs its own bump - see
the cache-busting rule in CLAUDE.md.
