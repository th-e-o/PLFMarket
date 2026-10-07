# Les paris du PLF 🪙 — version hébergement web OVH

Site statique (HTML/CSS/JS) + `api.php` (PHP ≥ 8.1). Base de données : un fichier SQLite créé
automatiquement dans `data/` (rien à configurer), ou MySQL en option.

## Déploiement automatique (push sur `main` → site à jour)

Le workflow [.github/workflows/deploiement.yml](.github/workflows/deploiement.yml) vérifie le PHP,
génère `config.php` à partir des secrets, puis envoie par FTP les seuls fichiers modifiés.
La base (`data/*.db`) n'est jamais écrasée.

À configurer une fois dans GitHub : *Settings → Secrets and variables → Actions*.

| Secret                | Valeur                                                                        |
|-----------------------|-------------------------------------------------------------------------------|
| `FTP_SERVER`          | Serveur FTP (espace client OVH → Hébergements → onglet *FTP-SSH*, ex. `ftp.clusterXXX.hosting.ovh.net`) |
| `FTP_USERNAME`        | Identifiant FTP (même onglet)                                                 |
| `FTP_PASSWORD`        | Mot de passe FTP (modifiable dans ce même onglet)                             |
| `PLF_ADMIN_PASSWORD`  | Mot de passe de l'administration du jeu                                       |

Facultatif :
- variable `FTP_DOSSIER` (onglet *Variables*) : dossier cible, `www/` par défaut (ex. `www/plf/`, avec le `/` final) ;
- variable `PLF_CAPITAL` : deniers publics de départ (1000 par défaut) ;
- variable `PLF_AMORCE` : mise fictive de la banque sur chaque issue, qui fixe les cotes de départ
  et leur stabilité (100 par défaut, 0 pour un pari mutuel pur) ;
- secrets `PLF_DB_DSN`, `PLF_DB_USER`, `PLF_DB_PASSWORD` : utiliser MySQL au lieu de SQLite
  (DSN : `mysql:host=XXX.mysql.db;dbname=XXX;charset=utf8mb4`).

Ensuite : `git push` sur `main` (ou *Actions → Déploiement OVH → Run workflow*).
Administration du jeu : adresse du site suivie de `#admin`.

Côté OVH, une seule chose : activer le certificat SSL (onglet *Informations générales*). Pour un
sous-domaine dédié (`paris.mondomaine.fr`) : onglet *Multisite* → *Ajouter un domaine*, dossier
racine identique à `FTP_DOSSIER`.

## Administration

Adresse du site suivie de `#admin`, mot de passe `PLF_ADMIN_PASSWORD`. Quatre sous-onglets (l'administration peut aussi supprimer n'importe quel commentaire) :

