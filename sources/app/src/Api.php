<?php declare(strict_types=1);

namespace Madeline\Web;

use Amp\ByteStream\ReadableIterableStream;
use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Pipeline\Queue;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Throwable;

/**
 * JSON API consumed by www/app.js. nginx strips the app path and the "api/"
 * prefix, and forwards the SSO-authenticated user in Ynh-User
 * (proxy_params_with_auth, which overwrites any client-supplied value).
 */
final class Api implements RequestHandler
{
    public const USER_RE = '/^[a-z0-9_.-]{1,64}$/';
    private const CSRF_HEADER = 'x-madeline';
    private const MAX_TEXT = 4096;
    private const PHOTO_MIMES = ['image/jpeg', 'image/png'];
    private const PHOTO_MAX = 10 * 1024 * 1024;
    private const LOCK_COOKIE = 'madeline_unlock';
    /** Routes that work while the browser is locked; everything else needs it unlocked. */
    private const OPEN_ROUTES = ['GET /status', 'POST /logout', 'POST /lock/unlock'];

    public function __construct(
        private readonly AccountManager $accounts,
        private readonly LockStore $locks,
        private readonly string $tmpDir,
        private readonly int $uploadMax,
        private readonly LoggerInterface $logger,
        private readonly float $keepalive = 10.0,
    ) {
    }

    public function handleRequest(Request $request): Response
    {
        try {
            $user = strtolower((string) $request->getHeader('ynh-user'));
            if (!preg_match(self::USER_RE, $user)) {
                throw new ApiError(HttpStatus::FORBIDDEN, 'NOT_AUTHENTICATED');
            }
            $method = $request->getMethod();
            // Cross-site forms can't set custom headers, so this blocks CSRF.
            if ($method !== 'GET' && $request->getHeader(self::CSRF_HEADER) !== '1') {
                throw new ApiError(HttpStatus::FORBIDDEN, 'CSRF');
            }
            $path = '/' . trim($request->getUri()->getPath(), '/');
            $route = "$method $path";
            $token = $request->getCookie(self::LOCK_COOKIE)?->getValue();
            if (!\in_array($route, self::OPEN_ROUTES, true) && !$this->locks->isUnlocked($user, $token)) {
                throw new ApiError(HttpStatus::LOCKED, 'LOCKED');
            }

            return match ($route) {
                'GET /status'          => $this->json(['user' => $user] + $this->status($user, $token)),
                'POST /lock/unlock'    => $this->unlocked($request, $user, $this->locks->unlock($user, $this->string($this->body($request), 'passcode', 64))),
                'POST /lock/activity'  => $this->json(['locked' => !$this->locks->touch($user, $token)]),
                'POST /lock/lock'      => $this->lockNow($request, $user, $token),
                'POST /lock/settings'  => $this->configureLock($request, $user),
                'POST /lock/disable'   => $this->disableLock($request, $user),
                'POST /login/phone'    => $this->json($this->account($user, true)->sendCode($this->phone($this->body($request)['phone'] ?? ''))),
                'POST /login/code'     => $this->json($this->loginStep($user, fn (Account $a) => $a->submitCode($this->string($this->body($request), 'code', 16)))),
                'POST /login/password' => $this->json($this->loginStep($user, fn (Account $a) => $a->submitPassword($this->string($this->body($request), 'password', 256)))),
                'POST /logout'         => $this->json($this->logout($user)),
                'GET /dialogs'         => $this->json($this->ready($user)->dialogs($this->cursor($request), 50)),
                'GET /history'         => $this->json($this->ready($user)->history($this->chat($request), $this->intQuery($request, 'before'), 40)),
                'POST /send'           => $this->json($this->send($user, $this->body($request))),
                'POST /read'           => $this->json($this->read($user, $this->body($request))),
                'POST /upload'         => $this->json($this->upload($user, $request)),
                'GET /media'           => $this->media($user, $request),
                'GET /events'          => $this->events($user, $token),
                default                => throw new ApiError(HttpStatus::NOT_FOUND, 'NOT_FOUND'),
            };
        } catch (ApiError $e) {
            return $this->json(['error' => $e->errorCode] + $e->extra, $e->status);
        } catch (Throwable $e) {
            $this->logger->error('Unhandled error: ' . $e);
            return $this->json(['error' => 'INTERNAL'], HttpStatus::INTERNAL_SERVER_ERROR);
        }
    }

