<?php declare(strict_types=1);

// Feeds raw Telegram payloads through MadelineProto's own TL serializer and
// deserializer, so Normalizer sees exactly the shapes production gets (peers
// as bot-API ints, pre-signed chat/channel ids...), then checks its output.
// Run by tests/test_normalizer.sh inside the test container.

require '/app/vendor/autoload.php';

use danog\MadelineProto\Magic;
use danog\MadelineProto\MTProto;
use danog\MadelineProto\Settings\TLSchema;
use danog\MadelineProto\TL\TL;
use Madeline\Web\Normalizer;

Magic::start(light: true);
// Serializing only needs MTProto's pure bot-API argument mapping
$tl = new TL((new ReflectionClass(MTProto::class))->newInstanceWithoutConstructor());
$tl->init(new TLSchema());

$roundTrip = static function (array $raw) use ($tl): array {
    $binary = $tl->serializeObject(['type' => ''], $raw, 'test');
    return $tl->deserialize($binary, ['type' => '', 'connection' => null, 'subtype' => null, 'encrypted' => false]);
};
$failures = 0;
$check = static function (string $what, mixed $expected, mixed $actual) use (&$failures): void {
    if ($expected === $actual) {
        echo "PASS: $what\n";
        return;
    }
    $failures++;
    echo "FAIL: $what\n  expected: " . json_encode($expected, JSON_UNESCAPED_UNICODE) . "\n  actual:   " . json_encode($actual, JSON_UNESCAPED_UNICODE) . "\n";
};

const ALICE = 111;
const ME = 999;
const GROUP = 222;      // basic group, bot-API id -222
const CHANNEL = 333;    // broadcast channel, bot-API id -1000000000333
const SUPERGROUP = 444; // megagroup, bot-API id -1000000000444

$notify = ['_' => 'peerNotifySettings'];
$dialog = static fn (array $peer, int $top, int $unread = 0, bool $pinned = false) => [
    '_' => 'dialog', 'pinned' => $pinned, 'peer' => $peer, 'top_message' => $top, 'read_inbox_max_id' => 0,
    'read_outbox_max_id' => 0, 'unread_count' => $unread, 'unread_mentions_count' => 0,
    'unread_reactions_count' => 0, 'notify_settings' => $notify,
];
$msg = static fn (array $peer, int $id, string $text, array $extra = []) => [
    '_' => 'message', 'id' => $id, 'peer_id' => $peer, 'date' => 1700000000 + $id, 'message' => $text,
] + $extra;
$user = static fn (int $id, string $first, array $extra = []) => ['_' => 'user', 'id' => $id, 'first_name' => $first, 'access_hash' => 7] + $extra;
$photo = ['_' => 'chatPhotoEmpty'];

$peerUser = ['_' => 'peerUser', 'user_id' => ALICE];
$peerGroup = ['_' => 'peerChat', 'chat_id' => GROUP];
$peerChannel = ['_' => 'peerChannel', 'channel_id' => CHANNEL];
$peerSuper = ['_' => 'peerChannel', 'channel_id' => SUPERGROUP];
$peerMe = ['_' => 'peerUser', 'user_id' => ME];

$users = [$user(ALICE, 'Alice', ['last_name' => 'Liddell']), $user(ME, 'Me', ['self' => true]), $user(555, '', ['deleted' => true])];
$chats = [
    ['_' => 'chat', 'id' => GROUP, 'title' => 'Family', 'photo' => $photo, 'participants_count' => 3, 'date' => 1, 'version' => 1],
    ['_' => 'channel', 'id' => CHANNEL, 'title' => 'News', 'photo' => $photo, 'date' => 1, 'access_hash' => 1, 'broadcast' => true],
    ['_' => 'channel', 'id' => SUPERGROUP, 'title' => 'Club', 'photo' => $photo, 'date' => 1, 'access_hash' => 1, 'megagroup' => true],
];

// ---- dialogs -----------------------------------------------------------
$raw = ['_' => 'messages.dialogsSlice', 'count' => 100, 'dialogs' => [
    $dialog($peerUser, 10, 2),
    $dialog($peerGroup, 20),
    $dialog($peerChannel, 30, 0, true),
    $dialog($peerSuper, 40),
    $dialog($peerMe, 50),
], 'messages' => [
    $msg($peerUser, 10, 'hello', ['from_id' => $peerUser]),
    $msg($peerGroup, 20, 'family news', ['from_id' => $peerUser]),
    $msg($peerChannel, 30, 'breaking'),
    $msg($peerSuper, 40, 'club chat', ['from_id' => $peerUser]),
    $msg($peerMe, 50, 'note to self', ['out' => true, 'from_id' => $peerMe]),
], 'chats' => $chats, 'users' => $users];
$result = Normalizer::dialogs($roundTrip($raw), 5);

