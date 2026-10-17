## Who can use it

Access follows the app's main permission (all users by default). Every YunoHost user who opens the app signs into **their own** Telegram account; nobody, administrators included, can see another user's chats through the web interface.

## Files and services

| What | Where |
|------|-------|
| Web client code, PHP dependencies | `/var/www/madeline/` (`www/` is the static UI, `src/` + `bin/daemon.php` the daemon) |
| Daemon configuration (api_id, api_hash, port, upload limit) | `/var/www/madeline/config.php`, regenerated on upgrade |
| Telegram sessions, one folder per YunoHost user | `/home/yunohost.app/madeline/sessions/<user>/` |
| Logs | `/var/log/madeline/madeline.log` (daemon), `/var/log/madeline/madelineproto.log` (Telegram library warnings) |
| Service | `madeline.service` (`systemctl status madeline`) |

The daemon listens on the Unix socket `/run/madeline/daemon.sock`, in a directory only nginx (`www-data`) may enter (`madeline:www-data`, mode 0750, created by `/etc/tmpfiles.d/madeline.conf`): it trusts the YunoHost user name nginx passes along, so no other local user or app must be able to talk to it. nginx proxies `<app path>/api/` to it and serves `<app path>/` from `www/`.

All saved sessions are loaded when the service starts, so users get their chats and live updates right away; right after a restart, the first page load may still take a few seconds.

## Telegram sessions are credentials

A session folder gives full access to that user's Telegram account. They are only readable by the `madeline` system user, but they are **included in YunoHost backups** of the app: protect your backup archives accordingly.

A user ends their session with the **Log out** button, which revokes it on Telegram's side and deletes it from the server. They can also terminate it from another Telegram app (*Settings → Devices*, shown as "YunoHost web"); the web client notices and asks to sign in again.

To force-remove the session of a user (e.g. a departing member):

```bash
sudo systemctl stop madeline
sudo rm -rf /home/yunohost.app/madeline/sessions/<user>
sudo systemctl start madeline
```

Uninstalling the app keeps the sessions unless you use `--purge`.

## Passcode lock

Each user can protect their messenger with a passcode (lock icon in the chat list header). It is enforced by the daemon, per browser:

- a browser has to be unlocked with the passcode; it then stays unlocked as long as it is used (typing, clicking, scrolling with the wheel, touching, moving the pointer in a visible tab). After the inactivity delay the user chose (1 minute to 24 hours, 15 minutes suggested), that browser is locked again: the daemon answers `LOCKED` to everything and closes its live-update stream;
- unlocking one browser or device does not unlock the others; every browser starts locked after the service restarts;
- the passcode is stored as a bcrypt hash in `/home/yunohost.app/madeline/locks/<user>.json`; after 5 wrong passcodes, attempts are delayed (30 s, doubling up to 1 hour);
- a user who forgot their passcode can log out of Telegram from the lock screen: this deletes the session and the lock, and they sign in again with their phone number.

To remove the lock of a user (they then need no passcode, their Telegram session is kept):

```bash
sudo rm /home/yunohost.app/madeline/locks/<user>.json
sudo systemctl restart madeline
```

## Changing settings

```bash
# Upload size limit, in MB (also applied to nginx)
sudo yunohost app setting madeline upload_max_mb -v 100
# Telegram api_id / api_hash
sudo yunohost app setting madeline api_id -v 1234567
sudo yunohost app setting madeline api_hash -v 0123456789abcdef0123456789abcdef
# Apply
sudo yunohost app upgrade madeline --force
```

## Memory

Everything runs in a single PHP process: about 30 MB on its own, plus roughly 20–100 MB per user logged into Telegram (depending on how many chats and contacts they have). A session is loaded on the user's first request after a restart and then stays in memory.

## Troubleshooting

- **"The Telegram api_id/api_hash … is not valid"**: check the values on <https://my.telegram.org>, then change them as shown above.
- **Login or media hangs**: make sure the server reaches every Telegram data center (outgoing TCP 443 to `149.154.160.0/20` and `91.108.0.0/16`); see `madelineproto.log`.
- **Live updates don't arrive**: they use Server-Sent Events on `<app path>/api/events`; reverse proxies or VPNs in front of YunoHost must not buffer that response.