    private function status(string $user, ?string $token): array
    {
        $account = $this->accounts->get($user);
        $status = $account?->status() ?? ['state' => 'logged_out'];
        if ($status['state'] === 'logged_out') {
            if ($account !== null) {
                $this->accounts->drop($user, false);
            }
            // The passcode belongs to the Telegram session, like in Telegram
            $this->locks->clear($user);
            return $status;
        }
        $lock = $this->locks->settings($user) + ['locked' => !$this->locks->isUnlocked($user, $token)];
        if ($lock['locked']) {
            // Not even the account name while locked
            return ['state' => 'locked', 'lock' => $lock];
        }
        return $status + ['lock' => $lock];
    }

    private function logout(string $user): array
    {
        if ($this->accounts->get($user) !== null) {
            $this->accounts->drop($user, true);
        }
        $this->locks->clear($user);
        $this->accounts->broadcast($user, ['type' => 'logged_out']);
        return ['state' => 'logged_out'];
    }

    /** Response for a browser that just got unlocked: its token goes into an HttpOnly cookie. */
    private function unlocked(Request $request, string $user, string $token): Response
    {
        $response = $this->json(['user' => $user] + $this->status($user, $token));
        $response->setHeader('set-cookie', $this->cookie($request, $token));
        return $response;
    }

    private function lockNow(Request $request, string $user, ?string $token): Response
    {
        $this->locks->revoke($user, $token);
        $this->accounts->closeSubscriptions($user);
        $response = $this->json(['state' => 'locked', 'lock' => $this->locks->settings($user) + ['locked' => true]]);
        $response->setHeader('set-cookie', $this->cookie($request, null));
        return $response;
    }

    private function configureLock(Request $request, string $user): Response
    {
        $this->ready($user);
        $body = $this->body($request);
        $passcode = isset($body['passcode']) ? $this->string($body, 'passcode', 64) : null;
        $current = isset($body['current']) ? $this->string($body, 'current', 64) : null;
        $token = $this->locks->configure($user, $current, $passcode, $this->int($body['timeout'] ?? null));
        // Other browsers lose their tokens: close their live streams now
        $this->accounts->closeSubscriptions($user);
        return $this->unlocked($request, $user, $token);
    }

    private function disableLock(Request $request, string $user): Response
    {
        $this->locks->disable($user, $this->string($this->body($request), 'current', 64));
        $response = $this->json(['user' => $user] + $this->status($user, null));
        $response->setHeader('set-cookie', $this->cookie($request, null));
        return $response;
    }

    private function cookie(Request $request, ?string $token): string
    {
        // nginx tells where the API lives, so the cookie is only sent to it
        $prefix = (string) $request->getHeader('x-forwarded-prefix');
        $path = preg_match('#^/[A-Za-z0-9._~/-]{0,200}$#', $prefix) ? $prefix : '/';
        $attributes = "; Path=$path; HttpOnly; Secure; SameSite=Strict";
        return $token === null
            ? self::LOCK_COOKIE . '=; Max-Age=0' . $attributes
            : self::LOCK_COOKIE . '=' . $token . $attributes;
    }

    private function media(string $user, Request $request): Response
    {
        $response = $this->ready($user)->mediaResponse($this->chat($request), $this->intQuery($request, 'msg'), $request, $this->query($request, 'download') === '1');
        if ($this->locks->isEnabled($user)) {
            // Never let the browser show a cached copy while it is locked
            $response->setHeader('cache-control', 'private, no-cache');
            $response->setHeader('vary', 'Cookie');
        }
        return $response;
    }

