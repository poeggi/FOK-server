#!/usr/bin/env python3
"""Loopback keep-alive tunnel for the remote smoke run.

The suite is 386 curl invocations, and each one is a separate process, so
each one opens its own TCP connection and does its own TLS handshake to the
host. Measured from a runner that is ~516 ms per request against ~25 ms of
actual work (the host is HTTP/1.1 only, so there is no multiplexing to fall
back on either); ~75% of the smoke step is handshakes.

curl cannot be talked out of that from the outside - the reuse it does have
lives inside one process. So this listens on the loopback, forwards to the
host over connections it keeps open and reuses, and lets the 386 curls pay a
loopback connect (free) instead of a TLS handshake (expensive). Nothing in
the tests changes: only BASE in lib.sh points here instead.

Usage: keepalive-proxy.py https://host    # prints the port it bound to
"""
import http.client
import queue
import sys
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlsplit

UP = urlsplit(sys.argv[1])
POOL = queue.LifoQueue()

# Per-hop headers describe THIS hop, so they must not be forwarded to the
# next one. Content-Length is re-derived on both sides.
HOP = {
    'connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization',
    'te', 'trailers', 'transfer-encoding', 'upgrade', 'host', 'content-length',
}


def connect():
    cls = http.client.HTTPSConnection if UP.scheme == 'https' else http.client.HTTPConnection
    # Longer than any long-poll test waits, so a held request is not cut off.
    return cls(UP.netloc, timeout=120)


class Tunnel(BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'

    def log_message(self, *a):
        pass

    def proxy(self):
        n = int(self.headers.get('Content-Length') or 0)
        body = self.rfile.read(n) if n else b''
        headers = {k: v for k, v in self.headers.items() if k.lower() not in HOP}

        # A pooled connection may have been closed by the host while idle,
        # which surfaces only on use - so one retry on a fresh connection.
        for last in (False, True):
            try:
                conn = POOL.get_nowait()
            except queue.Empty:
                conn = connect()
            try:
                conn.request(self.command, self.path, body, headers)
                res = conn.getresponse()
                data = res.read()
            except Exception:
                try:
                    conn.close()
                except Exception:
                    pass
                if last:
                    raise
                continue
            POOL.put(conn)
            break

        self.send_response_only(res.status, res.reason)
        for k, v in res.getheaders():
            if k.lower() in HOP:
                continue
            if k.lower() == 'set-cookie':
                # The app marks its admin cookie Secure because the host is
                # HTTPS. curl would then refuse to send it back over this
                # plain-HTTP loopback hop, so drop the attribute the tunnel
                # makes moot. The request to the host is still HTTPS.
                v = '; '.join(p for p in v.split('; ') if p.strip().lower() != 'secure')
            self.send_header(k, v)
        self.send_header('Content-Length', str(len(data)))
        self.end_headers()
        if self.command != 'HEAD':
            self.wfile.write(data)

    do_GET = do_POST = do_PUT = do_DELETE = do_OPTIONS = do_HEAD = do_PATCH = proxy


if __name__ == '__main__':
    # Port 0: the OS picks a free one, so a stale tunnel from an aborted run
    # can never answer this run's requests.
    srv = ThreadingHTTPServer(('127.0.0.1', 0), Tunnel)
    srv.daemon_threads = True
    print(srv.server_port, flush=True)
    srv.serve_forever()
