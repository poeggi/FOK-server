# Smoke test over real HTTP.
#
# Local mode (default): boots the app on PHP's built-in server with a
# throwaway data dir and fixed test IDs.
#
# Remote mode: SMOKE_BASE=https://host[/staging] runs the same tests
# against a deployed instance with random test IDs. Admin checks and the
# test-data cleanup need FOK_ADMIN_USER/FOK_ADMIN_PASS in the env; they
# are skipped (with a warning) otherwise. Used to verify staging before
# a live deploy.
DATA="$(mktemp -d)"
COOKIES="$DATA/cookies.txt"

if [ -n "${SMOKE_BASE:-}" ]; then
    REMOTE=1
    BASE="${SMOKE_BASE%/}"
    # Every one of the suite's ~390 requests is its own curl process, so
    # every one pays a fresh TLS handshake to the host - ~500 ms each against
    # ~25 ms of real work. keepalive-proxy.py holds the connections open and
    # the requests go over the loopback instead; see its header. It is an
    # accelerator only: if there is no python3, or it does not come up, the
    # suite runs exactly as before, straight at the host. SMOKE_NO_PROXY=1
    # forces that (a run against a host this tunnel cannot reach, or when
    # bisecting the tunnel itself).
    PROXY_PID=''
    if [ -z "${SMOKE_NO_PROXY:-}" ] && command -v python3 >/dev/null; then
        _up="${BASE#*://}"; _origin="${BASE%%://*}://${_up%%/*}"
        _path=''; [ "${_up#*/}" != "$_up" ] && _path="/${_up#*/}"
        python3 "$(dirname "${BASH_SOURCE[0]}")/keepalive-proxy.py" "$_origin" \
            > "$DATA/proxy.port" 2>"$DATA/proxy.log" &
        PROXY_PID=$!
        for _ in $(seq 50); do
            _port=$(head -1 "$DATA/proxy.port" 2>/dev/null)
            [ -n "$_port" ] && break
            sleep 0.1
        done
        if [ -n "${_port:-}" ]; then
            BASE="http://127.0.0.1:$_port$_path"
            echo "     (keep-alive tunnel on 127.0.0.1:$_port -> $_origin)"
        else
            kill "$PROXY_PID" 2>/dev/null || true
            PROXY_PID=''
            echo "     (keep-alive tunnel did not start, going direct)" >&2
        fi
    fi
    ID1=$(od -An -N4 -tx1 /dev/urandom | tr -d ' \n')
    ID2=$(od -An -N4 -tx1 /dev/urandom | tr -d ' \n')
    ID3=$(od -An -N4 -tx1 /dev/urandom | tr -d ' \n')
    ID4=$(od -An -N4 -tx1 /dev/urandom | tr -d ' \n')
    ADMIN_USER="${FOK_ADMIN_USER:-}"
    ADMIN_PASS="${FOK_ADMIN_PASS:-}"
    cleanup() {
        [ -n "$PROXY_PID" ] && kill "$PROXY_PID" 2>/dev/null
        rm -rf "$DATA"
    }