    private function loginStep(string $user, \Closure $step): array
    {
        $account = $this->accounts->get($user);
        if ($account === null) {
            throw new ApiError(HttpStatus::CONFLICT, 'LOGIN_NOT_STARTED');
        }
        return $step($account);
    }

    private function send(string $user, array $body): array
    {
        $text = $this->string($body, 'text', self::MAX_TEXT, 'MESSAGE_TOO_LONG');
        if (trim($text) === '') {
            throw new ApiError(HttpStatus::BAD_REQUEST, 'MESSAGE_EMPTY');
        }
        $this->ready($user)->sendText($this->int($body['chat'] ?? null), $text, $this->replyTo($body['reply_to'] ?? null));
        return ['ok' => true];
    }

    private function replyTo(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        $id = $this->int($value);
        if ($id <= 0 || $id > 0x7fffffff) {
            throw new ApiError(HttpStatus::BAD_REQUEST, 'BAD_REQUEST');
        }
        return $id;
    }

    private function read(string $user, array $body): array
    {
        $this->ready($user)->markRead($this->int($body['chat'] ?? null), $this->int($body['max_id'] ?? null));
        return ['ok' => true];
    }

    private function upload(string $user, Request $request): array
    {
        $account = $this->ready($user);
        $chat = $this->chat($request);
        $replyTo = $this->replyTo($this->query($request, 'reply_to'));
        $name = basename(str_replace('\\', '/', $this->query($request, 'name')));
        $name = $name !== '' && $name !== '.' ? mb_substr($name, 0, 200) : 'file';
        $caption = mb_substr($this->query($request, 'caption'), 0, 1024);
        $mime = strtolower(trim(explode(';', (string) $request->getHeader('content-type'))[0]));
        if (!preg_match('~^[a-z0-9.+-]+/[a-z0-9.+-]+$~', $mime)) {
            $mime = 'application/octet-stream';
        }

        $tmp = $this->tmpDir . '/upload-' . bin2hex(random_bytes(8));
        $fh = fopen($tmp, 'xb');
        if ($fh === false) {
            throw new ApiError(HttpStatus::INTERNAL_SERVER_ERROR, 'UPLOAD_FAILED');
        }
        try {
            $size = 0;
            $body = $request->getBody();
            $body->increaseSizeLimit($this->uploadMax + 1);
            while (null !== $chunk = $body->read()) {
                $size += \strlen($chunk);
                if ($size > $this->uploadMax) {
                    throw new ApiError(HttpStatus::PAYLOAD_TOO_LARGE, 'FILE_TOO_LARGE', ['max' => $this->uploadMax]);
                }
                fwrite($fh, $chunk);
            }
            fclose($fh);
            $fh = null;
            if ($size === 0) {
                throw new ApiError(HttpStatus::BAD_REQUEST, 'FILE_EMPTY');
            }
            $asPhoto = \in_array($mime, self::PHOTO_MIMES, true) && $size <= self::PHOTO_MAX && $this->query($request, 'as') !== 'file';
            $account->sendFile($chat, $tmp, $name, $mime, $caption, $asPhoto, $replyTo);
        } finally {
            if (\is_resource($fh)) {
                fclose($fh);
            }
            Fs::remove($tmp);
        }
        return ['ok' => true];
    }

