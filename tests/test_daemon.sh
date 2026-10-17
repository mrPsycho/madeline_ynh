#!/bin/bash
# Runs the daemon (sources/app) in a PHP 8.2 container with fake accounts
# (tests/fixtures) and exercises the JSON API the way nginx + the browser do:
# SSO identity, CSRF header, login flow, per-user isolation, chats, sending,
# uploads, media, live events and logout. Never talks to Telegram.
# Requires podman (or docker).

set -euo pipefail

repo="$(cd "$(dirname "$0")/.." && pwd)"
source "$repo/tests/_image.sh"

"$runtime" run --rm -i -v "$repo:/pkg:ro,z" -v "$cache:/root/.cache/composer:z" "$image" bash -s <<'EOF'
set -euo pipefail
cp -r /pkg/sources/app /app && cd /app
rm -rf vendor
composer install --no-dev --no-interaction --no-progress --quiet
php -d zend.assertions=-1 bin/daemon.php /pkg/tests/fixtures/config.php >/tmp/daemon.log 2>&1 &
daemon_pid=$!
# Every curl below talks to the daemon's Unix socket ("localhost:8095" is just a label)
echo 'unix-socket = "/tmp/madeline.sock"' >~/.curlrc
for _ in $(seq 50); do curl -s -o /dev/null localhost:8095/status && break; sleep 0.2; done

fail() { echo "FAIL: $*"; echo "--- daemon log"; cat /tmp/daemon.log; exit 1; }
pass() { echo "PASS: $*"; }
api() { # user method path [json]
    local args=(-s -X "$2" "localhost:8095$3")
    [ -n "$1" ] && args+=(-H "Ynh-User: $1")
    [ "$2" != GET ] && args+=(-H "X-Madeline: 1" --data-binary "${4:-{\}}")
    curl "${args[@]}"
}
expect() { # output needle message
    grep -qF -- "$2" <<<"$1" || fail "$3 (got: $1)"
}

# --- transport ------------------------------------------------------------
[ -S /tmp/madeline.sock ] || fail "the daemon does not listen on its Unix socket"
grep -qE ':(1F7F|1f7f) ' /proc/net/tcp /proc/net/tcp6 && fail "the daemon listens on a TCP port"
pass "the daemon listens on a Unix socket only"

# --- identity -------------------------------------------------------------
expect "$(api '' GET /status)" '"NOT_AUTHENTICATED"' "request without SSO user accepted"
expect "$(api '../etc' GET /status)" '"NOT_AUTHENTICATED"' "invalid user name accepted"
pass "requests without a valid SSO user are refused"

expect "$(api alice GET /status)" '{"user":"alice","state":"logged_out"}' "new user is not logged_out"
pass "a user without a session is logged out (and nothing is created)"

out="$(curl -s -X POST -H 'Ynh-User: alice' -d '{"phone":"+15550101"}' localhost:8095/login/phone)"
expect "$out" '"CSRF"' "POST without CSRF header accepted"
pass "state-changing requests need the X-Madeline header"

# --- login flow -----------------------------------------------------------
expect "$(api alice POST /login/code '{"code":"12345"}')" '"LOGIN_NOT_STARTED"' "code accepted before phone"
expect "$(api alice POST /login/phone '{"phone":"abc"}')" '"PHONE_NUMBER_INVALID"' "garbage phone accepted"
expect "$(api alice POST /login/phone '{"phone":"+1 000 000 0000"}')" '"PHONE_NUMBER_BANNED"' "Telegram error not passed through"
expect "$(api alice POST /login/phone '{"phone":"+1 (555) 010-1"}')" '"state":"code"' "phone step failed"
expect "$(api alice POST /login/code '{"code":"00000"}')" '"PHONE_CODE_INVALID"' "wrong code accepted"
expect "$(api alice GET /dialogs)" '"LOGGED_OUT"' "chats readable before login completed"
expect "$(api alice POST /login/code '{"code":"12345"}')" '"state":"ready"' "code step failed"
expect "$(api alice GET /status)" '"name":"Fake alice"' "status does not show the account"
pass "phone + code login works and errors are reported"

expect "$(api bob POST /login/phone '{"phone":"+15550102"}')" '"state":"code"' "bob phone step failed"
expect "$(api bob POST /login/code '{"code":"12345"}')" '"state":"password"' "2FA not requested"
expect "$(api bob POST /login/password '{"password":"nope"}')" '"PASSWORD_HASH_INVALID"' "wrong password accepted"
expect "$(api bob POST /login/password '{"password":"secret"}')" '"state":"ready"' "2FA step failed"
pass "two-step verification works"

# --- isolation ------------------------------------------------------------
expect "$(api alice GET '/history?chat=111')" 'Hello alice' "alice cannot read her chat"
api bob GET '/history?chat=111' | grep -q 'Hello alice' && fail "bob can read alice's messages"
expect "$(api carol GET /dialogs)" '"LOGGED_OUT"' "user without login sees chats"
pass "every YunoHost user only sees its own Telegram account"

