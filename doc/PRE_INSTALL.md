This app needs a Telegram **api_id** and **api_hash**, which identify *your* installation of the client to Telegram:

1. Sign in at <https://my.telegram.org> with any Telegram account (yours is fine).
2. Open **API development tools** and create an application (any title and short name, platform "Web").
3. Copy the **App api_id** (a number) and **App api_hash** (32 hexadecimal characters) into the installation form.

These credentials do not give access to your account and are shared by every user of this installation. Keep them private all the same: Telegram may limit or ban an api_id that is abused.

Your server must be able to reach Telegram's data centers (outgoing TCP port 443 to `149.154.160.0/20` and `91.108.0.0/16`); some hosting providers and networks block them. Plan about 30 MB of RAM for the app plus 20 to 100 MB per user who is logged into Telegram.
