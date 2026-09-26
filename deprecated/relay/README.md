# The HTTP relay (not deployed)

This directory is reference code. Nothing under `public/` requires it,
the deploy copies only `public/`, and no test runs it. It does not run
anywhere.

The relay carried a duel's in-game messages through this server when two
peers could not open a WebRTC DataChannel. A duel with no direct path now
uses TURN on the same DataChannel (docs/API.md, "TURN credentials"), and
the contract records the relay as withdrawn (docs/API.md, "Relay
fallback, withdrawn").

The last release that wired it in is 1.20.1, commit `6f92356`. Read the
call sites there, for example with `git show 6f92356:public/api/signal.php`.


## What is here

The layout mirrors `public/`, so the relative `require` paths read as they
did in the webroot.

- `api/relay.php`: the endpoint. A POST with a `payload` sends one message
  to the peer. A POST with `"pull": true` also hands back the poster's own
  pending messages. A read (a POST without `payload`, or a GET) is held up
  to `wait` seconds for the peer's next message. A held read answers
  `{"ok":true,"gone":true}` once the pair is torn down.
- `src/Relay.php`: the facade. Every relay concept the rest of the server
  needed went through one of its statics: the slot accounting (which pairs
  are relaying, the duel cap), the leave signal, the off switch.
- `src/RelayStore.php`: the message store. One stream per direction of a
  pair, in APCu only, with a sequence and an acknowledgement per stream,
  so a message is delivered exactly once and in order.
- `src/RelayRate.php`: the per-client send-rate guard (429 above a
  sustained rate, then a block of some seconds).


## How it was wired in

- `api/signal.php` accepted two more types, `invite-relay` and
  `accept-relay`. They declared relay mode up front (the "no-P2P bit")
  and were refused with 503 when the relay was full or switched off.
  A `bye` dropped the pair's undelivered backlog.
- `src/ConnTrack.php` kept a `mode` (`p2p` or `relay`) and a `relay_seen`
  stamp per tracked connection. A slot was held by real hub traffic, never
  by a declaration, and a `bye` handed it back at once.
- `src/Settings.php` had six `relay_*` settings, and `src/Config.php` the
  relay window, the tracking throttle and the tight hold-loop interval.
- The admin dashboard showed a Relaying bubble with a 24 h graph, a Mode
  and a Msgs column on the Duels card, and relay counters in the client
  popup.


## Why it went

A relayed duel is two held long polls, and PHP-FPM gives each held request
a worker of its own. This host has about 20 workers for everything, so the
relay could only ever carry a handful of duels, at 200-400 ms one way. A
relay that scales needs an event loop (one socket per connection, not one
worker), which shared hosting cannot run. TURN is that event loop, run by
Cloudflare.
