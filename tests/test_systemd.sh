#!/bin/bash
# Boots a Debian 12 container with systemd as init, installs conf/systemd.service
# (rendered like YunoHost does, with the real sandboxing; only ExecStart is a
# stub that writes the socket) plus conf/tmpfiles.conf, and checks that the
# socket directory is madeline:www-data 0750 and stays so across restarts:
# nginx (www-data) can enter it, other users cannot.
# Requires podman (systemd-in-container).

set -euo pipefail

repo="$(cd "$(dirname "$0")/.." && pwd)"
work="$(mktemp -d)"
name="madeline-systemd-test-$$"
trap 'podman rm -f "$name" >/dev/null 2>&1 || true; rm -rf "$work"' EXIT

image="localhost/madeline-systemd-test:debian12"
if ! podman image inspect "$image" >/dev/null 2>&1; then
    echo "Building $image..." >&2
    podman build -q -t "$image" -f - "$work" >/dev/null <<'EOF'
FROM docker.io/library/debian:12
RUN apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq systemd systemd-sysv >/dev/null \
 && useradd --system madeline \
 && mkdir -p /home/yunohost.app/madeline /var/log/madeline \
 && chown madeline: /home/yunohost.app/madeline /var/log/madeline
CMD ["/sbin/init"]
EOF
fi

sed -e 's/__APP__/madeline/g' -e 's|__DATA_DIR__|/home/yunohost.app/madeline|g' \
    -e 's|__INSTALL_DIR__|/var/www/madeline|g' -e 's/__PHP_VERSION__/8.2/g' \
    -e "s|^ExecStart=.*|ExecStart=/bin/sh -c 'touch /run/madeline/daemon.sock \\&\\& exec sleep infinity'|" \
    "$repo/conf/systemd.service" >"$work/madeline.service"
sed 's/__APP__/madeline/g' "$repo/conf/tmpfiles.conf" >"$work/madeline.tmpfiles"
if grep -q '__[A-Z0-9_]*__' "$work/madeline.service" "$work/madeline.tmpfiles"; then
    echo "FAIL: unrendered placeholder"
    exit 1
fi

podman run -d --name "$name" --systemd=always \
    -v "$work/madeline.service:/etc/systemd/system/madeline.service:ro,z" \
    -v "$work/madeline.tmpfiles:/etc/tmpfiles.d/madeline.conf:ro,z" \
    "$image" >/dev/null
for _ in $(seq 30); do podman exec "$name" systemctl is-system-running >/dev/null 2>&1 && break; sleep 1; done

podman exec -i "$name" bash -s <<'EOF'
set -uo pipefail
fail() { echo "FAIL: $*"; journalctl -u madeline --no-pager | tail -20; exit 1; }
pass() { echo "PASS: $*"; }

# Like a fresh boot: /run is empty, the service must recreate the directory
rm -rf /run/madeline
systemctl daemon-reload
systemctl start madeline || fail "the service does not start with its sandboxing"
sleep 1
systemctl is-active --quiet madeline || fail "the service is not running"
[ -e /run/madeline/daemon.sock ] || fail "the service cannot write its socket"
pass "the service starts and can write its socket"

[ "$(stat -c %U:%G:%a /run/madeline)" = madeline:www-data:750 ] || fail "socket dir is $(stat -c %U:%G:%a /run/madeline)"
systemctl restart madeline
sleep 1
[ "$(stat -c %U:%G:%a /run/madeline)" = madeline:www-data:750 ] || fail "after a restart, socket dir is $(stat -c %U:%G:%a /run/madeline)"
pass "the socket dir is madeline:www-data 0750, also after a restart"

su -s /bin/sh www-data -c 'ls /run/madeline' >/dev/null || fail "nginx (www-data) cannot enter the socket dir"
su -s /bin/sh nobody -c 'ls /run/madeline' >/dev/null 2>&1 && fail "other users can enter the socket dir"
pass "only nginx (www-data) can reach the socket"
EOF

echo
echo "All systemd tests passed."