else
    REMOTE=0
    ID1=deadbeef
    ID2=cafe0001
    ID3=f00df00d
    ID4=b0a710ad
    ADMIN_USER=smoke
    ADMIN_PASS=test
    export FOK_DATA_DIR="$DATA"
    php -r 'file_put_contents(getenv("FOK_DATA_DIR")."/admin.hash", password_hash("smoke:test", PASSWORD_DEFAULT));'
    cleanup() {
        [ -n "${SERVER_PID:-}" ] && kill "$SERVER_PID" 2>/dev/null || true
        rm -rf "$DATA"
    }
    # Random port: a stale server from an aborted run must never be able to
    # answer this run's requests. Drawn fresh per attempt, because the one
    # thing a random port can do is collide with something already listening.
    #
    # Then WAIT FOR THE PORT TO ANSWER rather than sleeping a guessed second:
    # on a loaded CI runner php -S can still be binding when the first request
    # goes out, and from there every later assertion fails on a connection
    # that was never made. time.php is the probe because it records nothing
    # (no counters, no player rows) and opens no database, so however many
    # probes a slow boot takes, the counts the suite asserts are the same. A
    # static file would answer before PHP was ready to, which is the one thing
    # this loop must not accept.
    #
    # api/version.txt is written by the deploy, so a local tree has none:
    # write the one a live deploy would (the target a local run stands in for
    # is 'live', which is what EXPECT_ENV computes from a 127.0.0.1 base).
    bash tools/make-version.sh live > /dev/null
    up=0
    for attempt in 1 2 3; do
        PORT=$((8300 + RANDOM % 500))
        BASE="http://127.0.0.1:$PORT"
        php -S "127.0.0.1:$PORT" -t public > "$DATA/server.log" 2>&1 &
        SERVER_PID=$!
        for _ in $(seq 100); do
            if curl -sf -o /dev/null "$BASE/api/time.php"; then
                up=1
                break
            fi
            kill -0 "$SERVER_PID" 2>/dev/null || break
            sleep 0.1
        done
        [ "$up" -eq 1 ] && break
        kill "$SERVER_PID" 2>/dev/null || true
        SERVER_PID=''
    done
    if [ "$up" -ne 1 ]; then
        echo "FAIL: php -S never answered on 127.0.0.1:$PORT"
        cat "$DATA/server.log" >&2
        rm -rf "$DATA"
        exit 1
    fi
fi
trap cleanup EXIT

fail=0
expect() { # expect <name> <needle> <actual>
    if [[ "$3" == *"$2"* ]]; then
        echo "ok   $1"
    else
        echo "FAIL $1: expected '$2' in: $3"
        fail=1
    fi
}
# The other half of expect: what an answer must NOT carry. A key, a secret
# or an online flag that leaks is a silent failure otherwise, because the
# assertion that would have caught it reads the same either way.
refute() { # refute <name> <needle> <actual>
    if [[ "$3" != *"$2"* ]]; then
        echo "ok   $1"
    else
        echo "FAIL $1: did NOT expect '$2' in: $3"
        fail=1
    fi
}
# Player/count assertions are exact locally; on a shared remote instance
# other clients may exist, so only the field's presence is asserted.
strict() { if [ "$REMOTE" -eq 0 ]; then echo "$1"; else echo "${1%%:*}:"; fi; }

# Shorthands for the connection endpoints. Payloads are inserted into the
# JSON verbatim, so keep them free of quotes and backslashes.
sig() { # sig <from> <to> <type> <payload>
    curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$1\",\"to\":\"$2\",\"type\":\"$3\",\"payload\":\"$4\"}" "$BASE/api/signal.php"
}
sigcode() { # like sig, but prints the HTTP status instead of the body
    curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$1\",\"to\":\"$2\",\"type\":\"$3\",\"payload\":\"$4\"}" "$BASE/api/signal.php"
}
rly() { # rly <from> <peer> <payload>
    curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$1\",\"peer\":\"$2\",\"payload\":\"$3\"}" "$BASE/api/relay.php"
}
rlycode() { # like rly, but prints the HTTP status instead of the body
    curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$1\",\"peer\":\"$2\",\"payload\":\"$3\"}" "$BASE/api/relay.php"
}
rlypull() { # rlypull <from> <peer> <payload> : POST with pull, print body
    curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$1\",\"peer\":\"$2\",\"payload\":\"$3\",\"pull\":true}" "$BASE/api/relay.php"
}
hello() { # hello <id>
    curl -s -X POST -H 'Content-Type: application/json' -d "{\"id\":\"$1\"}" "$BASE/api/hello.php"
}
setting() { # setting <key> <value>
    curl -s -b "$COOKIES" -X POST -d "$1=$2" "$BASE/admin/api.php?action=settings_save" > /dev/null
}
# Asserts <needle-a> appears before <needle-b>: order is part of the
# contract for both mailboxes.
ordered() { # ordered <name> <first> <second> <actual>
    if [[ "$4" == *"$2"* && "$4" == *"$3"* && "${4%%$3*}" == *"$2"* ]]; then
        echo "ok   $1"
    else
        echo "FAIL $1: expected '$2' before '$3' in: $4"
        fail=1
    fi
}

