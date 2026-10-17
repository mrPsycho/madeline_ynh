#!/bin/bash
# Renders conf/nginx.conf exactly like ynh_config_add_nginx does (for a sub-path
# and for the domain root), serves it with nginx + headers-more in front of the
# daemon (fake accounts), and checks routing, headers, identity forwarding,
# live events and upload limits. A stand-in for SSOwat turns the test-only
# "X-Test-Sso: <user>" request header into the YNH_USER header SSOwat injects.
#
#   tests/test_nginx.sh          run the checks
#   tests/test_nginx.sh --serve  serve the UI on http://localhost:8080/telegram/
#                                as user "alice" (fake account, code 12345)
# Requires podman (or docker).

set -euo pipefail

repo="$(cd "$(dirname "$0")/.." && pwd)"
source "$repo/tests/_image.sh"

mode="${1:-test}"
publish=()
[ "$mode" = "--serve" ] && publish=(-p 127.0.0.1:8080:8080)

"$runtime" run --rm -i --init "${publish[@]}" -e MODE="$mode" -v "$repo:/pkg:ro,z" -v "$cache:/root/.cache/composer:z" "$image" bash -s <<'EOF'
set -euo pipefail
mkdir -p /var/www/madeline && cp -r /pkg/sources/app/. /var/www/madeline/ && cd /var/www/madeline
rm -rf vendor
composer install --no-dev --no-interaction --no-progress --quiet
# Same permission model as conf/systemd.service: the daemon runs as its own
# user, its socket directory is madeline:www-data 0750.
useradd --system madeline
mkdir -p /run/madeline /var/lib/madeline-data
chown madeline:www-data /run/madeline && chmod 0750 /run/madeline
chown madeline: /var/lib/madeline-data
su madeline -s /bin/bash -c 'MADELINE_SOCKET=/run/madeline/daemon.sock MADELINE_DATA=/var/lib/madeline-data php -d zend.assertions=-1 bin/daemon.php /pkg/tests/fixtures/config.php' >/tmp/daemon.log 2>&1 &
for _ in $(seq 50); do curl -s -o /dev/null --unix-socket /run/madeline/daemon.sock http://d/status && break; sleep 0.2; done

render() { # path -> rendered app conf, like ynh_config_add + ynh_config_add_nginx
    local path="$1" out
    out="$(cat /pkg/conf/nginx.conf)"
    [ "$path" = "/" ] && out="${out//__PATH__\//\/}"
    out="$(sed -e "s@__PATH__@$path@g" -e "s@__APP__@madeline@g" -e "s@__UPLOAD_MAX_MB__@1@g" \
               -e "s@__INSTALL_DIR__@/var/www/madeline@g" <<<"$out")"
    if [ "$path" != "/" ]; then out="$(sed 's/^#sub_path_only//' <<<"$out")"; else out="$(sed 's/^#root_path_only//' <<<"$out")"; fi
    grep -q '__[A-Z0-9_]*__' <<<"$out" && { echo "FAIL: unrendered placeholder"; exit 1; }
    printf '%s\n' "$out"
}

# What YunoHost provides around app configs: proxy_params_with_auth and the
# $connection_upgrade map (from each domain's conf).
cat >/etc/nginx/proxy_params_with_auth <<'NGINX'
proxy_set_header   Host                 $host;
proxy_set_header   X-Real-IP            $remote_addr;
proxy_set_header   X-Forwarded-For      $proxy_add_x_forwarded_for;
proxy_set_header   X-Forwarded-Proto    $scheme;
proxy_http_version 1.1;
proxy_set_header   Upgrade              $http_upgrade;
proxy_set_header   Connection           $connection_upgrade;
proxy_set_header   Authorization        $http_authorization;
proxy_set_header   Ynh-User             $http_ynh_user;
proxy_set_header   Ynh-User-Email       $http_ynh_user_email;
proxy_set_header   Ynh-User-Fullname    $http_ynh_user_fullname;
proxy_set_header   REMOTE_USER          $http_ynh_user;
proxy_set_header   X-Forwarded-User     $http_ynh_user;
NGINX

