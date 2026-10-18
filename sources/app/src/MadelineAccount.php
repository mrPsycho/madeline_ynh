<?php declare(strict_types=1);

namespace Madeline\Web;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Closure;
use danog\MadelineProto\API;
use danog\MadelineProto\LocalFile;
use danog\MadelineProto\Logger;
use danog\MadelineProto\Magic;
use danog\MadelineProto\RPCError\RateLimitError;
use danog\MadelineProto\RPCErrorException;
use danog\MadelineProto\Settings;
use Psr\Log\LoggerInterface;
use Throwable;

use function Amp\async;
use function Amp\delay;

/**
 * A Telegram account backed by a MadelineProto session that runs inside the
 * daemon process (IPC "slow" mode), so N users cost one PHP process, not N.
 *
 * Layout of $dataDir/sessions/<user>/:
 *   current           name of the active session directory
 *   <random>.madeline the MadelineProto session itself
 * Every login gets a fresh directory, so a session that is still shutting down
 * after a logout can never hold the lock of the next one.
 */
final class MadelineAccount implements Account
{
    private const STATES = [
        API::NOT_LOGGED_IN    => 'logged_out',
        API::LOGGED_OUT       => 'logged_out',
        API::WAITING_CODE     => 'code',
        API::WAITING_PASSWORD => 'password',
        API::WAITING_SIGNUP   => 'signup',
        API::LOGGED_IN        => 'ready',
    ];

    private ?API $api;
    private ?Closure $listener = null;
    private bool $pumping = false;
    private bool $closed = false;
    private string $hint = '';
    private string $codeType = '';
    /** @var array<int, array{title: string, kind: string}> */
    private array $peerCache = [];

    private function __construct(API $api, private readonly string $pointer, private readonly LoggerInterface $logger)
    {
        $this->api = $api;
        if ($this->state() === API::LOGGED_IN) {
            $this->startPump();
        }
    }

    /**
     * @param array{data_dir: string, log_dir: string, api_id: int, api_hash: string, version: string} $config
     */
    public static function open(array $config, string $user, bool $create, LoggerInterface $logger): ?self
    {
        $dir = $config['data_dir'] . '/sessions/' . $user;
        $pointer = $dir . '/current';
        $current = is_file($pointer) ? trim((string) file_get_contents($pointer)) : '';
        if ($current === '' || !preg_match('/^[0-9a-f]{16}\.madeline$/', $current) || !file_exists("$dir/$current")) {
            if (!$create) {
                return null;
            }
            if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new ApiError(HttpStatus::INTERNAL_SERVER_ERROR, 'STORAGE');
            }
            $current = bin2hex(random_bytes(8)) . '.madeline';
            file_put_contents($pointer, $current, LOCK_EX);
        }

