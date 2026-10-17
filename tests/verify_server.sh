#!/bin/bash
# Run ON THE SERVER, as root, after installing/upgrading the app, to check the
# deployment: service, permissions, loopback-only daemon, nginx + SSO wiring.
# Read-only: it never logs into Telegram nor creates sessions.
#
#   sudo bash tests/verify_server.sh [app_id]

set -uo pipefail

app="${1:-madeline}"
settings="/etc/yunohost/apps/$app/settings.yml"
[ -r "$settings" ] || { echo "Run as root on a server where $app is installed"; exit 1; }

setting() { grep -oP "^$1: '?\K[^']*" "$settings"; }
install_dir="$(setting install_dir)"
data_dir="$(setting data_dir)"
sock="/run/$app/daemon.sock"
domain="$(setting domain)"
path="$(setting path)"

status=0
check() { # description, command...
    local what="$1"
    shift
    if "$@" >/dev/null 2>&1; then echo "PASS: $what"; else echo "FAIL: $what"; status=1; fi
}

check "service $app is active" systemctl is-active --quiet "$app"
check "daemon listens on $sock" test -S "$sock"
check "socket dir is $app:www-data 0750" bash -c "[ \"\$(stat -c %U:%G:%a /run/$app)\" = '$app:www-data:750' ]"
check "daemon has no TCP listener" bash -c "! ss -ltnp | grep -q \"pid=\$(systemctl show -p MainPID --value $app),\""
check "other local users cannot reach the daemon" bash -c "! sudo -u nobody curl -s -m 3 --unix-socket '$sock' http://d/status"
check "config.php is owner-only" bash -c "[ \"\$(stat -c %a '$install_dir/config.php')\" = 600 ]"
check "sessions are owner-only" bash -c "[ \"\$(stat -c %U:%a '$data_dir/sessions')\" = '$app:700' ]"
check "nginx (www-data) can read the UI" sudo -u www-data test -r "$install_dir/www/index.html"
check "nginx (www-data) cannot read config.php" bash -c "! sudo -u www-data cat '$install_dir/config.php'"
check "nginx (www-data) cannot read the sessions" bash -c "! sudo -u www-data ls '$data_dir/sessions'"
check "daemon refuses requests without a user" bash -c "curl -s --unix-socket '$sock' http://d/status | grep -q NOT_AUTHENTICATED"
check "daemon answers for a user (without creating a session)" bash -c "curl -s --unix-socket '$sock' -H 'Ynh-User: verify-probe' http://d/status | grep -q '\"state\":\"logged_out\"' && [ ! -e '$data_dir/sessions/verify-probe' ]"

base="https://$domain${path%/}"
check "anonymous visitors are sent to the SSO" bash -c "curl -sk -o /dev/null -w '%{http_code} %{redirect_url}' --resolve '$domain:443:127.0.0.1' '$base/' | grep -qE '^30[0-9] .*(yunohost/sso|portal)'"
check "anonymous API calls never reach the daemon as a user" bash -c "! curl -sk --resolve '$domain:443:127.0.0.1' -H 'Ynh-User: admin' '$base/api/status' | grep -q '\"user\"'"
check "Telegram data centers are reachable" bash -c "timeout 5 bash -c '</dev/tcp/149.154.167.51/443'"

echo
journalctl -u "$app" -n 3 --no-pager 2>/dev/null | tail -n 3
tail -n 3 "/var/log/$app/$app.log" 2>/dev/null
echo
[ "$status" = 0 ] && echo "All server checks passed." || echo "Some server checks FAILED."
exit "$status"