$check('dialog ids are bot-API ids', [ALICE, -GROUP, -1000000000000 - CHANNEL, -1000000000000 - SUPERGROUP, ME], array_column($result['dialogs'], 'id'));
$check('dialog titles', ['Alice Liddell', 'Family', 'News', 'Club', 'Me'], array_column($result['dialogs'], 'title'));
$check('dialog kinds', ['user', 'group', 'channel', 'group', 'self'], array_column($result['dialogs'], 'kind'));
$check('unread counts and pins', [[2, false], [0, false], [0, true], [0, false], [0, false]],
    array_map(static fn ($d) => [$d['unread'], $d['pinned']], $result['dialogs']));
$check('last message attached to its dialog', ['hello', 'family news', 'breaking', 'club chat', 'note to self'],
    array_map(static fn ($d) => $d['last']['text'] ?? null, $result['dialogs']));
$check('next page cursor points at the last dialog', ['date' => 1700000050, 'id' => 50, 'peer' => ME], $result['next']);
$check('channel ids are recognised as channels', [false, false, true, true],
    array_map(Normalizer::isChannelId(...), [ALICE, -GROUP, -1000000000000 - CHANNEL, -1000000000000 - SUPERGROUP]));

$raw['_'] = 'messages.dialogs';
unset($raw['count']);
$check('a complete dialog list has no next page', null, Normalizer::dialogs($roundTrip($raw), 5)['next']);

// ---- history -----------------------------------------------------------
$doc = static fn (array $attributes, string $mime = 'application/octet-stream', int $size = 1234) => [
    '_' => 'messageMediaDocument', 'document' => [
        '_' => 'document', 'id' => 1, 'access_hash' => 2, 'file_reference' => '', 'date' => 1,
        'mime_type' => $mime, 'size' => $size, 'dc_id' => 2, 'attributes' => $attributes,
    ],
];
$raw = ['_' => 'messages.channelMessages', 'pts' => 1, 'count' => 6, 'messages' => [
    $msg($peerSuper, 46, 'see file', ['from_id' => $peerUser, 'media' => $doc([['_' => 'documentAttributeFilename', 'file_name' => 'report.pdf']], 'application/pdf', 4096)]),
    $msg($peerSuper, 45, '', ['out' => true, 'from_id' => $peerMe, 'media' => $doc([['_' => 'documentAttributeAudio', 'voice' => true, 'duration' => 3]], 'audio/ogg')]),
    $msg($peerSuper, 44, '', ['from_id' => $peerUser, 'media' => $doc([['_' => 'documentAttributeSticker', 'alt' => ':)', 'stickerset' => ['_' => 'inputStickerSetEmpty']], ['_' => 'documentAttributeImageSize', 'w' => 512, 'h' => 512]], 'image/webp')]),
    $msg($peerSuper, 43, 'pic', ['from_id' => $peerUser, 'edit_date' => 1700000100, 'media' => ['_' => 'messageMediaPhoto', 'photo' => [
        '_' => 'photo', 'id' => 9, 'access_hash' => 1, 'file_reference' => '', 'date' => 1, 'sizes' => [], 'dc_id' => 2,
    ]]]),
    ['_' => 'messageService', 'id' => 42, 'peer_id' => $peerSuper, 'from_id' => $peerUser, 'date' => 1700000042,
        'action' => ['_' => 'messageActionChatAddUser', 'users' => [ALICE]]],
    $msg($peerSuper, 41, 'reply', ['from_id' => ['_' => 'peerUser', 'user_id' => 555], 'reply_to' => ['_' => 'messageReplyHeader', 'reply_to_msg_id' => 40]]),
], 'chats' => $chats, 'users' => $users];
$history = Normalizer::history($roundTrip($raw));

