# Les paris du PLF 🔔 — version hébergement web OVH

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
- variable `PLF_CAPITAL` : clochettes de départ (1000 par défaut) ;
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

Adresse du site suivie de `#admin`, mot de passe `PLF_ADMIN_PASSWORD`. Trois sous-onglets :

- **Paris** : modifier un pari (textes, date limite, libellés ; ajouter ou retirer des issues sans
  mise tant qu'il est en cours), miser pour un joueur, supprimer une mise (remboursée), suspendre,
  clôturer, annuler, et revenir sur une clôture ou une annulation (les gains sont repris).
- **Joueurs** : renommer, donner un nouveau code, créditer ou débiter des clochettes (visible dans le
  fil), supprimer un joueur et ses mises.
- **Réglages** : capital de départ et amorce, qui priment alors sur `PLF_CAPITAL` et `PLF_AMORCE`.

## Mises à jour de la base

La structure de la base et les nouveaux paris livrés avec le code sont appliqués automatiquement au
premier appel après le déploiement (version enregistrée dans la table `reglages`), sans toucher aux
joueurs ni aux mises existants.

## Sauvegarde des données

Télécharger `data/plf.db` par FTP (FileZilla, explorateur FTP de l'espace client).

## Tester en local

```bash
PLF_ADMIN_PASSWORD=admin php scripts/generer-config.php
php -S 127.0.0.1:8000
```
