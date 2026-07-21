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
    ID1=$(od -An -N4 -tx1 /dev/urandom | tr -d ' \n')
    ID2=$(od -An -N4 -tx1 /dev/urandom | tr -d ' \n')
    ID3=$(od -An -N4 -tx1 /dev/urandom | tr -d ' \n')
    ID4=$(od -An -N4 -tx1 /dev/urandom | tr -d ' \n')
    ADMIN_USER="${FOK_ADMIN_USER:-}"
    ADMIN_PASS="${FOK_ADMIN_PASS:-}"
    cleanup() { rm -rf "$DATA"; }
else
    REMOTE=0
    # Random port: a stale server from an aborted run must never be able
    # to answer this run's requests.
    PORT=$((8300 + RANDOM % 500))
    BASE="http://127.0.0.1:$PORT"
    ID1=deadbeef
    ID2=cafe0001
    ID3=f00df00d
    ID4=b0a710ad
    ADMIN_USER=smoke
    ADMIN_PASS=test
    export FOK_DATA_DIR="$DATA"
    php -r 'file_put_contents(getenv("FOK_DATA_DIR")."/admin.hash", password_hash("smoke:test", PASSWORD_DEFAULT));'
    php -S "127.0.0.1:$PORT" -t public > "$DATA/server.log" 2>&1 &
    SERVER_PID=$!
    cleanup() {
        kill "$SERVER_PID" 2>/dev/null || true
        rm -rf "$DATA"
    }
    sleep 1
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
