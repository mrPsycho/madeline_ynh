Cette application a besoin d'un **api_id** et d'un **api_hash** Telegram, qui identifient *votre* installation du client auprès de Telegram :

1. Connectez-vous sur <https://my.telegram.org> avec n'importe quel compte Telegram (le vôtre convient).
2. Ouvrez **API development tools** et créez une application (titre et nom court au choix, plateforme « Web »).
3. Copiez l'**App api_id** (un nombre) et l'**App api_hash** (32 caractères hexadécimaux) dans le formulaire d'installation.

Ces identifiants ne donnent pas accès à votre compte et sont partagés par tous les utilisateurs de cette installation. Gardez-les tout de même privés : Telegram peut limiter ou bannir un api_id utilisé de manière abusive.

Votre serveur doit pouvoir joindre les centres de données de Telegram (TCP sortant sur le port 443 vers `149.154.160.0/20` et `91.108.0.0/16`) ; certains hébergeurs et réseaux les bloquent. Prévoyez environ 30 Mo de RAM pour l'application, plus 20 à 100 Mo par utilisateur connecté à Telegram.
