<?php declare(strict_types=1);

/**
 * Madeline web client daemon: serves the JSON API on a Unix socket that only
 * nginx can reach (it proxies <path>/api/ to it, with the SSO user in the
 * Ynh-User header) and hosts every user's MadelineProto session.
 *
 * Usage: php bin/daemon.php [config.php]
 */

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Driver\DefaultHttpDriverFactory;
use Amp\Http\Server\Driver\SocketClientFactory;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket\ResourceServerSocketFactory;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Madeline\Web\AccountManager;
use Madeline\Web\Api;
use Madeline\Web\MadelineAccount;
use Monolog\Logger as Monolog;
use Psr\Log\LogLevel;

use function Amp\async;
use function Amp\ByteStream\getStdout;
use function Amp\trapSignal;

require dirname(__DIR__) . '/vendor/autoload.php';

$config = require $argv[1] ?? dirname(__DIR__) . '/config.php';

$handler = new StreamHandler(getStdout(), LogLevel::INFO);
$handler->setFormatter(new ConsoleFormatter);
$logger = new Monolog('madeline', [$handler]);

umask(0077);
foreach (['sessions', 'tmp', 'locks'] as $sub) {
    if (!is_dir($config['data_dir'] . "/$sub")) {
        mkdir($config['data_dir'] . "/$sub", 0700, true);
    }
}
// Nothing is open yet: safe to drop what earlier logouts and uploads left behind
MadelineAccount::cleanup($config['data_dir']);
array_map(Madeline\Web\Fs::remove(...), glob($config['data_dir'] . '/tmp/upload-*') ?: []);

// Tests inject a fake account opener through the config file
$opener = $config['account_opener'] ?? static fn (string $user, bool $create): ?MadelineAccount
    => MadelineAccount::open($config, $user, $create, $logger);
$accounts = new AccountManager($opener, $logger);

$server = new SocketHttpServer(
    $logger,
    new ResourceServerSocketFactory,
    new SocketClientFactory($logger),
    // amphp closes a response that stays silent for this long; live-event
    // streams send a keepalive every 10 s (see Api), slow uploads get slack too.
    httpDriverFactory: new DefaultHttpDriverFactory($logger, streamTimeout: 60, connectionTimeout: 60),
);

// The daemon trusts the Ynh-User header, so it must only be reachable by
// nginx: a Unix socket in a directory that only www-data may enter (see
// conf/systemd.service). A TCP port would let any local user or app in.
$listen = (string) $config['listen'];
if (!str_starts_with($listen, '/')) {
    throw new RuntimeException("'listen' must be the path of a Unix socket, got '$listen'");
}
Madeline\Web\Fs::remove($listen);
$server->expose($listen);
$uploadMax = max(1, (int) $config['upload_max_mb']) * 1024 * 1024;
// Tests inject a clock they can move forward through the config file
$locks = new Madeline\Web\LockStore($config['data_dir'] . '/locks', $config['lock_clock'] ?? null);
$server->start(new Api($accounts, $locks, $config['data_dir'] . '/tmp', $uploadMax, $logger), new DefaultErrorHandler);
// The directory restricts access; the socket itself must be connectable by nginx
chmod($listen, 0666);
$logger->notice("Madeline web client {$config['version']} listening on {$listen}");

// Load every saved session in the background, so that users find their chats
// (and live updates) right away instead of waiting for the session to load
// on their first request after a restart.
async(static function () use ($accounts, $config, $logger): void {
    foreach (glob($config['data_dir'] . '/sessions/*/current') ?: [] as $pointer) {
        $user = basename(dirname($pointer));
        if (!preg_match(Api::USER_RE, $user)) {
            continue;
        }
        try {
            $account = $accounts->get($user);
            if ($account !== null && $account->status()['state'] !== 'ready') {
                $accounts->drop($user, false);
            }
        } catch (Throwable $e) {
            $logger->warning("Could not load the session of $user: {$e->getMessage()}");
        }
    }
    $logger->notice("{$accounts->loadedCount()} session(s) loaded");
})->ignore();

try {
    if (\extension_loaded('pcntl')) {
        $signal = trapSignal([SIGINT, SIGTERM]);
        $logger->notice("Received signal $signal, shutting down");
    } else {
        $logger->warning('ext-pcntl is missing: sessions are only saved periodically, not on shutdown');
        (new Amp\DeferredFuture)->getFuture()->await();
    }
} catch (Throwable $e) {
    // MadelineProto installs its own SIGINT/SIGTERM handlers, which throw
    $logger->notice('Shutting down: ' . $e->getMessage());
}

$server->stop();
$accounts->shutdown();
gc_collect_cycles();
if (class_exists(danog\MadelineProto\API::class, false)) {
    danog\MadelineProto\API::finalize();
}
$logger->notice('Bye');
