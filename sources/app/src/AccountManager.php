<?php declare(strict_types=1);

namespace Madeline\Web;

use Amp\DeferredFuture;
use Amp\Future;
use Amp\Pipeline\Queue;
use Closure;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps at most one Account per YunoHost user in memory, opened lazily on the
 * first request that needs it, and fans live events out to that user's open
 * browser tabs (Server-Sent Events queues). Users never share anything.
 */
final class AccountManager
{
    /** @var array<string, Account> */
    private array $accounts = [];
    /** @var array<string, Future<?Account>> */
    private array $opening = [];
    /** @var array<string, array<int, Queue<string>>> */
    private array $subscribers = [];

    /**
     * @param Closure(string $user, bool $create): ?Account $opener
     *        Returns the user's account, or null if it has none and $create is false.
     */
    public function __construct(private readonly Closure $opener, private readonly LoggerInterface $logger)
    {
    }

    public function get(string $user, bool $create = false): ?Account
    {
        if (isset($this->accounts[$user])) {
            return $this->accounts[$user];
        }
        if (isset($this->opening[$user])) {
            $account = $this->opening[$user]->await();
            return $account ?? ($create ? $this->get($user, true) : null);
        }

        $deferred = new DeferredFuture;
        $this->opening[$user] = $deferred->getFuture();
        $this->opening[$user]->ignore();
        try {
            $account = ($this->opener)($user, $create);
            if ($account !== null) {
                $account->onEvent(fn (array $event) => $this->handleEvent($user, $event));
                $this->accounts[$user] = $account;
            }
            $deferred->complete($account);
            return $account;
        } catch (Throwable $e) {
            $deferred->error($e);
            throw $e;
        } finally {
            unset($this->opening[$user]);
        }
    }

    /** Forget the user's account; with $logout the Telegram session is revoked and deleted. */
    public function drop(string $user, bool $logout): void
    {
        $account = $this->accounts[$user] ?? null;
        unset($this->accounts[$user]);
        if ($account !== null) {
            try {
                $account->close($logout);
            } catch (Throwable $e) {
                $this->logger->warning("Closing the session of $user failed: {$e->getMessage()}");
            }
        }
    }

    public function shutdown(): void
    {
        foreach (array_keys($this->accounts) as $user) {
            $this->drop((string) $user, false);
        }
        foreach ($this->subscribers as $queues) {
            foreach ($queues as $queue) {
                if (!$queue->isComplete()) {
                    $queue->complete();
                }
            }
        }
        $this->subscribers = [];
    }

    public function loadedCount(): int
    {
        return \count($this->accounts);
    }

    /** @param Queue<string> $queue */
    public function subscribe(string $user, Queue $queue): void
    {
        $this->subscribers[$user][spl_object_id($queue)] = $queue;
    }

    /** @param Queue<string> $queue */
    public function unsubscribe(string $user, Queue $queue): void
    {
        unset($this->subscribers[$user][spl_object_id($queue)]);
        if (empty($this->subscribers[$user])) {
            unset($this->subscribers[$user]);
        }
    }

    public function broadcast(string $user, array $event): void
    {
        $frame = 'data: ' . json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
        $this->send($user, $frame);
    }

    /** Write a raw SSE frame to every open tab of the user, dropping tabs that went away. */
    public function send(string $user, string $frame): void
    {
        foreach ($this->subscribers[$user] ?? [] as $queue) {
            if ($queue->isDisposed() || $queue->isComplete()) {
                $this->unsubscribe($user, $queue);
                continue;
            }
            try {
                // Don't let one slow tab block the others: never await the push.
                $queue->pushAsync($frame)->ignore();
            } catch (Throwable) {
                $this->unsubscribe($user, $queue);
            }
        }
    }

    private function handleEvent(string $user, array $event): void
    {
        if (($event['type'] ?? '') === 'logged_out') {
            // Revoked from another device, or the account was deactivated
            $this->drop($user, false);
        }
        $this->broadcast($user, $event);
    }
}