- **Paris** : « Tout suspendre » (coupe d'un coup les mises de tous les paris ouverts, en séance) puis
  « Rouvrir » (seulement les paris ainsi suspendus) ; modifier un pari (textes, date limite, étape du
  calendrier, libellés ; ajouter ou retirer des issues sans mise tant qu'il est en cours), miser pour un
  joueur, supprimer une mise (remboursée), suspendre, clôturer, annuler, et revenir sur une clôture ou une
  annulation (les gains sont repris). À la clôture, indiquer **l'heure où le résultat a été connu** : les
  mises placées depuis sont remboursées et ne comptent pas dans la cagnotte.
- **Joueurs** : renommer, donner un nouveau code, changer d'équipe, créditer ou débiter des deniers publics
  (visible dans le fil), supprimer un joueur et ses mises.
- **Calendrier** : étapes de l'examen du PLF (date, intitulé), affichées en frise dans l'onglet Calendrier ;
  chaque pari peut être rattaché à une étape.
- **Réglages** : capital de départ et amorce (priment sur `PLF_CAPITAL` et `PLF_AMORCE`), code
  d'invitation (exigé à l'inscription s'il est défini), équipes, sauvegarde téléchargeable.

## Page d'accueil « marché »

Premier écran du site : chiffres en direct (deniers misés aujourd'hui, marchés ouverts, joueurs, deniers en
jeu, animés quand ils changent), dernière dépêche, flux des derniers événements, **pari du jour** (mise
directe et graphique des probabilités), tuiles **Tendances** (probabilité de chaque issue, variation en
points, mini-courbe sur 30 jours), paris qui ferment bientôt, derniers résultats, prochaine étape.

- **Probabilités** : partout, les issues affichent la probabilité implicite de leur cote (1 / cote, ramenée à
  100 %) ; la cote reste indiquée en petit (« rapporte ×1,39 »).
- **Dépêches** (Admin → Paris → « Publier une dépêche », ex. « Réunion à Matignon ») : annoncées dans le
  bandeau et sur l'accueil, repères sur les courbes ; pendant 7 jours, les variations sont calculées depuis
  la dernière dépêche si le marché a bougé depuis (sinon sur 24 h), et les mouvements de 10 points ou plus
  sont annoncés automatiquement (« 📈 49.3 sur le PLF ? : « Oui » vient de prendre 14 pts depuis
  « Réunion à Matignon » »). Sans dépêche, seuls les mouvements de 15 points sur 24 h le sont.
- **Pari du jour** : choisi par l'administration pour la journée (bouton « ⭐ En faire le pari du jour »),
  sinon le pari ouvert le plus animé des dernières 24 heures.
- **Fiche joueur** (clic sur un pseudo) : rang, variation du total sur 24 h et 7 jours, % de paris gagnés,
  série en cours, meilleur pari, plus grosse perte, trophées, dernières mises (estimations en cours
  jamais dévoilées). Elle figure aussi dans « Mon profil ».

## Joueurs

- **Inscription** (bouton « Créer un compte ») : pseudo, code secret, code d'invitation s'il est défini, et
  équipe facultative : un nom existant la rejoint (casse, accents, espaces et ponctuation ignorés :
  « dg 75 » = « DG75 »), un nouveau nom la crée.
- **Mon profil** (clic sur son pseudo) : rang, équipe (rejoindre, créer, quitter), trophées obtenus.
- **Commentaires** (« Exposé des motifs ») sous chaque pari : 280 caractères, 5 par 2 minutes ;
  suppression par l'auteur ou l'administration.
- **Trophées** : 🔮 Nostradamus (mise gagnée à ×5 ou plus), 🔥 En série / 🧊 Cassandre (3 paris gagnés /
  perdus d'affilée), 🎲 49.3 (tout son solde misé d'un coup), ⚡ Éclair (pari flash gagné), 🎯 Dans le mille
  (valeur exacte d'un pari chiffré), 📜 Rapporteur général (pari proposé ayant attiré 5 joueurs).
  Calculés à partir de l'historique, annoncés dans le fil.

## Paris flash

Admin → Paris → « Lancer un pari flash » : intitulé, issues (« Oui / Non » par défaut), durée de 2 à
60 minutes. Le pari s'affiche en tête avec un compte à rebours, est annoncé dans le bandeau, et se ferme
seul à l'échéance (arrondie à la minute supérieure). Il se clôture ensuite comme les autres.

## Règles d'équité et sécurité

- **Mises tardives** : remboursées si placées après l'heure de réalisation indiquée à la clôture
  (les mises saisies par l'administration, transmises à temps, ne le sont jamais).
- **Anti-force brute** : 5 codes erronés en 15 min bloquent le compte visé ; 10 mots de passe admin erronés
  ou 10 codes d'invitation erronés bloquent l'adresse IP (15 min). Limites dans les constantes `LIMITE_*`
  d'`api.php`, larges par adresse IP car les collègues peuvent partager celle du proxy.
- **Code d'invitation** : contre les inconnus et les comptes multiples.

## Types de paris

- **Choix** : 2 à 8 issues, pari mutuel amorcé par la banque (voir les règles du jeu sur le site).
- **Estimation d'un chiffre** : chaque joueur donne une estimation (secrète jusqu'à la clôture) et une
  mise ; la ou les estimations les plus proches de la valeur réelle se partagent la cagnotte et l'apport de
  la banque (l'amorce), au prorata des mises.

## Performances

Le navigateur interroge le serveur toutes les 4 s en envoyant la version de l'état déjà reçue ; tant que
rien n'a changé (aucune écriture, même minute), le serveur répond `{"inchange": true}` sans recalculer.
Les courbes (évolution des cotes d'un pari, des deniers publics des joueurs) sont chargées à la demande.

## Mises à jour de la base

La structure de la base et les nouveaux paris livrés avec le code sont appliqués automatiquement au
premier appel après le déploiement (version enregistrée dans la table `reglages`), sans toucher aux
joueurs ni aux mises existants.

## Sauvegarde des données

Administration → Réglages → « Télécharger une sauvegarde complète » (fichier `.db` avec SQLite, export
JSON avec MySQL). Pour restaurer une base SQLite, remplacer `data/plf.db` par ce fichier via FTP.
Ne pas la verser dans le dépôt GitHub, qui est public.

## Tester en local

```bash
PLF_ADMIN_PASSWORD=admin php scripts/generer-config.php
php -S 127.0.0.1:8000
```
