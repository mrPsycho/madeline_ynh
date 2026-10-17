<?php declare(strict_types=1);

// Daemon config for tests/test_daemon.sh: same keys as conf/config.php, plus a
// fake account opener so no request ever reaches Telegram.

require __DIR__ . '/FakeAccount.php';

use Madeline\Web\Test\FakeAccount;

return [
    'app'           => 'madeline',
    'version'       => 'test',
    'listen'        => getenv('MADELINE_SOCKET') ?: '/tmp/madeline.sock',
    'data_dir'      => getenv('MADELINE_DATA') ?: '/tmp/madeline-data',
    'log_dir'       => '/tmp',
    'api_id'        => 12345,
    'api_hash'      => '0123456789abcdef0123456789abcdef',
    'upload_max_mb' => 1,
    // Lets tests/test_daemon.sh move the lock's clock forward: seconds in this file
    'lock_clock' => static fn (): float => microtime(true) + (is_file('/tmp/clock-offset') ? (float) file_get_contents('/tmp/clock-offset') : 0.0),
    // Mimics MadelineAccount::open(): null when the user has no session and
    // $create is false; a logged-out (closed) session is gone for good.
    'account_opener' => static function (string $user, bool $create): ?FakeAccount {
        static $accounts = [];
        if (isset($accounts[$user]) && $accounts[$user]->closed) {
            unset($accounts[$user]);
        }
        if (!isset($accounts[$user]) && $create) {
            $accounts[$user] = new FakeAccount($user);
        }
        return $accounts[$user] ?? null;
    },
];