serve() { # path [fixed user]
    mkdir -p /etc/nginx/app.d
    render "$1" >/etc/nginx/app.d/madeline.conf
    local sso='more_set_input_headers "YNH_USER: $http_x_test_sso";'
    [ -n "${2:-}" ] && sso="more_set_input_headers \"YNH_USER: $2\";"
    cat >/etc/nginx/sites-enabled/default <<NGINX
map \$http_upgrade \$connection_upgrade {
    default upgrade;
    ''      close;
}
server {
    listen 8080;
    # Stand-in for SSOwat: drop client-sent identity headers, inject the real one
    more_clear_input_headers "YNH_USER" "Ynh-User";
    $sso
    include /etc/nginx/app.d/*.conf;
}
NGINX
    nginx -t -q || { echo "FAIL: nginx rejects the rendered configuration"; exit 1; }
    if [ -n "${nginx_started:-}" ]; then nginx -s reload; sleep 0.5; else nginx; nginx_started=1; fi
}

if [ "$MODE" = "--serve" ]; then
    serve /telegram alice
    echo "Serving http://localhost:8080/telegram/ as alice (phone: any, code 12345). Ctrl-C to stop."
    tail -f /tmp/daemon.log /var/log/nginx/error.log
fi

fail() { echo "FAIL: $*"; echo "--- daemon"; cat /tmp/daemon.log; echo "--- nginx"; cat /var/log/nginx/error.log; exit 1; }
pass() { echo "PASS: $*"; }
get() { curl -s -H "X-Test-Sso: ${2:-}" "localhost:8080$1"; }
head_of() { curl -s -o /dev/null -D - -H "X-Test-Sso: ${2:-}" "localhost:8080$1"; }

for path in /telegram /; do
    serve "$path"
    p="${path%/}"
    echo "--- installed at '$path'"

    if [ "$path" != "/" ]; then
        head_of "$p" | grep -qiE '^location: .*/telegram/' || fail "$p does not redirect to $p/"
        pass "the bare path redirects to the trailing-slash URL"
    fi

    h="$(head_of "$p/" alice)"
    grep -qi '^content-type: text/html' <<<"$h" || fail "index.html not served at $p/"
    grep -qi "^content-security-policy: default-src 'self'" <<<"$h" || fail "no CSP on the page"
    grep -qi '^x-content-type-options: nosniff' <<<"$h" || fail "no nosniff on the page"
    get "$p/app.js" alice | grep -q 'EventSource' || fail "app.js not served"
    get "$p/i18n.js" alice | grep -q 'const STRINGS' || fail "i18n.js not served"
    pass "the static app is served with security headers"

    for leak in "$p/../config.php" "$p/config.php" "$p/../src/Api.php" "$p/../vendor/autoload.php" "$p/api/../../config.php"; do
        code="$(curl -s -o /dev/null -w '%{http_code}' --path-as-is -H 'X-Test-Sso: alice' "localhost:8080$leak")"
        [ "$code" = 200 ] && fail "$leak is reachable"
    done
    pass "nothing outside www/ is reachable"

    get "$p/api/status" alice | grep -q '"user":"alice"' || fail "SSO user not forwarded to the daemon"
    get "$p/api/status" | grep -q 'NOT_AUTHENTICATED' || fail "anonymous request reached the daemon as someone"
    curl -s -H 'Ynh-User: bob' "localhost:8080$p/api/status" | grep -q 'NOT_AUTHENTICATED' || fail "client-sent Ynh-User trusted"
    curl -s -H 'X-Test-Sso: alice' -H 'Ynh-User: bob' "localhost:8080$p/api/status" | grep -q '"user":"alice"' || fail "client-sent Ynh-User overrides the SSO user"
    pass "only the SSO identity reaches the daemon"

    if [ "$path" = /telegram ]; then
        su nobody -s /bin/bash -c "curl -s -m 3 --unix-socket /run/madeline/daemon.sock -H 'Ynh-User: alice' http://d/status" | grep -q '"user"' \
            && fail "another local user can talk to the daemon and impersonate users"
        pass "other local users cannot reach the daemon socket"
    fi

    h="$(head_of "$p/api/status" alice)"
    grep -qi "^content-security-policy: default-src 'self'" <<<"$h" && fail "page CSP leaks onto API responses"
    pass "API responses keep their own headers"

    curl -s -X POST -H 'X-Test-Sso: carol' -H 'X-Madeline: 1' -d '{"phone":"+15550101"}' "localhost:8080$p/api/login/phone" >/dev/null
    curl -s -X POST -H 'X-Test-Sso: carol' -H 'X-Madeline: 1' -d '{"code":"12345"}' "localhost:8080$p/api/login/code" | grep -q '"ready"' || fail "login through nginx failed"
    rm -f /tmp/sse
    curl -s -N -H 'X-Test-Sso: carol' "localhost:8080$p/api/events" >/tmp/sse &
    sse=$!
    sleep 0.5
    curl -s -X POST -H 'X-Test-Sso: carol' -H 'X-Madeline: 1' -d '{"chat":111,"text":"via nginx"}' "localhost:8080$p/api/send" >/dev/null
    sleep 0.5
    kill "$sse" 2>/dev/null || true
    grep -q 'via nginx' /tmp/sse || fail "live events are buffered by nginx (got: $(cat /tmp/sse))"
    pass "live events stream through nginx unbuffered"

    head -c 1200000 /dev/zero >/tmp/big
    code="$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'X-Test-Sso: carol' -H 'X-Madeline: 1' --data-binary @/tmp/big "localhost:8080$p/api/upload?chat=111&name=big")"
    [ "$code" = 413 ] || fail "upload above client_max_body_size got HTTP $code"
    printf 'small' >/tmp/small
    curl -s -X POST -H 'X-Test-Sso: carol' -H 'X-Madeline: 1' -H 'Content-Type: text/plain' --data-binary @/tmp/small "localhost:8080$p/api/upload?chat=111&name=s.txt" | grep -q '"ok":true' \
        || fail "small upload through nginx failed"
    pass "uploads pass through nginx and respect upload_max_mb"

    curl -s -D /tmp/lock.h -o /dev/null -X POST -H 'X-Test-Sso: carol' -H 'X-Madeline: 1' -d '{"passcode":"1234","timeout":5}' "localhost:8080$p/api/lock/settings"
    grep -qi "^set-cookie: madeline_unlock=[0-9a-f]*; Path=$p/api;" /tmp/lock.h || fail "unlock cookie not scoped to $p/api ($(grep -i set-cookie /tmp/lock.h))"
    curl -s -H 'X-Test-Sso: carol' "localhost:8080$p/api/dialogs" | grep -q '"LOCKED"' || fail "lock not enforced through nginx"
    cookie="$(grep -i '^set-cookie:' /tmp/lock.h | sed -E 's/^[^=]*=([^;]*).*/\1/' | tr -d '\r')"
    curl -s -X POST -H 'X-Test-Sso: carol' -H 'X-Madeline: 1' -H "Cookie: madeline_unlock=$cookie" -d '{"current":"1234"}' "localhost:8080$p/api/lock/disable" | grep -q '"enabled":false' \
        || fail "lock could not be disabled through nginx"
    pass "the unlock cookie is scoped to the API path"
done

echo
echo "All nginx tests passed."
EOF
