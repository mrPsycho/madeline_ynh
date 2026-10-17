<?php declare(strict_types=1);

namespace Madeline\Web;

/**
 * Turns raw MTProto (TL) arrays into the small JSON shapes the browser uses.
 * Pure functions only, so they can be tested without Telegram.
 */
final class Normalizer
{
    private const CHANNEL_OFFSET = 1000000000000;

    private const SERVICE_ACTIONS = [
        'messageActionChatCreate'       => 'created',
        'messageActionChannelCreate'    => 'created',
        'messageActionChatEditTitle'    => 'title',
        'messageActionChatEditPhoto'    => 'photo',
        'messageActionChatDeletePhoto'  => 'photo',
        'messageActionChatAddUser'      => 'joined',
        'messageActionChatJoinedByLink' => 'joined',
        'messageActionChatJoinedByRequest' => 'joined',
        'messageActionChatDeleteUser'   => 'left',
        'messageActionPinMessage'       => 'pinned',
        'messageActionPhoneCall'        => 'call',
        'messageActionHistoryClear'     => 'cleared',
        'messageActionContactSignUp'    => 'signed_up',
        'messageActionChatMigrateTo'    => 'migrated',
        'messageActionChannelMigrateFrom' => 'migrated',
    ];

    /**
     * Bot-API style id of a peer, as MadelineProto (>= 8) returns them:
     * Peer objects are already deserialized to plain ints, and the "id",
     * "chat_id" and "channel_id" fields are already signed/offset (see
     * danog\MadelineProto\TL\TL::deserialize). Nothing must be converted again.
     */
    public static function peerId(int|array $peer): int
    {
        if (\is_int($peer)) {
            return $peer;
        }
        $id = match ($peer['_'] ?? '') {
            'user', 'userEmpty', 'chat', 'chatEmpty', 'chatForbidden', 'channel', 'channelForbidden' => $peer['id'] ?? null,
            'peerUser', 'inputPeerUser' => $peer['user_id'] ?? null,
            'peerChat', 'inputPeerChat' => $peer['chat_id'] ?? null,
            'peerChannel', 'inputPeerChannel' => $peer['channel_id'] ?? null,
            default => null,
        };
        if (!\is_int($id) || $id === 0) {
            throw new ApiError(500, 'UNKNOWN_PEER');
        }
        return $id;
    }

    public static function isChannelId(int $id): bool
    {
        return $id <= -self::CHANNEL_OFFSET;
    }

    /** Display name of a user/chat array; '' for deleted accounts (the UI translates that). */
    public static function title(array $peer): string
    {
        if (\in_array($peer['_'] ?? '', ['user', 'userEmpty'], true)) {
            if (!empty($peer['deleted'])) {
                return '';
            }
            $name = trim(($peer['first_name'] ?? '') . ' ' . ($peer['last_name'] ?? ''));
            return $name !== '' ? $name : (string) ($peer['username'] ?? '');
        }
        return (string) ($peer['title'] ?? '');
    }

    public static function kind(array $peer): string
    {
        return match ($peer['_'] ?? '') {
            'user', 'userEmpty' => !empty($peer['self']) ? 'self' : (!empty($peer['bot']) ? 'bot' : 'user'),
            'channel', 'channelForbidden' => !empty($peer['megagroup']) ? 'group' : 'channel',
            default => 'group',
        };
    }

    /**
     * Index the users/chats arrays that accompany most MTProto results.
     *
     * @return array<int, array{title: string, kind: string}>
     */
    public static function peers(array $result): array
    {
        $peers = [];
        foreach ([...($result['users'] ?? []), ...($result['chats'] ?? [])] as $peer) {
            try {
                $peers[self::peerId($peer)] = ['title' => self::title($peer), 'kind' => self::kind($peer)];
            } catch (ApiError) {
            }
        }
        return $peers;
    }

    /**
     * @param array<int, array{title: string, kind: string}> $peers
     */
    public static function message(array $m, array $peers): ?array
    {
        if (!\in_array($m['_'] ?? '', ['message', 'messageService'], true) || !isset($m['peer_id'])) {
            return null;
        }
        $chat = self::peerId($m['peer_id']);
        $out = !empty($m['out']);
        $from = isset($m['from_id']) ? self::peerId($m['from_id']) : ($out ? null : $chat);

        return [
            'id'        => (int) $m['id'],
            'chat'      => $chat,
            'date'      => (int) ($m['date'] ?? 0),
            'out'       => $out,
            'from'      => $from,
            'from_name' => $from !== null ? ($peers[$from]['title'] ?? null) : null,
            'text'      => (string) ($m['message'] ?? ''),
            'media'     => self::media($m['media'] ?? null),
            'service'   => $m['_'] === 'messageService' ? (self::SERVICE_ACTIONS[$m['action']['_'] ?? ''] ?? 'other') : null,
            'edited'    => isset($m['edit_date']),
            'reply_to'  => isset($m['reply_to']['reply_to_msg_id']) ? (int) $m['reply_to']['reply_to_msg_id'] : null,
        ];
    }

