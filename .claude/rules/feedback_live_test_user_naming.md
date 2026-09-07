# Live-test user naming

Every virtual user/player a test creates or reuses on a LIVE instance must
be named `srv-CI-<something>` (e.g. `srv-CI-alice`), so an operator
looking at real data can tell test rows from real ones at a glance. Live
test data sits next to real users; an unrecognizable name cannot be
triaged or cleaned up safely.

The marker goes in the NAME field. Ids stay whatever the protocol requires
- ids are 8 hex chars and come from the fixed set in
feedback_live_test_fixed_ids.md; the name is what carries the label.
