#!/bin/bash
# Checks src/Normalizer.php against payloads deserialized by MadelineProto
# itself (tests/fixtures/normalizer_test.php), so a change in how MadelineProto
# shapes peers/ids breaks this test instead of production. Offline.
# Requires podman (or docker).

set -euo pipefail

repo="$(cd "$(dirname "$0")/.." && pwd)"
source "$repo/tests/_image.sh"

"$runtime" run --rm -i -v "$repo:/pkg:ro,z" -v "$cache:/root/.cache/composer:z" "$image" bash -s <<'EOF'
set -euo pipefail
cp -r /pkg/sources/app /app && cd /app
rm -rf vendor
composer install --no-dev --no-interaction --no-progress --quiet
php -d zend.assertions=1 /pkg/tests/fixtures/normalizer_test.php
EOF