        return new self(new API("$dir/$current", self::settings($config)), $pointer, $logger);
    }

    /** Remove session directories left behind by earlier logouts. Only safe while no session is open. */
    public static function cleanup(string $dataDir): void
    {
        foreach (glob("$dataDir/sessions/*", GLOB_ONLYDIR) ?: [] as $dir) {
            $current = is_file("$dir/current") ? trim((string) file_get_contents("$dir/current")) : '';
            foreach (glob("$dir/*.madeline") ?: [] as $session) {
                if (basename($session) !== $current) {
                    Fs::remove($session);
                }
            }
        }
    }

    private static function settings(array $config): Settings
    {
        // MadelineProto only keeps a session inside the current process (instead
        // of forking one IPC worker process per session) when it runs as its own
        // worker. Settings\Ipc::setSlow() used to do this, but is gone in v8.7.
        if (!\defined('MADELINE_WORKER_TYPE')) {
            \define('MADELINE_WORKER_TYPE', 'madeline-ipc');
        }
        Magic::start(light: true);
        Magic::$isIpcWorker = true;

        $settings = new Settings;
        $settings->getAppInfo()
            ->setApiId((int) $config['api_id'])
            ->setApiHash((string) $config['api_hash'])
            ->setShowPrompt(false)
            ->setDeviceModel('YunoHost web')
            ->setSystemVersion(PHP_OS_FAMILY)
            ->setAppVersion((string) $config['version']);
        $settings->getLogger()
            ->setType(Logger::FILE_LOGGER)
            ->setExtra($config['log_dir'] . '/madelineproto.log')
            ->setLevel(Logger::LEVEL_WARNING)
            ->setMaxSize(10 * 1024 * 1024);
        // Telegram's test data centers, for tests/test_session_restart.sh only
        if (!empty($config['test_mode'])) {
            $settings->getConnection()->setTestMode(true);
        }
        return $settings;
    }

    public function status(): array
    {
        $state = self::STATES[$this->state()] ?? 'logged_out';
        $status = ['state' => $state];
        if ($state === 'code') {
            $status['code_type'] = $this->codeType;
        } elseif ($state === 'password') {
            $status['hint'] = $this->hint;
        } elseif ($state === 'ready') {
            $self = $this->call(fn (API $api) => $api->getSelf());
            if (\is_array($self)) {
                $status['me'] = Normalizer::me($self);
            }
        }
        return $status;
    }

    public function sendCode(string $phone): array
    {
        if ($this->state() === API::LOGGED_IN) {
            return $this->status();
        }
        $sent = $this->call(fn (API $api) => $api->phoneLogin($phone));
        $this->codeType = (string) ($sent['type']['_'] ?? '');
        return $this->status();
    }

    public function submitCode(string $code): array
    {
        if ($this->state() !== API::WAITING_CODE) {
            throw new ApiError(HttpStatus::CONFLICT, 'LOGIN_NOT_STARTED');
        }
        try {
            $result = $this->call(fn (API $api) => $api->completePhoneLogin(preg_replace('/\D/', '', $code)));
        } catch (ApiError $e) {
            // completePhoneLogin() leaves the session logged out on any error,
            // although the code request is still valid: let the user retry a typo.
            if (\in_array($e->errorCode, ['PHONE_CODE_INVALID', 'PHONE_CODE_EMPTY'], true) && $this->state() === API::NOT_LOGGED_IN) {
                $this->mtproto()?->setLoginState(API::WAITING_CODE);
            }
            throw $e;
        }
        if (($result['_'] ?? '') === 'account.password') {
            $this->hint = (string) ($result['hint'] ?? '');
        } elseif (($result['_'] ?? '') === 'account.needSignup') {
            throw new ApiError(HttpStatus::CONFLICT, 'SIGNUP_REQUIRED');
        }
        return $this->afterLogin();
    }

    public function submitPassword(string $password): array
    {
        if ($this->state() !== API::WAITING_PASSWORD) {
            throw new ApiError(HttpStatus::CONFLICT, 'LOGIN_NOT_STARTED');
        }
        $this->call(fn (API $api) => $api->complete2faLogin($password));
        return $this->afterLogin();
    }

    private function afterLogin(): array
    {
        if ($this->state() === API::LOGGED_IN) {
            $this->startPump();
        }
        return $this->status();
    }

    public function dialogs(?array $cursor, int $limit): array
    {
        $result = $this->call(fn (API $api) => $api->messages->getDialogs(
            offset_date: $cursor['date'] ?? 0,
            offset_id: $cursor['id'] ?? 0,
            offset_peer: $cursor !== null && $cursor['peer'] !== 0 ? $cursor['peer'] : ['_' => 'inputPeerEmpty'],
            limit: $limit,
            hash: 0,
        ));
        $this->rememberPeers($result);
        return Normalizer::dialogs($result, $limit);
    }

    public function history(int $chat, int $before, int $limit): array
    {
        $result = $this->call(fn (API $api) => $api->messages->getHistory(
            peer: $chat,
            offset_id: $before,
            offset_date: 0,
            add_offset: 0,
            limit: $limit,
            max_id: 0,
            min_id: 0,
            hash: 0,
        ));
        $this->rememberPeers($result);
        return ['messages' => $this->resolveReplies($chat, Normalizer::history($result))];
    }

    public function sendText(int $chat, string $text, int $replyTo = 0): void
    {
        $args = ['peer' => $chat, 'message' => $text];
        if ($replyTo > 0) {
            $args['reply_to'] = ['_' => 'inputReplyToMessage', 'reply_to_msg_id' => $replyTo];
        }
        $this->call(fn (API $api) => $api->messages->sendMessage(...$args));
    }

    public function sendFile(int $chat, string $path, string $name, string $mime, string $caption, bool $asPhoto, int $replyTo = 0): void
    {
        $file = new LocalFile($path);
        $reply = $replyTo > 0 ? $replyTo : null;
        $this->call(fn (API $api) => $asPhoto
            ? $api->sendPhoto(peer: $chat, file: $file, caption: $caption, fileName: $name, replyToMsgId: $reply)
            : $api->sendDocument(peer: $chat, file: $file, caption: $caption, fileName: $name, mimeType: $mime, replyToMsgId: $reply));
    }

    public function markRead(int $chat, int $maxId): void
    {
        $this->call(fn (API $api) => Normalizer::isChannelId($chat)
            ? $api->channels->readHistory(channel: $chat, max_id: $maxId)
            : $api->messages->readHistory(peer: $chat, max_id: $maxId));
    }

    public function mediaResponse(int $chat, int $messageId, Request $request, bool $download): Response
    {
        $result = $this->fetchMessages($chat, [$messageId]);

        foreach ($result['messages'] ?? [] as $m) {
            if (($m['id'] ?? 0) !== $messageId || !isset($m['peer_id'], $m['media'])
                || Normalizer::peerId($m['peer_id']) !== $chat) {
                continue;
            }
            $media = Normalizer::media($m['media']);
            if ($media === null || !\in_array($media['kind'], ['photo', 'file', 'video', 'round', 'gif', 'audio', 'voice', 'sticker'], true)) {
                break;
            }
            $response = $this->call(fn (API $api) => $api->downloadToResponse($m['media'], $request));
            $response->setHeader('cache-control', 'private, max-age=86400');
            $response->setHeader('x-content-type-options', 'nosniff');
            // Never let a downloaded file run as a page of this origin
            $response->setHeader('content-security-policy', "default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; sandbox");
            if ($download || !\in_array($media['kind'], ['photo', 'video', 'round', 'gif', 'audio', 'voice', 'sticker'], true)) {
                $name = $media['name'] ?? ('telegram-' . $messageId);
                $ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
                $response->setHeader('content-disposition', "attachment; filename=\"$ascii\"; filename*=UTF-8''" . rawurlencode($name));
            }
            return $response;
        }
        throw new ApiError(HttpStatus::NOT_FOUND, 'MEDIA_NOT_FOUND');
    }

    /** @param list<int> $ids */
    private function fetchMessages(int $chat, array $ids): array
    {
        return $this->call(fn (API $api) => Normalizer::isChannelId($chat)
            ? $api->channels->getMessages(channel: $chat, id: $ids)
            : $api->messages->getMessages(id: $ids));
    }

    /**
     * Fill in the replied-to messages that are not part of $messages. Best
     * effort: on failure the replies only show what their header tells.
     *
     * @param list<array> $messages normalized messages of $chat
     * @return list<array>
     */
    private function resolveReplies(int $chat, array $messages): array
    {
        $missing = Normalizer::missingReplies($messages, $chat);
        if ($missing === []) {
            return $messages;
        }
        try {
            $result = $this->fetchMessages($chat, $missing);
        } catch (Throwable $e) {
            $this->logger->warning('Could not fetch replied messages: ' . $e->getMessage());
            return $messages;
        }
        $this->rememberPeers($result);
        // Asked for but not returned: gone as well
        $known = Normalizer::messagesById($result, $chat) + array_fill_keys($missing, null);
        return Normalizer::withReplies($messages, $known);
    }

    public function onEvent(Closure $listener): void
    {
        $this->listener = $listener;
    }

    public function close(bool $logout): void
    {
        $this->closed = true;
        $api = $this->api;
        if ($api === null) {
            return;
        }
        $state = $api->getAuthorization();
        // A session that never got past the phone step holds nothing worth keeping
        if ($logout || $state === API::NOT_LOGGED_IN) {
            try {
                $api->logout();
            } catch (Throwable $e) {
                $this->logger->warning('Telegram logout failed: ' . $e->getMessage());
            }
        }
        if ($logout || \in_array($state, [API::LOGGED_OUT, API::NOT_LOGGED_IN], true)) {
            // The session files are deleted by MadelineProto itself on its final
            // serialization, once the instance is released below.
            Fs::remove($this->pointer);
        }
        $this->api = null;
        unset($api);
        // The API object and its namespace helpers reference each other
        gc_collect_cycles();
    }

    private function state(): int
    {
        return $this->api?->getAuthorization() ?? API::LOGGED_OUT;
    }

    /** The in-process MTProto instance behind the API facade (for internals the facade doesn't expose). */
    private function mtproto(): ?\danog\MadelineProto\MTProto
    {
        $api = $this->api;
        if ($api === null) {
            return null;
        }
        $instance = (fn () => $this->wrapper->getAPI())->call($api);
        return $instance instanceof \danog\MadelineProto\MTProto ? $instance : null;
    }

    /**
     * Run a MadelineProto call, turning Telegram errors into ApiErrors and
     * noticing when the session got revoked from another device.
     *
     * @template T
     * @param Closure(API): T $fn
     * @return T
     */
    private function call(Closure $fn): mixed
    {
        $api = $this->api ?? throw new ApiError(HttpStatus::UNAUTHORIZED, 'LOGGED_OUT');
        try {
            return $fn($api);
        } catch (RateLimitError $e) {
            throw new ApiError(HttpStatus::TOO_MANY_REQUESTS, 'FLOOD_WAIT', ['seconds' => $e->getWaitTime()]);
        } catch (RPCErrorException $e) {
            $this->checkLoggedOut();
            throw new ApiError(HttpStatus::BAD_REQUEST, $e->rpc);
        } catch (ApiError $e) {
            throw $e;
        } catch (Throwable $e) {
            if ($this->checkLoggedOut()) {
                throw new ApiError(HttpStatus::UNAUTHORIZED, 'LOGGED_OUT');
            }
            throw $e;
        }
    }

    private function checkLoggedOut(): bool
    {
        if (!$this->closed && $this->api !== null && $this->state() === API::LOGGED_OUT) {
            $this->emit(['type' => 'logged_out']);
            return true;
        }
        return false;
    }

    private function emit(array $event): void
    {
        if ($this->listener !== null) {
            ($this->listener)($event);
        }
    }

    private function rememberPeers(array $result): void
    {
        if (\count($this->peerCache) > 5000) {
            $this->peerCache = [];
        }
        $this->peerCache = Normalizer::peers($result) + $this->peerCache;
    }

    /** @return array{title: string, kind: string} */
    private function peer(int $id): array
    {
        if (!isset($this->peerCache[$id])) {
            try {
                $info = $this->api?->getInfo($id, API::INFO_TYPE_CONSTRUCTOR);
                $this->peerCache[$id] = \is_array($info)
                    ? ['title' => Normalizer::title($info), 'kind' => Normalizer::kind($info)]
                    : ['title' => '', 'kind' => 'user'];
            } catch (Throwable) {
                return ['title' => '', 'kind' => 'user'];
            }
        }
        return $this->peerCache[$id];
    }

    /** Forward live updates to the browser for as long as the session is open. */
    private function startPump(): void
    {
        if ($this->pumping) {
            return;
        }
        $this->pumping = true;
        async(function (): void {
            $offset = 0;
            try {
                while (!$this->closed && ($api = $this->api) !== null) {
                    try {
                        $updates = $api->getUpdates(['offset' => $offset, 'timeout' => 5.0]);
                    } catch (Throwable $e) {
                        if ($this->closed || $this->checkLoggedOut()) {
                            break;
                        }
                        $this->logger->warning('getUpdates failed: ' . $e->getMessage());
                        delay(5);
                        continue;
                    } finally {
                        unset($api);
                    }
                    foreach ($updates as ['update_id' => $id, 'update' => $update]) {
                        $offset = $id + 1;
                        try {
                            $event = $this->event($update);
                        } catch (Throwable $e) {
                            $this->logger->warning('Could not convert update: ' . $e->getMessage());
                            continue;
                        }
                        if ($event !== null) {
                            $this->emit($event);
                        }
                    }
                }
            } finally {
                $this->pumping = false;
                if ($this->closed) {
                    // Let the released API instance be destructed (and its session saved or deleted)
                    gc_collect_cycles();
                }
            }
        })->ignore();
    }

    private function event(array $update): ?array
    {
        switch ($update['_'] ?? '') {
            case 'updateNewMessage':
            case 'updateNewChannelMessage':
            case 'updateEditMessage':
            case 'updateEditChannelMessage':
                $m = $update['message'] ?? [];
                if (!isset($m['peer_id'])) {
                    return null;
                }
                $chat = Normalizer::peerId($m['peer_id']);
                $peers = [$chat => $this->peer($chat)];
                if (isset($m['from_id'])) {
                    $from = Normalizer::peerId($m['from_id']);
                    $peers[$from] = $this->peer($from);
                }
                $message = Normalizer::message($m, $peers);
                if ($message === null) {
                    return null;
                }
                $message = $this->resolveReplies($chat, [$message])[0];
                return [
                    'type'       => str_contains($update['_'], 'Edit') ? 'edit' : 'message',
                    'message'    => $message,
                    'chat_title' => $peers[$chat]['title'],
                    'chat_kind'  => $peers[$chat]['kind'],
                ];
            case 'updateDeleteMessages':
                return ['type' => 'delete', 'chat' => null, 'ids' => array_map('intval', $update['messages'] ?? [])];
            case 'updateDeleteChannelMessages':
                return ['type' => 'delete', 'chat' => Normalizer::peerId((int) $update['channel_id']), 'ids' => array_map('intval', $update['messages'] ?? [])];
            case 'updateReadHistoryInbox':
                return ['type' => 'read', 'chat' => Normalizer::peerId($update['peer']), 'unread' => (int) ($update['still_unread_count'] ?? 0)];
            case 'updateReadChannelInbox':
                return ['type' => 'read', 'chat' => Normalizer::peerId((int) $update['channel_id']), 'unread' => (int) ($update['still_unread_count'] ?? 0)];
            default:
                return null;
        }
    }
}