$check('history is sorted oldest first', [41, 42, 43, 44, 45, 46], array_column($history, 'id'));
$check('every message belongs to the supergroup', array_fill(0, 6, -1000000000000 - SUPERGROUP), array_column($history, 'chat'));
$check('senders are bot-API user ids', [555, ALICE, ALICE, ALICE, ME, ALICE], array_column($history, 'from'));
$check('sender names (deleted account has none)', ['', 'Alice Liddell', 'Alice Liddell', 'Alice Liddell', 'Me', 'Alice Liddell'], array_column($history, 'from_name'));
$check('media kinds', [null, null, 'photo', 'sticker', 'voice', 'file'], array_map(static fn ($m) => $m['media']['kind'] ?? null, $history));
$check('file name, mime and size', ['kind' => 'file', 'name' => 'report.pdf', 'mime' => 'application/pdf', 'size' => 4096], $history[5]['media']);
$check('service message', ['joined', ''], [$history[1]['service'], $history[1]['text']]);
$check('reply and edit flags', [40, false, true], [$history[0]['reply_to'], $history[0]['edited'], $history[2]['edited']]);
$check('outgoing flag', [false, true], [$history[3]['out'], $history[4]['out']]);

// ---- replies -------------------------------------------------------------
$header = static fn (int $to, array $extra = []) => ['reply_to' => ['_' => 'messageReplyHeader', 'reply_to_msg_id' => $to] + $extra];
$replies = Normalizer::history($roundTrip(['_' => 'messages.channelMessages', 'pts' => 1, 'count' => 7, 'messages' => [
    $msg($peerSuper, 60, "original\n  text", ['from_id' => $peerUser, 'media' => ['_' => 'messageMediaPhoto', 'photo' => [
        '_' => 'photo', 'id' => 9, 'access_hash' => 1, 'file_reference' => '', 'date' => 1, 'sizes' => [], 'dc_id' => 2,
    ]]]),
    $msg($peerSuper, 61, 'answer', ['out' => true, 'from_id' => $peerMe] + $header(60)),
    $msg($peerSuper, 62, 'quoting', ['from_id' => $peerUser] + $header(60, ['quote' => true, 'quote_text' => 'orig'])),
    $msg($peerSuper, 63, 'old one', ['from_id' => $peerUser] + $header(10)),
    $msg($peerSuper, 64, 'in a topic', ['from_id' => $peerUser] + $header(5, ['forum_topic' => true])),
    $msg($peerSuper, 65, 'reply in a topic', ['from_id' => $peerUser] + $header(61, ['forum_topic' => true, 'reply_to_top_id' => 5])),
    $msg($peerSuper, 66, 'elsewhere', ['from_id' => $peerUser] + $header(30, ['reply_to_peer_id' => $peerChannel])),
], 'chats' => $chats, 'users' => $users]));
$byId = array_column($replies, null, 'id');
$check('a reply to a message of the same page is resolved',
    ['id' => 60, 'chat' => -1000000000000 - SUPERGROUP, 'from' => ALICE, 'from_name' => 'Alice Liddell', 'out' => false, 'text' => 'original text', 'quote' => false, 'media' => 'photo', 'service' => null, 'deleted' => false],
    $byId[61]['reply']);
$check('reply_to is the replied-to id', 60, $byId[61]['reply_to']);
$check('a quote shows the quoted part', ['orig', true, 'Alice Liddell'], [$byId[62]['reply']['text'], $byId[62]['reply']['quote'], $byId[62]['reply']['from_name']]);
$check('a reply to a message not on the page stays a stub', [10, null, '', false], [$byId[63]['reply_to'], $byId[63]['reply']['from_name'], $byId[63]['reply']['text'], $byId[63]['reply']['deleted']]);
$check('a forum-topic header alone is no reply', [null, null], [$byId[64]['reply_to'], $byId[64]['reply']]);
$check('a reply inside a forum topic is one', [61, true], [$byId[65]['reply_to'], $byId[65]['reply']['out']]);
$check('a reply to another chat cannot be jumped to', [null, -1000000000000 - CHANNEL, 'News'], [$byId[66]['reply_to'], $byId[66]['reply']['chat'], $byId[66]['reply']['from_name']]);
$check('replied-to messages missing from the page', [10], Normalizer::missingReplies($replies, -1000000000000 - SUPERGROUP));