    private function events(string $user, ?string $token): Response
    {
        $queue = new Queue(16);
        // Checked before every event: a browser that got locked stops receiving
        $gate = fn (): bool => $this->locks->isUnlocked($user, $token);
        $this->accounts->subscribe($user, $queue, $gate);
        $queue->pushAsync("retry: 3000\n: connected\n\n")->ignore();

        $timer = EventLoop::repeat($this->keepalive, function (string $id) use ($user, $queue, $gate): void {
            if ($queue->isDisposed() || $queue->isComplete()) {
                EventLoop::cancel($id);
                $this->accounts->unsubscribe($user, $queue);
                return;
            }
            if (!$gate()) {
                EventLoop::cancel($id);
                $this->accounts->closeSubscription($user, $queue);
                return;
            }
            $queue->pushAsync(": ping\n\n")->ignore();
        });
        EventLoop::unreference($timer);

        return new Response(HttpStatus::OK, [
            'content-type'      => 'text/event-stream; charset=utf-8',
            'cache-control'     => 'no-store',
            'x-accel-buffering' => 'no',
        ], new ReadableIterableStream($queue->pipe()));
    }

    private function account(string $user, bool $create = false): Account
    {
        $account = $this->accounts->get($user, $create);
        if ($account === null) {
            throw new ApiError(HttpStatus::UNAUTHORIZED, 'LOGGED_OUT');
        }
        return $account;
    }

    /** The user's account, only if it has completed the Telegram login. */
    private function ready(string $user): Account
    {
        $account = $this->account($user);
        if ($account->status()['state'] !== 'ready') {
            throw new ApiError(HttpStatus::UNAUTHORIZED, 'LOGGED_OUT');
        }
        return $account;
    }

    private function body(Request $request): array
    {
        $raw = $request->getBody()->buffer(limit: 64 * 1024);
        $data = json_decode($raw, true);
        if (!\is_array($data)) {
            throw new ApiError(HttpStatus::BAD_REQUEST, 'BAD_REQUEST');
        }
        return $data;
    }

    private function phone(mixed $phone): string
    {
        $digits = preg_replace('/[\s()-]/', '', \is_string($phone) ? $phone : '');
        if (!preg_match('/^\+?[0-9]{5,16}$/', $digits)) {
            throw new ApiError(HttpStatus::BAD_REQUEST, 'PHONE_NUMBER_INVALID');
        }
        return '+' . ltrim($digits, '+');
    }

    private function string(array $body, string $key, int $max, string $tooLong = 'BAD_REQUEST'): string
    {
        $value = $body[$key] ?? null;
        if (!\is_string($value) || $value === '') {
            throw new ApiError(HttpStatus::BAD_REQUEST, 'BAD_REQUEST');
        }
        if (mb_strlen($value) > $max) {
            throw new ApiError(HttpStatus::BAD_REQUEST, $tooLong);
        }
        return $value;
    }

    private function int(mixed $value): int
    {
        if (\is_int($value) || (\is_string($value) && preg_match('/^-?[0-9]{1,19}$/', $value))) {
            return (int) $value;
        }
        throw new ApiError(HttpStatus::BAD_REQUEST, 'BAD_REQUEST');
    }

    private function query(Request $request, string $key): string
    {
        $value = $request->getQueryParameter($key);
        return \is_string($value) ? $value : '';
    }

    private function intQuery(Request $request, string $key): int
    {
        $value = $this->query($request, $key);
        return $value === '' ? 0 : $this->int($value);
    }

    private function chat(Request $request): int
    {
        $chat = $this->intQuery($request, 'chat');
        if ($chat === 0) {
            throw new ApiError(HttpStatus::BAD_REQUEST, 'BAD_REQUEST');
        }
        return $chat;
    }

    private function cursor(Request $request): ?array
    {
        if ($this->query($request, 'offset_id') === '') {
            return null;
        }
        return [
            'date' => $this->intQuery($request, 'offset_date'),
            'id'   => $this->intQuery($request, 'offset_id'),
            'peer' => $this->intQuery($request, 'offset_peer'),
        ];
    }

    private function json(array $data, int $status = HttpStatus::OK): Response
    {
        return new Response($status, [
            'content-type'  => 'application/json; charset=utf-8',
            'cache-control' => 'no-store',
        ], json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