# Log in to the admin API up front (when creds are available) so the rate
# and ban tests below can lower their caps via `setting` - a couple of
# requests instead of a flood. Without creds (a bare remote diagnostic run)
# they fall back to flooding at the default caps. The admin section later
# re-tests the login flow in full; this early login does not disturb it.
ADMIN=0
if [ -n "$ADMIN_USER" ]; then
    curl -s -o /dev/null -c "$COOKIES" -X POST \
        --data-urlencode "do=login" --data-urlencode "user=$ADMIN_USER" --data-urlencode "pass=$ADMIN_PASS" \
        "$BASE/admin/index.php"
    ADMIN=1
fi

# The per-id friend-request throttle (API 3.5) is an anti-probe guard that
# 429s a second "request" from the same id within a second. Several tests
# below legitimately fire two requests from one id back to back (quicker than
# the 1 s default), so disable it suite-wide here; 04_matchmaking.sh re-enables
# it at tight caps for its own focused throttle test. Needs admin (no creds =
# a bare remote diagnostic, which skips the throttle-dependent tests anyway).
if [ "$ADMIN" -eq 1 ]; then
    setting friend_rate_interval 0
    setting friend_rate_burst 1000000
    # The tournament sweep gate is an apcu_add with the setting as its TTL,
    # and apcu_add never renews a key that exists. With the default 30 s every
    # part below re-takes it at some unknowable moment, and 09_sweep.sh's
    # gate test - which needs the gate HELD across its own few requests -
    # passes or fails on where that expiry happens to fall. So the first
    # client request of the run takes it for longer than any run lasts, and
    # 09 finds it held. 09 sets the gap to 0 wherever it wants a sweep, which
    # bypasses the key, and puts the default back at its end.
    setting tournament_sweep_secs 900
fi

# ---- helpers more than one part uses -------------------------------------
# A part has to be able to run without the parts before it (the remote run
# groups them), so anything two parts share is defined here, once.

# A start carries a sync proof (pts) in server-clock ms. Rather than read
# /api/time.php for every one, learn the client<->server skew ONCE and then
# compute pts locally - the server tolerates minutes of drift, so second
# resolution is ample. (The pts logic itself is exercised in unit.php.)
# Millisecond-resolution local clock; the round-trip latency biases SKEW
# slightly negative, so a computed pts lands just in the PAST - never the
# future the sync gate rejects.
_srv_ms=$(curl -s "$BASE/api/time.php" | grep -oE '"t":[0-9]+' | cut -d: -f2)
SKEW=$(( _srv_ms - $(date +%s%3N) ))
now_ms() { echo $(( $(date +%s%3N) + SKEW )); }
start_req_private() { # id peer epoch reason pts
    curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$1\",\"peer\":\"$2\",\"epoch\":$3,\"reason\":\"$4\",\"pts\":$5,\"duel_private\":true}" \
        "$BASE/api/start.php"
}
start_req() { # id peer epoch reason pts
    curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$1\",\"peer\":\"$2\",\"epoch\":$3,\"reason\":\"$4\",\"pts\":$5}" \
        "$BASE/api/start.php"
}

tourney() { # tourney <json-body> : POST to api/tournament.php, print the body
    curl -s -X POST -H 'Content-Type: application/json' -d "$1" "$BASE/api/tournament.php"
}
tcode() { # like tourney, but prints the HTTP status instead
    curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
        -d "$1" "$BASE/api/tournament.php"
}
# A no-match grep exits 1, which would abort the run under `set -e` instead of
# failing an assertion - so the extractor swallows it (see 05_items.sh).
tfield() { echo "$1" | grep -oE "\"$2\":\"[0-9A-Za-z]+\"" | head -1 | cut -d'"' -f4 || true; }
act() { # act <id> <action> <tid>
    tourney "{\"id\":\"$1\",\"action\":\"$2\",\"tid\":\"$3\"}"
}
result() { # result <id> <tid> <nid> <outcome> <mine> <theirs>
    tourney "{\"id\":\"$1\",\"action\":\"result\",\"tid\":\"$2\",\"nid\":\"$3\",\"outcome\":\"$4\",\"score\":[$5,$6]}"
}
hellot() { # hello asking for the lobbies announced on the caller's address
    curl -s -X POST -H 'Content-Type: application/json' \
        -d "{\"id\":\"$1\",\"tourneys\":true}" "$BASE/api/hello.php"
}
