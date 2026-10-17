<?php declare(strict_types=1);

namespace Madeline\Web;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Closure;

/**
 * One YunoHost user's Telegram account. The HTTP layer only talks to this
 * interface, so it can be exercised in tests without reaching Telegram.
 *
 * Chats are identified by bot-API style ids (users > 0, basic groups < 0,
 * channels/supergroups <= -1000000000000).
 */
interface Account
{
    /** @return array{state: string, me?: array, hint?: string} */
    public function status(): array;

    public function sendCode(string $phone): array;

    public function submitCode(string $code): array;

    public function submitPassword(string $password): array;

    /** @return array{dialogs: list<array>, next: ?array} */
    public function dialogs(?array $cursor, int $limit): array;

    /** @return array{messages: list<array>} */
    public function history(int $chat, int $before, int $limit): array;

    public function sendText(int $chat, string $text): void;

    public function sendFile(int $chat, string $path, string $name, string $mime, string $caption, bool $asPhoto): void;

    public function markRead(int $chat, int $maxId): void;

    public function mediaResponse(int $chat, int $messageId, Request $request, bool $download): Response;

    /** Register the single listener that receives live events (see AccountManager). */
    public function onEvent(Closure $listener): void;

    /** Release the session; with $logout the Telegram authorization is revoked and the session deleted. */
    public function close(bool $logout): void;
}
