# Streaming push is NOT viable on this host; long-poll is

The host (Hetzner shared hosting, Apache + PHP-FPM via mod_proxy_fcgi)
BUFFERS streamed output at ~64KB between Apache and FPM. Small flush()ed
writes do NOT reach the client until the buffer fills or the script ends.
Measured on the live host: events emitted 1 s apart arrived bunched at
script end; only >=64KB per write flushed in real time (32KB irregular,
<=16KB fully buffered). `FcgidOutputBufferSize` is rejected in .htaccess
(HTTP 500) and there is no PHP knob under FPM (php_value is mod_php-only).

- Server-Sent Events / ANY incremental streaming push is NOT viable here:
  forcing real-time flush needs ~64KB padding per message (~600x blowup
  on a ~100B game message - prohibitive bandwidth).
- Long-poll (poll.php / relay.php GET) works FINE despite this, precisely
  because it computes-holds-then-sends ONE response at script end;
  buffering never applies to a single end-of-script write. That is why
  long-poll is the right pattern on this host and streaming is not.
- Real server push (SSE or WebSocket) MUST live on a VPS/container
  event-loop hub. That hub is also the only thing that lifts the
  concurrent-relay-duel ceiling: PHP-FPM = 1 worker per held connection,
  measured pool ~20, so relay_max_duels*2 <= 20. A held SSE/WS on FPM is
  NOT cheaper than long-poll (same 1 worker per connection); only an
  event loop (1 fd per connection) changes the economics.
- Transport confirmed on the host: HTTPS only, TLS 1.3, HTTP/2 (ALPN h2),
  keep-alive/persistent connections; no HTTP/3 (no alt-svc). Each held
  request still pins 1 worker regardless of HTTP version.