# --- chats and messages ---------------------------------------------------
out="$(api alice GET /dialogs)"
expect "$out" '"title":"Alice"' "dialog list incomplete"
expect "$out" '"id":-1000000000222' "channel ids are not bot-API style"
expect "$(api alice GET '/history?chat=abc')" '"BAD_REQUEST"' "non-numeric chat id accepted"
expect "$(api alice POST /send '{"chat":111,"text":"   "}')" '"MESSAGE_EMPTY"' "empty message accepted"
long="$(printf 'x%.0s' $(seq 4097))"
expect "$(api alice POST /send "{\"chat\":111,\"text\":\"$long\"}")" '"MESSAGE_TOO_LONG"' "over-long message accepted"
expect "$(api alice POST /send '{"chat":111,"text":"Привет 👋"}')" '{"ok":true}' "send failed"
expect "$(api alice GET '/history?chat=111')" 'Привет 👋' "sent message missing from history"
pass "chat list, history and sending text work (UTF-8 included)"

# --- live events ----------------------------------------------------------
curl -s -N -H 'Ynh-User: alice' localhost:8095/events >/tmp/alice.sse &
curl -s -N -H 'Ynh-User: bob' localhost:8095/events >/tmp/bob.sse &
sleep 0.5
api alice POST /send '{"chat":111,"text":"live!"}' >/dev/null
sleep 0.5
grep -q '"text":"live!"' /tmp/alice.sse || fail "live event not delivered (got: $(cat /tmp/alice.sse))"
grep -q 'live!' /tmp/bob.sse && fail "alice's live event leaked to bob"
grep -q '^retry: 3000' /tmp/alice.sse || fail "SSE stream has no retry hint"
pass "live events reach the user's own tabs only"

# amphp drops responses that stay silent for its stream timeout: the keepalive
# pings must keep an idle stream open well past it.
set +e
timeout 25 curl -s -N -H 'Ynh-User: bob' localhost:8095/events >/tmp/idle.sse
code=$?
set -e
[ "$code" = 124 ] || fail "idle live-event stream was closed by the server (curl exit $code)"
[ "$(grep -c '^: ping' /tmp/idle.sse)" -ge 2 ] || fail "no keepalive pings on an idle stream"
pass "idle live-event streams stay open with keepalive pings"

# --- uploads and media ----------------------------------------------------
printf 'hello file' >/tmp/f.txt
out="$(curl -s -X POST -H 'Ynh-User: alice' -H 'X-Madeline: 1' -H 'Content-Type: text/plain' \
    --data-binary @/tmp/f.txt 'localhost:8095/upload?chat=111&name=../../etc/notes.txt&caption=cap')"
expect "$out" '{"ok":true}' "upload failed"
hist="$(api alice GET '/history?chat=111')"
expect "$hist" "sha1:$(sha1sum /tmp/f.txt | cut -d' ' -f1)" "uploaded content corrupted"
expect "$hist" '"name":"notes.txt"' "upload file name not sanitised"
head -c 1100000 /dev/zero >/tmp/big.bin
out="$(curl -s -X POST -H 'Ynh-User: alice' -H 'X-Madeline: 1' --data-binary @/tmp/big.bin 'localhost:8095/upload?chat=111&name=big.bin')"
expect "$out" '"FILE_TOO_LARGE"' "upload over the limit accepted"
[ -z "$(ls -A /tmp/madeline-data/tmp)" ] || fail "temporary upload files left behind"
pass "uploads are streamed, size-limited, sanitised and cleaned up"

mid="$(grep -oP '"id":\K[0-9]+(?=,"chat":111,[^}]*"kind":"file")' <<<"$hist" | tail -n1)"
expect "$(api alice GET "/media?chat=111&msg=$mid")" "media of alice #$mid" "media download failed"
curl -s -D - -o /dev/null -H 'Ynh-User: alice' "localhost:8095/media?chat=111&msg=$mid&download=1" | grep -qi '^content-disposition: attachment' \
    || fail "download=1 is not served as an attachment"
expect "$(api bob GET "/media?chat=111&msg=$mid")" '"MEDIA_NOT_FOUND"' "bob can fetch alice's media"
pass "media is served to its owner only"

# --- logout ---------------------------------------------------------------
expect "$(api alice POST /logout)" '"state":"logged_out"' "logout failed"
sleep 0.3
grep -q '"type":"logged_out"' /tmp/alice.sse || fail "other tabs are not told about the logout"
expect "$(api alice GET /status)" '{"user":"alice","state":"logged_out"}' "still logged in after logout"
expect "$(api alice GET /dialogs)" '"LOGGED_OUT"' "chats readable after logout"
expect "$(api bob GET /status)" '"state":"ready"' "alice's logout affected bob"
pass "logout ends the session of that user only"

expect "$(api alice GET /nope)" '"NOT_FOUND"' "unknown route not 404"
grep -q 'Unhandled error' /tmp/daemon.log && fail "daemon logged an unhandled error"

kill "$daemon_pid" 2>/dev/null || true
wait 2>/dev/null || true
echo
echo "All daemon API tests passed."
EOF
