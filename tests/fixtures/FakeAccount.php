<?php declare(strict_types=1);

namespace Madeline\Web\Test;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Closure;
use Madeline\Web\Account;
use Madeline\Web\ApiError;

/**
 * In-memory stand-in for MadelineAccount: login with code 12345 (and password
 * "secret" for numbers ending in 2), a few canned chats, echoes sent messages
 * back as live events. Every user gets its own instance, like in production.
 */
final class FakeAccount implements Account
{
    private string $state = 'logged_out';
    private string $phone = '';
    private ?Closure $listener = null;
    /** @var array<int, list<array>> */
    private array $messages = [];
    private int $nextId = 100;
    public bool $closed = false;

    public function __construct(private readonly string $user)
    {
        $this->messages[111] = [$this->msg(111, 1, "Hello {$this->user}", false)];
        $this->messages[-1000000000222] = [$this->msg(-1000000000222, 2, 'Channel post', false)];
    }

    private function msg(int $chat, int $id, string $text, bool $out, ?array $media = null): array
    {
        return ['id' => $id, 'chat' => $chat, 'date' => 1700000000 + $id, 'out' => $out, 'from' => $out ? null : $chat,
            'from_name' => $out ? null : 'Alice', 'text' => $text, 'media' => $media, 'service' => null, 'edited' => false, 'reply_to' => null];
    }

    public function status(): array
    {
        $status = ['state' => $this->state];
        if ($this->state === 'ready') {
            $status['me'] = ['id' => 1, 'name' => "Fake {$this->user}", 'username' => $this->user, 'phone' => $this->phone];
        } elseif ($this->state === 'code') {
            $status['code_type'] = 'auth.sentCodeTypeApp';
        } elseif ($this->state === 'password') {
            $status['hint'] = 'hint';
        }
        return $status;
    }

    public function sendCode(string $phone): array
    {
        if ($phone === '+10000000000') {
            throw new ApiError(HttpStatus::BAD_REQUEST, 'PHONE_NUMBER_BANNED');
        }
        $this->phone = $phone;
        $this->state = 'code';
        return $this->status();
    }

    public function submitCode(string $code): array
    {
        if ($this->state !== 'code') {
            throw new ApiError(HttpStatus::CONFLICT, 'LOGIN_NOT_STARTED');
        }
        if ($code !== '12345') {
            throw new ApiError(HttpStatus::BAD_REQUEST, 'PHONE_CODE_INVALID');
        }
        $this->state = str_ends_with($this->phone, '2') ? 'password' : 'ready';
        return $this->status();
    }

    public function submitPassword(string $password): array
    {
        if ($this->state !== 'password') {
            throw new ApiError(HttpStatus::CONFLICT, 'LOGIN_NOT_STARTED');
        }
        if ($password !== 'secret') {
            throw new ApiError(HttpStatus::BAD_REQUEST, 'PASSWORD_HASH_INVALID');
        }
        $this->state = 'ready';
        return $this->status();
    }

    public function dialogs(?array $cursor, int $limit): array
    {
        return ['dialogs' => [
            ['id' => 111, 'title' => 'Alice', 'kind' => 'user', 'unread' => 1, 'pinned' => false, 'last' => end($this->messages[111])],
            ['id' => -1000000000222, 'title' => 'News', 'kind' => 'channel', 'unread' => 0, 'pinned' => true, 'last' => end($this->messages[-1000000000222])],
        ], 'next' => null];
    }

    public function history(int $chat, int $before, int $limit): array
    {
        if (!isset($this->messages[$chat])) {
            throw new ApiError(HttpStatus::BAD_REQUEST, 'PEER_ID_INVALID');
        }
        return ['messages' => $this->messages[$chat]];
    }

    public function sendText(int $chat, string $text): void
    {
        $message = $this->msg($chat, $this->nextId++, $text, true);
        $this->messages[$chat][] = $message;
        $this->emit(['type' => 'message', 'message' => $message, 'chat_title' => 'Alice', 'chat_kind' => 'user']);
    }

    public function sendFile(int $chat, string $path, string $name, string $mime, string $caption, bool $asPhoto): void
    {
        $content = (string) file_get_contents($path);
        $media = $asPhoto ? ['kind' => 'photo'] : ['kind' => 'file', 'name' => $name, 'mime' => $mime, 'size' => \strlen($content)];
        $message = $this->msg($chat, $this->nextId++, $caption . ' sha1:' . sha1($content), true, $media);
        $this->messages[$chat][] = $message;
        $this->emit(['type' => 'message', 'message' => $message, 'chat_title' => 'Alice', 'chat_kind' => 'user']);
    }

    public function markRead(int $chat, int $maxId): void
    {
        $this->emit(['type' => 'read', 'chat' => $chat, 'unread' => 0]);
    }

    public function mediaResponse(int $chat, int $messageId, Request $request, bool $download): Response
    {
        foreach ($this->messages[$chat] ?? [] as $m) {
            if ($m['id'] === $messageId && $m['media'] !== null) {
                $headers = ['content-type' => 'text/plain'];
                if ($download) {
                    $headers['content-disposition'] = 'attachment; filename="file.txt"';
                }
                return new Response(HttpStatus::OK, $headers, "media of {$this->user} #$messageId");
            }
        }
        throw new ApiError(HttpStatus::NOT_FOUND, 'MEDIA_NOT_FOUND');
    }

    public function onEvent(Closure $listener): void
    {
        $this->listener = $listener;
    }

    public function close(bool $logout): void
    {
        $this->closed = true;
    }

    private function emit(array $event): void
    {
        if ($this->listener) {
            ($this->listener)($event);
        }
    }
}