    public static function media(?array $media): ?array
    {
        if ($media === null) {
            return null;
        }
        switch ($media['_'] ?? '') {
            case 'messageMediaPhoto':
                return isset($media['photo']['id']) ? ['kind' => 'photo'] : null;
            case 'messageMediaDocument':
                $doc = $media['document'] ?? null;
                if (!isset($doc['id'])) {
                    return null;
                }
                $kind = 'file';
                $name = null;
                foreach ($doc['attributes'] ?? [] as $attr) {
                    switch ($attr['_'] ?? '') {
                        case 'documentAttributeFilename':
                            $name = (string) $attr['file_name'];
                            break;
                        case 'documentAttributeSticker':
                            $kind = 'sticker';
                            break;
                        case 'documentAttributeAnimated':
                            $kind = $kind === 'sticker' ? 'sticker' : 'gif';
                            break;
                        case 'documentAttributeVideo':
                            if (!\in_array($kind, ['sticker', 'gif'], true)) {
                                $kind = !empty($attr['round_message']) ? 'round' : 'video';
                            }
                            break;
                        case 'documentAttributeAudio':
                            $kind = !empty($attr['voice']) ? 'voice' : 'audio';
                            break;
                    }
                }
                return [
                    'kind' => $kind,
                    'name' => $name,
                    'mime' => (string) ($doc['mime_type'] ?? 'application/octet-stream'),
                    'size' => (int) ($doc['size'] ?? 0),
                ];
            case 'messageMediaWebPage':
            case 'messageMediaEmpty':
                return null;
            case 'messageMediaGeo':
            case 'messageMediaGeoLive':
            case 'messageMediaVenue':
                return ['kind' => 'location'];
            case 'messageMediaContact':
                return ['kind' => 'contact', 'name' => trim(($media['first_name'] ?? '') . ' ' . ($media['last_name'] ?? '')), 'phone' => (string) ($media['phone_number'] ?? '')];
            case 'messageMediaPoll':
                $q = $media['poll']['question'] ?? '';
                return ['kind' => 'poll', 'name' => \is_array($q) ? (string) ($q['text'] ?? '') : (string) $q];
            default:
                return ['kind' => 'other'];
        }
    }

    /**
     * @param int $limit The limit that was requested, to know if more pages exist
     * @return array{dialogs: list<array>, next: ?array}
     */
    public static function dialogs(array $result, int $limit): array
    {
        $peers = self::peers($result);
        $messages = [];
        foreach ($result['messages'] ?? [] as $m) {
            if (isset($m['peer_id'], $m['id'])) {
                $messages[self::peerId($m['peer_id']) . ':' . $m['id']] = $m;
            }
        }

        $dialogs = [];
        $next = null;
        foreach ($result['dialogs'] ?? [] as $d) {
            if (($d['_'] ?? '') !== 'dialog') {
                continue;
            }
            $id = self::peerId($d['peer']);
            $top = $messages[$id . ':' . ($d['top_message'] ?? 0)] ?? null;
            $last = $top !== null ? self::message($top, $peers) : null;
            $dialogs[] = [
                'id'     => $id,
                'title'  => $peers[$id]['title'] ?? '',
                'kind'   => $peers[$id]['kind'] ?? 'user',
                'unread' => (int) ($d['unread_count'] ?? 0),
                'pinned' => !empty($d['pinned']),
                'last'   => $last,
            ];
            if ($top !== null) {
                $next = ['date' => (int) $top['date'], 'id' => (int) $top['id'], 'peer' => $id];
            }
        }

        // messages.dialogsSlice means more pages exist; messages.dialogs is the complete list
        $more = ($result['_'] ?? '') === 'messages.dialogsSlice' && \count($result['dialogs'] ?? []) >= $limit;
        return ['dialogs' => $dialogs, 'next' => $more ? $next : null];
    }

    /** @return list<array> oldest first */
    public static function history(array $result): array
    {
        $peers = self::peers($result);
        $out = [];
        foreach ($result['messages'] ?? [] as $m) {
            $n = self::message($m, $peers);
            if ($n !== null) {
                $out[] = $n;
            }
        }
        usort($out, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        return $out;
    }

    public static function me(array $self): array
    {
        return [
            'id'       => (int) ($self['id'] ?? 0),
            'name'     => self::title(['_' => 'user'] + $self),
            'username' => $self['username'] ?? null,
            'phone'    => isset($self['phone']) ? '+' . $self['phone'] : null,
        ];
    }
}
