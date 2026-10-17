Un client web Telegram pour les utilisateurs de votre YunoHost, construit sur [MadelineProto](https://github.com/danog/MadelineProto), une implémentation en PHP du protocole MTProto de Telegram (celui des applications officielles, pas l'API des bots).

Chaque utilisateur de YunoHost se connecte avec **son propre** compte Telegram (numéro de téléphone, code de connexion et mot de passe de validation en deux étapes). La session Telegram est conservée sur votre serveur et n'est accessible qu'à travers la connexion YunoHost de cet utilisateur.

### Fonctionnalités

- Liste des discussions avec compteurs de non-lus, discussions épinglées et recherche
- Lecture de l'historique des discussions privées, groupes et canaux
- Envoi de messages texte
- Mises à jour en direct : les messages nouveaux, modifiés, supprimés et lus apparaissent sans recharger
- Affichage des photos, autocollants, vidéos, messages vocaux et audio ; téléchargement de tout fichier
- Envoi de fichiers et de photos (bouton, glisser-déposer ou coller)
- Déconnexion, qui révoque la session Telegram et la supprime du serveur
- Interface en anglais, français et russe, thèmes clair et sombre, affichage mobile

### Fonctionnement

Un seul démon PHP héberge les sessions MadelineProto de tous les utilisateurs (10 utilisateurs ne coûtent donc pas 10 processus) et expose une petite API JSON sur l'interface locale. nginx sert l'interface web et transmet l'API derrière le SSO de YunoHost, qui indique au démon qui est l'utilisateur.
