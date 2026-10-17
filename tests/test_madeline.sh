#!/bin/bash
# Exercises MadelineAccount against the real Telegram servers, without any
# Telegram account: with dummy api_id/api_hash credentials it checks that a
# session is created in-process (no IPC worker), that Telegram's refusal comes
# back as a clean API_ID_INVALID error, that logging out deletes the session,
# and how much memory one session costs. With TG_API_ID/TG_API_HASH set (real
# credentials, never committed) it also runs the login flow on Telegram's test
# data centers. Needs Internet access.
# Requires podman (or docker).

set -euo pipefail

repo="$(cd "$(dirname "$0")/.." && pwd)"
source "$repo/tests/_image.sh"

"$runtime" run --rm -i -e TG_API_ID -e TG_API_HASH -v "$repo:/pkg:ro,z" -v "$cache:/root/.cache/composer:z" "$image" bash -s <<'EOF'
set -euo pipefail
cp -r /pkg/sources/app /app && cd /app
composer install --no-dev --no-interaction --no-progress --quiet
mkdir -p /data/sessions /data/tmp /logs

cat >/tmp/t.php <<'PHP'
<?php
require '/app/vendor/autoload.php';
use Madeline\Web\{ApiError, MadelineAccount};

$fail = static function (string $m): never { echo "FAIL: $m\n"; exit(1); };
$cfg = ['data_dir' => '/data', 'log_dir' => '/logs', 'api_id' => 12345,
        'api_hash' => '0123456789abcdef0123456789abcdef', 'version' => 'test'];
$log = new Psr\Log\NullLogger;

MadelineAccount::open($cfg, 'alice', false, $log) === null || $fail('a session appeared out of nowhere');
is_dir('/data/sessions/alice') && $fail('opening without $create wrote to disk');
echo "PASS: no session is created until the user starts a login\n";

$before = memory_get_usage(true);
$t = microtime(true);
$a = MadelineAccount::open($cfg, 'alice', true, $log);
printf("INFO: session opened in %.1fs, +%d MB of memory\n", microtime(true) - $t, (memory_get_usage(true) - $before) >> 20);
$a->status() === ['state' => 'logged_out'] || $fail('fresh session is not logged_out: ' . json_encode($a->status()));
$session = '/data/sessions/alice/' . trim(file_get_contents('/data/sessions/alice/current'));
is_dir($session) || $fail("session directory $session missing");
foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $f) {
    $p = str_replace("\0", ' ', (string) @file_get_contents($f));
    (str_contains($p, 'madeline-ipc') || str_contains($p, 'entry.php')) && $fail("an IPC worker process was forked: $p");
}
echo "PASS: the session runs inside the daemon process\n";

try {
    $a->sendCode('+15550101');
    $fail('Telegram accepted dummy credentials?!');
} catch (ApiError $e) {
    $e->errorCode === 'API_ID_INVALID' || $fail("unexpected error {$e->errorCode}");
}
echo "PASS: Telegram errors come back as ApiError (API_ID_INVALID)\n";

$a->close(true);
unset($a);
gc_collect_cycles();
danog\MadelineProto\API::finalize();
Amp\delay(1);
file_exists('/data/sessions/alice/current') && $fail('pointer file survived logout');
MadelineAccount::cleanup('/data');
glob('/data/sessions/alice/*.madeline') === [] || $fail('session files survived logout + cleanup: ' . implode(' ', glob('/data/sessions/alice/*')));
MadelineAccount::open($cfg, 'alice', false, $log) === null || $fail('logged-out session can still be opened');
echo "PASS: logout deletes the session\n";

// A login abandoned at the phone step must not leave a session that gets
// re-opened (and costs memory) on every status request.
$c = MadelineAccount::open($cfg, 'bob', true, $log);
try {
    $c->sendCode('+15550101');
} catch (ApiError) {
}
$c->close(false);
unset($c);
gc_collect_cycles();
danog\MadelineProto\API::finalize();
MadelineAccount::open($cfg, 'bob', false, $log) === null || $fail('abandoned login is re-opened');
MadelineAccount::cleanup('/data');
glob('/data/sessions/bob/*.madeline') === [] || $fail('abandoned login left session files behind');
echo "PASS: an abandoned login leaves nothing behind\n";

// With real credentials (TG_API_ID / TG_API_HASH, never committed), check the
// login flow on Telegram's test data centers.
if (getenv('TG_API_ID')) {
    $test = ['api_id' => (int) getenv('TG_API_ID'), 'api_hash' => (string) getenv('TG_API_HASH'), 'test_mode' => true] + $cfg;
    $t = MadelineAccount::open($test, 'carol', true, $log);
    $t->sendCode('+99966' . '2' . random_int(1000, 9999))['state'] === 'code' || $fail('test DC did not send a code');
    foreach (['00000', '00001'] as $wrong) {
        try {
            $t->submitCode($wrong);
            $fail('a wrong code was accepted');
        } catch (ApiError $e) {
            $e->errorCode === 'PHONE_CODE_INVALID' || $fail("wrong code $wrong gave {$e->errorCode}");
        }
        $t->status()['state'] === 'code' || $fail('a wrong code ended the login attempt');
    }
    echo "PASS: a mistyped code can be retried (Telegram test DC)\n";
    $t->close(true);
} else {
    echo "SKIP: login flow on Telegram's test DC (set TG_API_ID and TG_API_HASH)\n";
}
PHP

timeout 120 php -d zend.assertions=-1 /tmp/t.php
EOF
echo
echo "All MadelineProto integration tests passed."
