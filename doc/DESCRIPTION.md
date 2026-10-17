A Telegram web client for your YunoHost users, built on [MadelineProto](https://github.com/danog/MadelineProto), a PHP implementation of Telegram's MTProto protocol (the protocol of the official apps, not the Bot API).

Each YunoHost user signs in with **their own** Telegram account (phone number, login code and two-step verification password). The Telegram session is kept on your server and is only reachable through that user's YunoHost login.

### Features

- Chat list with unread counters, pinned chats and search
- Read the history of private chats, groups and channels
- Send text messages
- Live updates: new, edited, deleted and read messages appear without reloading
- View photos, stickers, videos, voice messages and audio; download any file
- Upload files and photos (button, drag & drop or paste)
- Log out, which revokes the Telegram session and deletes it from the server
- English, French and Russian interface, light and dark themes, mobile layout

### How it works

A single PHP daemon hosts every user's MadelineProto session (so 10 users don't cost 10 processes) and exposes a small JSON API on the loopback interface. nginx serves the web interface and forwards the API behind the YunoHost SSO, which tells the daemon who the user is.
