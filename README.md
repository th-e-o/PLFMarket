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
- secrets `PLF_DB_DSN`, `PLF_DB_USER`, `PLF_DB_PASSWORD` : utiliser MySQL au lieu de SQLite
  (DSN : `mysql:host=XXX.mysql.db;dbname=XXX;charset=utf8mb4`).

Ensuite : `git push` sur `main` (ou *Actions → Déploiement OVH → Run workflow*).
Administration du jeu : adresse du site suivie de `#admin`.

Côté OVH, une seule chose : activer le certificat SSL (onglet *Informations générales*). Pour un
sous-domaine dédié (`paris.mondomaine.fr`) : onglet *Multisite* → *Ajouter un domaine*, dossier
racine identique à `FTP_DOSSIER`.

## Sauvegarde des données

Télécharger `data/plf.db` par FTP (FileZilla, explorateur FTP de l'espace client).

## Tester en local

```bash
PLF_ADMIN_PASSWORD=admin php scripts/generer-config.php
php -S 127.0.0.1:8000
```
