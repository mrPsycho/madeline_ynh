<?php declare(strict_types=1);

/**
 * Madeline web client daemon: serves the JSON API on loopback (nginx proxies
 * <path>/api/ to it) and hosts every user's MadelineProto session.
 *
 * Usage: php bin/daemon.php [config.php]
 */

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Driver\DefaultHttpDriverFactory;
use Amp\Http\Server\Middleware\ForwardedHeaderType;
use Amp\Http\Server\SocketHttpServer;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Madeline\Web\AccountManager;
use Madeline\Web\Api;
use Madeline\Web\MadelineAccount;
use Monolog\Logger as Monolog;
use Psr\Log\LogLevel;

use function Amp\ByteStream\getStdout;
use function Amp\trapSignal;

require dirname(__DIR__) . '/vendor/autoload.php';

$config = require $argv[1] ?? dirname(__DIR__) . '/config.php';

$handler = new StreamHandler(getStdout(), LogLevel::INFO);
$handler->setFormatter(new ConsoleFormatter);
$logger = new Monolog('madeline', [$handler]);

umask(0077);
foreach (['sessions', 'tmp'] as $sub) {
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

$server = SocketHttpServer::createForBehindProxy(
    $logger,
    ForwardedHeaderType::XForwardedFor,
    ['127.0.0.1', '::1'],
    enableCompression: false,
    concurrencyLimit: null,
    // amphp closes a response that stays silent for this long; live-event
    // streams send a keepalive every 10 s (see Api), slow uploads get slack too.
    httpDriverFactory: new DefaultHttpDriverFactory($logger, streamTimeout: 60, connectionTimeout: 60),
);
$server->expose($config['listen']);
$uploadMax = max(1, (int) $config['upload_max_mb']) * 1024 * 1024;
$server->start(new Api($accounts, $config['data_dir'] . '/tmp', $uploadMax, $logger), new DefaultErrorHandler);
$logger->notice("Madeline web client {$config['version']} listening on {$config['listen']}");

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