// What MadelineAccount::resolveReplies() gets back from channels.getMessages
$fetched = $roundTrip(['_' => 'messages.channelMessages', 'pts' => 1, 'count' => 2, 'messages' => [
    $msg($peerSuper, 10, 'long ago ' . str_repeat('x', 200), ['from_id' => ['_' => 'peerUser', 'user_id' => 555]]),
    ['_' => 'messageEmpty', 'id' => 11],
], 'chats' => $chats, 'users' => $users]);
$known = Normalizer::messagesById($fetched, -1000000000000 - SUPERGROUP);
$check('fetched messages by id, deleted ones as null', [10, 11], array_keys($known));
$check('deleted replied-to message', null, $known[11]);
$resolved = Normalizer::withReplies([$byId[63], ['reply_to' => 11, 'reply' => ['id' => 11, 'quote' => false, 'deleted' => false] + $byId[63]['reply']] + $byId[63]], $known);
$check('a fetched replied-to message completes the stub', [555, '', 151], [$resolved[0]['reply']['from'], $resolved[0]['reply']['from_name'], mb_strlen($resolved[0]['reply']['text'])]);
$check('a reply to a deleted message says so', true, $resolved[1]['reply']['deleted']);

$forwarded = Normalizer::message($roundTrip($msg($peerSuper, 70, 'x', ['from_id' => $peerUser] + $header(3, [
    'reply_to_peer_id' => $peerChannel, 'reply_from' => ['_' => 'messageFwdHeader', 'date' => 1, 'from_name' => 'Hidden Sender'],
]))), []);
$check('a hidden original sender is named by the reply header', 'Hidden Sender', $forwarded['reply']['from_name']);

$pin = Normalizer::history($roundTrip(['_' => 'messages.messages', 'messages' => [
    $msg($peerUser, 7, 'pin me'),
    ['_' => 'messageService', 'id' => 8, 'peer_id' => $peerUser, 'from_id' => $peerUser, 'date' => 1700000008,
        'action' => ['_' => 'messageActionPinMessage']] + $header(7),
], 'chats' => [], 'users' => $users]));
$check('a pin names the pinned message', ['pinned', 'pin me'], [$pin[1]['service'], $pin[1]['reply']['text']]);

// ---- private chat without from_id, and channel posts --------------------
$private = Normalizer::history($roundTrip(['_' => 'messages.messages', 'messages' => [
    $msg($peerUser, 7, 'incoming'),
    $msg($peerUser, 8, 'outgoing', ['out' => true]),
], 'chats' => [], 'users' => $users]));
$check('incoming private message is from the chat partner', [ALICE, 'Alice Liddell'], [$private[0]['from'], $private[0]['from_name']]);
$check('outgoing private message has no sender name', [null, null], [$private[1]['from'], $private[1]['from_name']]);
$post = Normalizer::history($roundTrip(['_' => 'messages.messages', 'messages' => [$msg($peerChannel, 3, 'post')], 'chats' => $chats, 'users' => []]));
$check('channel post is attributed to the channel', [-1000000000000 - CHANNEL, 'News'], [$post[0]['from'], $post[0]['from_name']]);

// ---- updates (what the live pump receives) -------------------------------
$update = $roundTrip(['_' => 'updateNewChannelMessage', 'message' => $msg($peerSuper, 47, 'live', ['from_id' => $peerUser]), 'pts' => 1, 'pts_count' => 1]);
$message = Normalizer::message($update['message'], [-1000000000000 - SUPERGROUP => ['title' => 'Club', 'kind' => 'group'], ALICE => ['title' => 'Alice', 'kind' => 'user']]);
$check('live message ids', [47, -1000000000000 - SUPERGROUP, ALICE, 'Alice'], [$message['id'], $message['chat'], $message['from'], $message['from_name']]);
$deleted = $roundTrip(['_' => 'updateDeleteChannelMessages', 'channel_id' => SUPERGROUP, 'messages' => [1, 2], 'pts' => 1, 'pts_count' => 1]);
$check('channel ids in updates are already bot-API ids', -1000000000000 - SUPERGROUP, Normalizer::peerId((int) $deleted['channel_id']));
$read = $roundTrip(['_' => 'updateReadHistoryInbox', 'peer' => $peerGroup, 'max_id' => 5, 'still_unread_count' => 1, 'pts' => 1, 'pts_count' => 1]);
$check('peers in updates are bot-API ids', -GROUP, Normalizer::peerId($read['peer']));

// ---- self ----------------------------------------------------------------
$self = $roundTrip($user(ME, 'Ivan', ['self' => true, 'last_name' => 'Petrov', 'username' => 'ivan', 'phone' => '79001234567']));
$check('me', ['id' => ME, 'name' => 'Ivan Petrov', 'username' => 'ivan', 'phone' => '+79001234567'], Normalizer::me($self));

echo $failures === 0 ? "\nAll normalizer tests passed.\n" : "\n$failures normalizer test(s) FAILED.\n";
exit($failures === 0 ? 0 : 1);
