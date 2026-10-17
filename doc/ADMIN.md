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

The daemon listens on `127.0.0.1` only; nginx proxies `<app path>/api/` to it and serves `<app path>/` from `www/`.

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
