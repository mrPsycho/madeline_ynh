<?php declare(strict_types=1);

namespace Madeline\Web;

use Amp\Http\HttpStatus;

/**
 * Per-user passcode lock with per-browser unlock tokens.
 *
 * Settings (bcrypt hash of the passcode, inactivity timeout, brute-force
 * counters) are stored in $dir/<user>.json, owner-only. Unlock tokens only
 * live in memory: every browser starts locked after a daemon restart.
 * A browser stays unlocked while it reports activity (see Api: /lock/activity)
 * at least once per timeout; nothing else extends it.
 */
final class LockStore
{
    public const MIN_TIMEOUT = 1;
    public const MAX_TIMEOUT = 1440;
    private const MAX_TOKENS = 20;
    private const FREE_ATTEMPTS = 5;
    private const MAX_DELAY = 3600;

    /** @var array<string, array<string, float>> user => sha256(token) => last activity */
    private array $tokens = [];

    /** @param \Closure(): float $clock */
    public function __construct(private readonly string $dir, private readonly ?\Closure $clock = null)
    {
    }

    /** @return array{enabled: bool, timeout: int} */
    public function settings(string $user): array
    {
        $data = $this->load($user);
        return ['enabled' => $data !== null, 'timeout' => (int) ($data['timeout'] ?? 15)];
    }

    public function isEnabled(string $user): bool
    {
        return $this->load($user) !== null;
    }

    /** True when the user has no lock, or this browser holds a token that is still within the timeout. */
    public function isUnlocked(string $user, ?string $token): bool
    {
        $data = $this->load($user);
        if ($data === null) {
            return true;
        }
        if ($token === null || $token === '') {
            return false;
        }
        $key = hash('sha256', $token);
        $last = $this->tokens[$user][$key] ?? null;
        if ($last === null) {
            return false;
        }
        if ($this->now() - $last >= $data['timeout'] * 60) {
            unset($this->tokens[$user][$key]);
            return false;
        }
        return true;
    }

    /** Record activity of an unlocked browser. */
    public function touch(string $user, ?string $token): bool
    {
        if (!$this->isEnabled($user)) {
            return true;
        }
        if (!$this->isUnlocked($user, $token)) {
            return false;
        }
        $this->tokens[$user][hash('sha256', (string) $token)] = $this->now();
        return true;
    }

    /**
     * Check the passcode (throttled) and return a new unlock token for this browser.
     */
    public function unlock(string $user, string $passcode): string
    {
        $data = $this->load($user) ?? throw new ApiError(HttpStatus::CONFLICT, 'LOCK_DISABLED');
        $this->verify($user, $data, $passcode);
        return $this->issue($user);
    }

    /**
     * Enable the lock, or change passcode/timeout. When the lock is already
     * enabled, $current must be the current passcode. Every other browser is
     * locked; the returned token keeps this one unlocked.
     */
    public function configure(string $user, ?string $current, ?string $passcode, int $timeout): string
    {
        self::checkTimeout($timeout);
        $data = $this->load($user);
        if ($data !== null) {
            $this->verify($user, $data, (string) $current);
        } elseif ($passcode === null) {
            throw new ApiError(HttpStatus::BAD_REQUEST, 'PASSCODE_REQUIRED');
        }
        if ($passcode !== null) {
            self::checkPasscode($passcode);
            $data = ['hash' => password_hash($passcode, PASSWORD_BCRYPT)];
        }
        $data['timeout'] = $timeout;
        $data['failures'] = 0;
        $data['blocked_until'] = 0;
        $this->save($user, $data);
        $this->tokens[$user] = [];
        return $this->issue($user);
    }

    /** Disable the lock (needs the current passcode). */
    public function disable(string $user, string $current): void
    {
        $data = $this->load($user) ?? throw new ApiError(HttpStatus::CONFLICT, 'LOCK_DISABLED');
        $this->verify($user, $data, $current);
        $this->clear($user);
    }

    /** Lock this browser now. */
    public function revoke(string $user, ?string $token): void
    {
        if ($token !== null) {
            unset($this->tokens[$user][hash('sha256', $token)]);
        }
    }

    /** Forget everything about the user's lock (Telegram logout). */
    public function clear(string $user): void
    {
        unset($this->tokens[$user]);
        Fs::remove($this->path($user));
    }

    public static function checkTimeout(int $timeout): void
    {
        if ($timeout < self::MIN_TIMEOUT || $timeout > self::MAX_TIMEOUT) {
            throw new ApiError(HttpStatus::BAD_REQUEST, 'LOCK_TIMEOUT_INVALID');
        }
    }

    private static function checkPasscode(string $passcode): void
    {
        $length = mb_strlen($passcode);
        if ($length < 4 || $length > 64) {
            throw new ApiError(HttpStatus::BAD_REQUEST, 'PASSCODE_INVALID');
        }
    }

    private function verify(string $user, array $data, string $passcode): void
    {
        $now = $this->now();
        $wait = (int) ceil(($data['blocked_until'] ?? 0) - $now);
        if ($wait > 0) {
            throw new ApiError(HttpStatus::TOO_MANY_REQUESTS, 'PASSCODE_FLOOD', ['seconds' => $wait]);
        }
        if (password_verify($passcode, (string) $data['hash'])) {
            if (($data['failures'] ?? 0) !== 0) {
                $data['failures'] = 0;
                $data['blocked_until'] = 0;
                $this->save($user, $data);
            }
            return;
        }
        $data['failures'] = ($data['failures'] ?? 0) + 1;
        $over = $data['failures'] - self::FREE_ATTEMPTS;
        $data['blocked_until'] = $over >= 0 ? $now + min(self::MAX_DELAY, 30 * 2 ** min($over, 10)) : 0;
        $this->save($user, $data);
        throw new ApiError(HttpStatus::FORBIDDEN, 'PASSCODE_WRONG');
    }

    private function issue(string $user): string
    {
        $token = bin2hex(random_bytes(32));
        $this->tokens[$user] ??= [];
        if (\count($this->tokens[$user]) >= self::MAX_TOKENS) {
            asort($this->tokens[$user]);
            array_shift($this->tokens[$user]);
        }
        $this->tokens[$user][hash('sha256', $token)] = $this->now();
        return $token;
    }

    private function load(string $user): ?array
    {
        $path = $this->path($user);
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        return \is_array($data) && isset($data['hash'], $data['timeout']) ? $data : null;
    }

    private function save(string $user, array $data): void
    {
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0700, true);
        }
        $tmp = $this->path($user) . '.tmp';
        file_put_contents($tmp, json_encode($data), LOCK_EX);
        chmod($tmp, 0600);
        rename($tmp, $this->path($user));
    }

    private function path(string $user): string
    {
        return $this->dir . '/' . $user . '.json';
    }

    private function now(): float
    {
        return $this->clock !== null ? ($this->clock)() : microtime(true);
    }
}
