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
    private const USER_RE = '/^[a-z0-9_.-]{1,64}$/';
    private const CSRF_HEADER = 'x-madeline';
    private const MAX_TEXT = 4096;
    private const PHOTO_MIMES = ['image/jpeg', 'image/png'];
    private const PHOTO_MAX = 10 * 1024 * 1024;

    public function __construct(
        private readonly AccountManager $accounts,
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

            return match ("$method $path") {
                'GET /status'          => $this->json(['user' => $user] + $this->status($user)),
                'POST /login/phone'    => $this->json($this->account($user, true)->sendCode($this->phone($this->body($request)['phone'] ?? ''))),
                'POST /login/code'     => $this->json($this->loginStep($user, fn (Account $a) => $a->submitCode($this->string($this->body($request), 'code', 16)))),
                'POST /login/password' => $this->json($this->loginStep($user, fn (Account $a) => $a->submitPassword($this->string($this->body($request), 'password', 256)))),
                'POST /logout'         => $this->json($this->logout($user)),
                'GET /dialogs'         => $this->json($this->ready($user)->dialogs($this->cursor($request), 50)),
                'GET /history'         => $this->json($this->ready($user)->history($this->chat($request), $this->intQuery($request, 'before'), 40)),
                'POST /send'           => $this->json($this->send($user, $this->body($request))),
                'POST /read'           => $this->json($this->read($user, $this->body($request))),
                'POST /upload'         => $this->json($this->upload($user, $request)),
                'GET /media'           => $this->ready($user)->mediaResponse($this->chat($request), $this->intQuery($request, 'msg'), $request, $this->query($request, 'download') === '1'),
                'GET /events'          => $this->events($user),
                default                => throw new ApiError(HttpStatus::NOT_FOUND, 'NOT_FOUND'),
            };
        } catch (ApiError $e) {
            return $this->json(['error' => $e->errorCode] + $e->extra, $e->status);
        } catch (Throwable $e) {
            $this->logger->error('Unhandled error: ' . $e);
            return $this->json(['error' => 'INTERNAL'], HttpStatus::INTERNAL_SERVER_ERROR);
        }
    }

    private function status(string $user): array
    {
        $account = $this->accounts->get($user);
        if ($account === null) {
            return ['state' => 'logged_out'];
        }
        $status = $account->status();
        if ($status['state'] === 'logged_out') {
            $this->accounts->drop($user, false);
        }
        return $status;
    }

    private function logout(string $user): array
    {
        if ($this->accounts->get($user) !== null) {
            $this->accounts->drop($user, true);
        }
        $this->accounts->broadcast($user, ['type' => 'logged_out']);
        return ['state' => 'logged_out'];
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
        $this->ready($user)->sendText($this->int($body['chat'] ?? null), $text);
        return ['ok' => true];
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
            $account->sendFile($chat, $tmp, $name, $mime, $caption, $asPhoto);
        } finally {
            if (\is_resource($fh)) {
                fclose($fh);
            }
            Fs::remove($tmp);
        }
        return ['ok' => true];
    }

    private function events(string $user): Response
    {
        $queue = new Queue(16);
        $this->accounts->subscribe($user, $queue);
        $queue->pushAsync("retry: 3000\n: connected\n\n")->ignore();

        $timer = EventLoop::repeat($this->keepalive, function (string $id) use ($user, $queue): void {
            if ($queue->isDisposed() || $queue->isComplete()) {
                EventLoop::cancel($id);
                $this->accounts->unsubscribe($user, $queue);
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
