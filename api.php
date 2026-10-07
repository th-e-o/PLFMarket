<?php
/**
 * Jeu de paris sur l'examen du PLF : API JSON (MySQL ou SQLite).
 * Appel : api.php?r=<route>, en GET (lecture) ou POST (écriture, corps JSON).
 */

declare(strict_types=1);
date_default_timezone_set('Europe/Paris');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const STATUTS_ACTIFS = ['ouvert', 'suspendu'];

// Paris d'illustration, créés à l'installation si 'exemples' => true (retirés en production par la version 5).
const PARIS_EXEMPLES = [
    ['Procédure', "Le Gouvernement engagera-t-il sa responsabilité (art. 49.3) sur le PLF ?",
     "Sur au moins une partie du texte, à n'importe quelle lecture.", ['Oui', 'Non']],
    ['Vote', "Une motion de censure sera-t-elle adoptée pendant l'examen du PLF ?",
     "Motion déposée en réponse à un 49.3 ou motion spontanée (art. 49.2).", ['Oui', 'Non']],
    ['Vote', "La première partie (recettes) sera-t-elle adoptée par l'Assemblée en 1re lecture ?",
     "Vote sur l'ensemble de la première partie, hors 49.3.", ['Oui', 'Non']],
    ['Procédure', "La commission mixte paritaire (CMP) sera-t-elle conclusive ?",
     "« Non » inclut le cas où aucune CMP ne se réunit.", ['Oui', 'Non']],
    ['Calendrier', "Quand la loi de finances sera-t-elle promulguée ?",
     "Date de publication au Journal officiel.",
     ['Avant le 20 décembre', 'Entre le 20 et le 31 décembre', 'Après le 31 décembre (loi spéciale)']],
    ['Conseil constitutionnel', "Le Conseil constitutionnel censurera-t-il au moins une disposition ?",
     "Censure totale ou partielle, cavaliers budgétaires compris.", ['Oui', 'Non']],
    ['Chiffres', "Combien d'amendements seront déposés en séance à l'Assemblée en 1re lecture ?",
     "Total première et seconde parties, tel que publié par l'Assemblée.",
     ['Moins de 3 000', 'De 3 000 à 5 000', 'Plus de 5 000']],
];

// Paris ajoutés par la version 2 de la base (aussi sur les sites déjà installés).
const PARIS_V2 = [
    ['Vote', "Le RN s'abstient", "Lors du vote sur l'ensemble du PLF à l'Assemblée.", ['Oui', 'Non']],
    ['Communication', "La comm poste une photo de BLF sur LinkedIn", '', ['Oui', 'Non']],
    ['Séance', "BLF apparaît au banc", '', ['Oui', 'Non']],
    ['Séance', "BPB part en balade au Sénat", '', ['Oui', 'Non']],
    ['Petites phrases', "Tanguy dit « BlaBla »", '', ['Oui', 'Non']],
    ['Chiffres', "Le déficit public de la LFI sera supérieur à 5 % du PIB",
     "Déficit public prévu par la loi de finances initiale promulguée.", ['Oui', 'Non']],
];

const VERSION_BASE = 9;
const PROPOSITIONS_PAR_JOUR = 5;
const DUREES_FLASH = [2, 5, 10, 15, 30, 60]; // minutes
const COMMENTAIRE_MAX = 280; // caractères
const COMMENTAIRES_PAR_FENETRE = [5, 120]; // au plus 5 commentaires par joueur en 2 minutes
const RAPPORTEUR_SEUIL = 5; // joueurs attirés par un pari proposé

// Trophées : code => [icône, nom, condition]. Calculés à partir de l'historique (voir trophees()).
const TROPHEES = [
    'nostradamus' => ['🔮', 'Nostradamus', 'A gagné une mise à ×5 ou plus'],
    'serie' => ['🔥', 'En série', "A gagné 3 paris d'affilée"],
    'cassandre' => ['🧊', 'Cassandre', "A perdu 3 paris d'affilée"],
    'tapis' => ['🎲', '49.3', "A misé tout son solde disponible d'un coup"],
    'eclair' => ['⚡', 'Éclair', 'A gagné un pari flash'],
    'mille' => ['🎯', 'Dans le mille', "A trouvé la valeur exacte d'un pari sur un chiffre"],
    'rapporteur' => ['📜', 'Rapporteur général', 'A proposé un pari qui a attiré au moins ' . RAPPORTEUR_SEUIL . ' joueurs'],
];
const TYPES_PARI = ['choix', 'estimation'];

// Limites anti-force brute : [nombre maximal, fenêtre en secondes]. Les collègues pouvant partager
// une même adresse IP (proxy), les limites par IP sont larges ; la limite par pseudo protège chaque compte.
const LIMITE_CONNEXION_PSEUDO = [5, 900];
const LIMITE_CONNEXION_IP = [50, 900];
const LIMITE_ADMIN_IP = [10, 900];
const LIMITE_INVITATION_IP = [10, 900];
const LIMITE_INSCRIPTION_IP = [30, 3600];

const SCHEMA_SQLITE = <<<SQL
CREATE TABLE IF NOT EXISTS joueurs (
    id INTEGER PRIMARY KEY, pseudo TEXT NOT NULL UNIQUE COLLATE NOCASE,
    pin_hash TEXT NOT NULL, cree_le TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS paris (
    id INTEGER PRIMARY KEY, titre TEXT NOT NULL, description TEXT NOT NULL DEFAULT '',
    categorie TEXT NOT NULL DEFAULT '', statut TEXT NOT NULL DEFAULT 'ouvert',
    date_limite TEXT, issue_gagnante_id INTEGER, cree_le TEXT NOT NULL, clos_le TEXT);
CREATE TABLE IF NOT EXISTS issues (
    id INTEGER PRIMARY KEY, pari_id INTEGER NOT NULL REFERENCES paris(id) ON DELETE CASCADE,
    libelle TEXT NOT NULL, probabilite REAL NOT NULL, ordre INTEGER NOT NULL DEFAULT 0);
CREATE TABLE IF NOT EXISTS mises (
    id INTEGER PRIMARY KEY, joueur_id INTEGER NOT NULL REFERENCES joueurs(id),
    pari_id INTEGER NOT NULL REFERENCES paris(id), issue_id INTEGER NOT NULL REFERENCES issues(id),
    montant INTEGER NOT NULL, cote_c INTEGER NOT NULL, gain INTEGER, cree_le TEXT NOT NULL);
CREATE INDEX IF NOT EXISTS idx_mises_joueur ON mises(joueur_id);
CREATE INDEX IF NOT EXISTS idx_mises_pari ON mises(pari_id);
CREATE INDEX IF NOT EXISTS idx_mises_issue ON mises(issue_id);
CREATE TABLE IF NOT EXISTS reglages (cle TEXT PRIMARY KEY, valeur TEXT NOT NULL);
SQL;

const SCHEMA_MYSQL = <<<SQL
CREATE TABLE IF NOT EXISTS joueurs (
    id INT AUTO_INCREMENT PRIMARY KEY, pseudo VARCHAR(30) NOT NULL UNIQUE,
    pin_hash VARCHAR(255) NOT NULL, cree_le CHAR(19) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS paris (
    id INT AUTO_INCREMENT PRIMARY KEY, titre VARCHAR(500) NOT NULL, description TEXT NOT NULL,
    categorie VARCHAR(100) NOT NULL DEFAULT '', statut VARCHAR(10) NOT NULL DEFAULT 'ouvert',
    date_limite CHAR(16) NULL, issue_gagnante_id INT NULL, cree_le CHAR(19) NOT NULL, clos_le CHAR(19) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS issues (
    id INT AUTO_INCREMENT PRIMARY KEY, pari_id INT NOT NULL, libelle VARCHAR(300) NOT NULL,
    probabilite DOUBLE NOT NULL, ordre INT NOT NULL DEFAULT 0,
    FOREIGN KEY (pari_id) REFERENCES paris(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS mises (
    id INT AUTO_INCREMENT PRIMARY KEY, joueur_id INT NOT NULL, pari_id INT NOT NULL, issue_id INT NOT NULL,
    montant INT NOT NULL, cote_c INT NOT NULL, gain INT NULL, cree_le CHAR(19) NOT NULL,
    FOREIGN KEY (joueur_id) REFERENCES joueurs(id), FOREIGN KEY (pari_id) REFERENCES paris(id),
    FOREIGN KEY (issue_id) REFERENCES issues(id), INDEX (joueur_id), INDEX (pari_id), INDEX (issue_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS reglages (
    cle VARCHAR(50) PRIMARY KEY, valeur TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

class ErreurApi extends Exception {}

function repondre(array $donnees, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($donnees, JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------------------
// Configuration, session, base de données
// ---------------------------------------------------------------------------

if (!is_file(__DIR__ . '/config.php')) {
    repondre(['erreur' => 'Fichier config.php manquant : copiez config.exemple.php en config.php.'], 500);
}
define('CONFIG', require __DIR__ . '/config.php');
const CAPITAL_DEFAUT = CONFIG['capital'] ?? 1000;
const AMORCE_DEFAUT = CONFIG['amorce'] ?? 100;
// Sans base MySQL configurée : fichier SQLite dans data/ (créé automatiquement, protégé par data/.htaccess).
define('DB', CONFIG['db'] ?? ['dsn' => 'sqlite:' . __DIR__ . '/data/plf.db']);

function demarrer_session(): void
{
    $duree = 120 * 86400;
    $dossier = __DIR__ . '/data/sessions';
    if (!is_dir($dossier)) @mkdir($dossier, 0700, true);
    if (is_writable($dossier)) session_save_path($dossier); // évite le nettoyage des sessions partagées
    ini_set('session.gc_maxlifetime', (string)$duree);
    session_name('plf_session');
    session_set_cookie_params([
        'lifetime' => $duree,
        'path' => rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\') . '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $c = DB;
    if (est_sqlite() && !extension_loaded('pdo_sqlite')) {
        throw new ErreurApi("SQLite n'est pas disponible sur cet hébergement : configurez une base MySQL dans config.php.", 500);
    }
    $pdo = new PDO($c['dsn'], $c['user'] ?? null, $c['password'] ?? null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    if (est_sqlite()) {
        $pdo->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 15000; PRAGMA journal_mode = WAL;');
    }
    initialiser_base($pdo);
    return $pdo;
}

function est_sqlite(): bool
{
    return str_starts_with(DB['dsn'], 'sqlite:');
}

/** Réglage modifié depuis l'administration (table reglages), sinon valeur de config.php. */
function reglage(string $cle, int $defaut): int
{
    $valeur = q('SELECT valeur FROM reglages WHERE cle = ?', [$cle])->fetchColumn();
    return $valeur === false ? $defaut : (int)$valeur;
}

function reglage_texte(string $cle): string
{
    return (string)(q('SELECT valeur FROM reglages WHERE cle = ?', [$cle])->fetchColumn() ?: '');
}

function capital(): int
{
    return reglage('capital', (int)CAPITAL_DEFAUT);
}

function amorce(): int
{
    return max(0, reglage('amorce', (int)AMORCE_DEFAUT));
}

function version_base(PDO $pdo): int
{
    try {
        return (int)$pdo->query("SELECT valeur FROM reglages WHERE cle = 'version'")->fetchColumn();
    } catch (PDOException) {
        return 0; // base vide, ou installée avant la table reglages
    }
}

/** Crée ou met à jour la base ; une seule requête à la fois applique la mise à jour. */
function initialiser_base(PDO $pdo): void
{
    if (version_base($pdo) >= VERSION_BASE) return;
    if (est_sqlite()) {
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            migrer($pdo, version_base($pdo));
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
    } else { // MySQL : les ALTER TABLE ne sont pas transactionnels, on sérialise par un verrou nommé
        $pdo->query("SELECT GET_LOCK('plf_migration', 30)")->fetchColumn();
        try {
            migrer($pdo, version_base($pdo));
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('plf_migration')")->fetchColumn();
        }
    }
}

function migrer(PDO $pdo, int $version): void
{
    if ($version >= VERSION_BASE) return;
    if ($version < 1) {
        foreach (array_filter(array_map('trim', explode(';', est_sqlite() ? SCHEMA_SQLITE : SCHEMA_MYSQL))) as $ordre) {
            $pdo->exec($ordre);
        }
        if (($pdo->query('SELECT COUNT(*) FROM paris')->fetchColumn() == 0) && (CONFIG['exemples'] ?? false)) {
            foreach (PARIS_EXEMPLES as $p) inserer_pari($pdo, ...$p);
        }
    }
    if ($version < 2) { // paris proposés par les joueurs, nouveaux paris
        $pdo->exec('ALTER TABLE paris ADD COLUMN auteur_id INTEGER');
        foreach (PARIS_V2 as $p) inserer_pari($pdo, ...$p);
    }
    if ($version < 3) { // pouvoirs de l'administration
        $pdo->exec(est_sqlite()
            ? 'CREATE TABLE IF NOT EXISTS ajustements (
                   id INTEGER PRIMARY KEY, joueur_id INTEGER NOT NULL REFERENCES joueurs(id),
                   montant INTEGER NOT NULL, motif TEXT NOT NULL, cree_le TEXT NOT NULL)'
            : 'CREATE TABLE IF NOT EXISTS ajustements (
                   id INT AUTO_INCREMENT PRIMARY KEY, joueur_id INT NOT NULL, montant INT NOT NULL,
                   motif VARCHAR(200) NOT NULL, cree_le CHAR(19) NOT NULL,
                   FOREIGN KEY (joueur_id) REFERENCES joueurs(id), INDEX (joueur_id)
               ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $pdo->exec('ALTER TABLE mises ADD COLUMN par_admin INTEGER NOT NULL DEFAULT 0');
    }
    if ($version < 4) { // cotes ajustées par l'administration
        $pdo->exec('ALTER TABLE issues ADD COLUMN poids_banque REAL');
    }
    if ($version < 5 && !(CONFIG['exemples'] ?? false)) { // mise en production : retrait des paris d'illustration sans mise
        $retrait = $pdo->prepare('SELECT id FROM paris WHERE titre = ? AND auteur_id IS NULL
                                  AND NOT EXISTS (SELECT 1 FROM mises WHERE pari_id = paris.id)');
        foreach (PARIS_EXEMPLES as [, $titre]) {
            $retrait->execute([$titre]);
            foreach ($retrait->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $pdo->prepare('DELETE FROM issues WHERE pari_id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM paris WHERE id = ?')->execute([$id]);
            }
        }
    }
    if ($version < 6) { // équité, équipes, calendrier, estimations, historique des cotes, anti-force brute
        foreach ([
            'ALTER TABLE paris ADD COLUMN realise_le CHAR(19)',
            "ALTER TABLE paris ADD COLUMN type VARCHAR(12) NOT NULL DEFAULT 'choix'",
            'ALTER TABLE paris ADD COLUMN unite VARCHAR(30)',
            'ALTER TABLE paris ADD COLUMN valeur_reelle DOUBLE',
            'ALTER TABLE paris ADD COLUMN etape_id INTEGER',
            'ALTER TABLE mises ADD COLUMN estimation DOUBLE',
            'ALTER TABLE mises ADD COLUMN tardive INTEGER NOT NULL DEFAULT 0',
            'ALTER TABLE joueurs ADD COLUMN equipe_id INTEGER',
        ] as $ordre) {
            $pdo->exec($ordre);
        }
        $tables = est_sqlite() ? [
            'CREATE TABLE IF NOT EXISTS equipes (id INTEGER PRIMARY KEY, nom TEXT NOT NULL UNIQUE COLLATE NOCASE)',
            "CREATE TABLE IF NOT EXISTS etapes (id INTEGER PRIMARY KEY, date_etape TEXT NOT NULL, titre TEXT NOT NULL,
                 description TEXT NOT NULL DEFAULT '')",
            'CREATE TABLE IF NOT EXISTS tentatives (id INTEGER PRIMARY KEY, cle TEXT NOT NULL, cree_le TEXT NOT NULL)',
            'CREATE INDEX IF NOT EXISTS idx_tentatives ON tentatives(cle, cree_le)',
            'CREATE TABLE IF NOT EXISTS cotes_historique (id INTEGER PRIMARY KEY, pari_id INTEGER NOT NULL,
                 cree_le TEXT NOT NULL, cotes TEXT NOT NULL)',
            'CREATE INDEX IF NOT EXISTS idx_cotes_historique ON cotes_historique(pari_id)',
        ] : [
            'CREATE TABLE IF NOT EXISTS equipes (id INT AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(60) NOT NULL UNIQUE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS etapes (id INT AUTO_INCREMENT PRIMARY KEY, date_etape CHAR(10) NOT NULL,
                 titre VARCHAR(150) NOT NULL, description TEXT NOT NULL
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS tentatives (id INT AUTO_INCREMENT PRIMARY KEY, cle VARCHAR(150) NOT NULL,
                 cree_le CHAR(19) NOT NULL, INDEX (cle, cree_le)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS cotes_historique (id INT AUTO_INCREMENT PRIMARY KEY, pari_id INT NOT NULL,
                 cree_le CHAR(19) NOT NULL, cotes TEXT NOT NULL, INDEX (pari_id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ];
        foreach ($tables as $ordre) $pdo->exec($ordre);
        // Point de départ des courbes de cotes pour les paris déjà ouverts
        foreach ($pdo->query("SELECT id FROM paris WHERE statut IN ('ouvert', 'suspendu')")->fetchAll(PDO::FETCH_COLUMN) as $id) {
            capturer_cotes((int)$id);
        }
    }
    if ($version < 7) { // paris flash, trophées (mise « tapis »), commentaires
        $pdo->exec('ALTER TABLE paris ADD COLUMN flash INTEGER NOT NULL DEFAULT 0');
        $pdo->exec('ALTER TABLE mises ADD COLUMN tapis INTEGER NOT NULL DEFAULT 0');
        foreach (est_sqlite() ? [
            'CREATE TABLE IF NOT EXISTS commentaires (id INTEGER PRIMARY KEY, pari_id INTEGER NOT NULL,
                 joueur_id INTEGER NOT NULL, texte TEXT NOT NULL, cree_le TEXT NOT NULL)',
            'CREATE INDEX IF NOT EXISTS idx_commentaires_pari ON commentaires(pari_id)',
        ] : [
            'CREATE TABLE IF NOT EXISTS commentaires (id INT AUTO_INCREMENT PRIMARY KEY, pari_id INT NOT NULL,
                 joueur_id INT NOT NULL, texte TEXT NOT NULL, cree_le CHAR(19) NOT NULL, INDEX (pari_id), INDEX (joueur_id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ] as $ordre) {
            $pdo->exec($ordre);
        }
    }
    if ($version < 8) { // dépêches de l'administration (« Réunion à Matignon »)
        $pdo->exec(est_sqlite()
            ? 'CREATE TABLE IF NOT EXISTS depeches (id INTEGER PRIMARY KEY, texte TEXT NOT NULL, cree_le TEXT NOT NULL)'
            : 'CREATE TABLE IF NOT EXISTS depeches (id INT AUTO_INCREMENT PRIMARY KEY, texte VARCHAR(200) NOT NULL,
                   cree_le CHAR(19) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
    if ($version < 9) { // ordre d'affichage des paris, réglable par l'administration
        $pdo->exec('ALTER TABLE paris ADD COLUMN position INTEGER');
        $pdo->exec('UPDATE paris SET position = id');
        // les paris en cours gardent l'ordre dans lequel ils s'affichaient jusqu'ici
        $ids = $pdo->query("SELECT id FROM paris WHERE statut IN ('ouvert', 'suspendu')
                            ORDER BY date_limite IS NULL, date_limite, id")->fetchAll(PDO::FETCH_COLUMN);
        $maj = $pdo->prepare('UPDATE paris SET position = ? WHERE id = ?');
        foreach ($ids as $n => $id) $maj->execute([$n + 1, $id]);
    }
    $pdo->exec("DELETE FROM reglages WHERE cle IN ('version', 'revision')");
    $pdo->prepare("INSERT INTO reglages (cle, valeur) VALUES ('version', ?)")->execute([(string)VERSION_BASE]);
    // Révision des données : incrémentée à chaque écriture, elle évite de renvoyer un état inchangé.
    $pdo->prepare("INSERT INTO reglages (cle, valeur) VALUES ('revision', ?)")->execute([(string)time()]);
}

function inserer_pari(PDO $pdo, string $categorie, string $titre, string $description, array $issues,
                      ?string $dateLimite = null, ?int $auteurId = null): int
{
    $pdo->prepare('INSERT INTO paris (titre, description, categorie, date_limite, cree_le) VALUES (?, ?, ?, ?, ?)')
        ->execute([$titre, $description, $categorie, $dateLimite, maintenant()]);
    $pariId = (int)$pdo->lastInsertId();
    if ($auteurId) $pdo->prepare('UPDATE paris SET auteur_id = ? WHERE id = ?')->execute([$auteurId, $pariId]);
    if (version_base($pdo) >= 9) { // nouveau pari : en fin de liste
        $pdo->prepare('UPDATE paris SET position = (SELECT p FROM (SELECT COALESCE(MAX(position), 0) + 1 AS p FROM paris) t) WHERE id = ?')
            ->execute([$pariId]);
    }
    foreach (array_values($issues) as $n => $libelle) {
        // probabilite : colonne héritée des cotes fixes, inutilisée en pari mutuel
        $pdo->prepare('INSERT INTO issues (pari_id, libelle, probabilite, ordre) VALUES (?, ?, 0, ?)')
            ->execute([$pariId, $libelle, $n]);
    }
    return $pariId;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

/** Transaction en écriture ; les lignes verrouillées par verrou() le restent jusqu'au COMMIT. */
function transaction(callable $f): mixed
{
    $pdo = db();
    est_sqlite() ? $pdo->exec('BEGIN IMMEDIATE') : $pdo->beginTransaction();
    try {
        $resultat = $f();
        q("UPDATE reglages SET valeur = valeur + 1 WHERE cle = 'revision'");
        est_sqlite() ? $pdo->exec('COMMIT') : $pdo->commit();
        return $resultat;
    } catch (Throwable $e) {
        est_sqlite() ? $pdo->exec('ROLLBACK') : $pdo->rollBack();
        throw $e;
    }
}

function verrou(): string
{
    return est_sqlite() ? '' : ' FOR UPDATE'; // SQLite verrouille déjà toute la base (BEGIN IMMEDIATE)
}

function maintenant(): string
{
    return date('Y-m-d\TH:i:s');
}

// ---------------------------------------------------------------------------
// Règles du jeu
// ---------------------------------------------------------------------------

/**
 * Pari mutuel amorcé : la « banque » apporte amorce() deniers publics fictifs par issue à la cagnotte, et
 * détient sur chaque issue une part fictive (amorce × poids, poids 1 par défaut). Les cotes sont ainsi
 * attrayantes dès l'ouverture (×2 sur un Oui/Non) et stables tant qu'il y a peu de mises.
 * Cote d'une issue = (masse misée + apport de la banque) / (masse misée sur l'issue + part de la banque) ;
 * null tant que le dénominateur est nul. L'administration fixe une cote en ajustant le poids de l'issue.
 * À la clôture, les gagnants sont payés à cette cote ; la banque ne touche rien. Elle crée des
 * deniers publics quand les joueurs gagnent, en détruit sinon.
 */
function cote(int $masse, int $masseIssue, int $nbIssues, ?float $poids = null): ?float
{
    $part = $masseIssue + amorce() * ($poids ?? 1);
    return $part > 0 ? ($masse + $nbIssues * amorce()) / $part : null;
}

/** Deniers publics à partager entre les mises sur l'issue réalisée (masse de l'issue × cote). */
function a_verser(int $masse, int $masseIssue, int $nbIssues, ?float $poids = null): int
{
    return $masseIssue > 0 ? (int)floor($masseIssue * cote($masse, $masseIssue, $nbIssues, $poids) + 1e-9) : 0;
}

/**
 * Répartit $masse entre les mises gagnantes, au prorata, en entiers dont la somme vaut exactement
 * $masse (méthode du plus fort reste).
 * @param array<int, int> $mises id de mise → montant
 * @return array<int, int> id de mise → gain
 */
function repartir(int $masse, array $mises): array
{
    $total = array_sum($mises);
    $gains = $restes = [];
    foreach ($mises as $id => $montant) {
        $gains[$id] = intdiv($montant * $masse, $total);
        $restes[$id] = ($montant * $masse) % $total;
    }
    arsort($restes); // à reste égal, la mise la plus ancienne d'abord (tri stable)
    foreach (array_slice(array_keys($restes), 0, $masse - array_sum($gains)) as $id) $gains[$id]++;
    return $gains;
}

/** Cotes actuelles des issues d'un pari {id d'issue: cote}, mises tardives exclues. */
function cotes_actuelles(int $pariId): array
{
    $issues = q('SELECT i.id, i.poids_banque,
                        (SELECT COALESCE(SUM(montant), 0) FROM mises WHERE issue_id = i.id AND tardive = 0) AS masse
                 FROM issues i WHERE i.pari_id = ? ORDER BY i.ordre, i.id', [$pariId])->fetchAll();
    $masse = array_sum(array_column($issues, 'masse'));
    $cotes = [];
    foreach ($issues as $i) {
        $c = cote($masse, (int)$i['masse'], count($issues), $i['poids_banque'] === null ? null : (float)$i['poids_banque']);
        $cotes[(int)$i['id']] = $c === null ? null : round($c, 4);
    }
    return $cotes;
}

/** Enregistre les cotes du moment (courbe d'évolution des cotes d'un pari à choix). */
function capturer_cotes(int $pariId): void
{
    if (q('SELECT type FROM paris WHERE id = ?', [$pariId])->fetchColumn() !== 'choix') return;
    q('INSERT INTO cotes_historique (pari_id, cree_le, cotes) VALUES (?, ?, ?)',
      [$pariId, maintenant(), json_encode(cotes_actuelles($pariId))]);
}

/**
 * Sépare les mises valides des mises tardives, placées à partir du moment où l'issue s'est réalisée
 * (connu à la clôture) : celles-ci sont remboursées. Les mises saisies par l'administration (mises
 * transmises à temps) ne sont jamais tardives.
 * @return array{0: array, 1: array} [valides, tardives]
 */
function separer_tardives(array $mises, ?string $realiseLe): array
{
    if (!$realiseLe) return [$mises, []];
    $valides = $tardives = [];
    foreach ($mises as $m) {
        if (!$m['par_admin'] && $m['cree_le'] >= $realiseLe . ':00') $tardives[] = $m;
        else $valides[] = $m;
    }
    return [$valides, $tardives];
}

function accepte_les_mises(array $pari): bool
{
    return $pari['statut'] === 'ouvert'
        && !($pari['date_limite'] && $pari['date_limite'] <= substr(maintenant(), 0, 16));
}

/** Deniers publics de chaque joueur : disponibles, engagés sur des paris en cours, total. */
function soldes(?int $joueurId = null): array
{
    $filtre = $joueurId ? 'WHERE j.id = ?' : '';
    $lignes = q("
        SELECT j.id, j.pseudo, j.equipe_id,
               COALESCE(SUM(m.montant), 0) AS mise_totale,
               COALESCE(SUM(CASE WHEN p.statut IN ('ouvert', 'suspendu') THEN m.montant END), 0) AS en_jeu,
               COALESCE(SUM(m.gain), 0) AS gains,
               COUNT(CASE WHEN p.statut = 'clos' AND m.tardive = 0 AND (m.issue_id = p.issue_gagnante_id
                          OR (p.type = 'estimation' AND m.gain > 0)) THEN 1 END) AS paris_gagnes,
               (SELECT COALESCE(SUM(a.montant), 0) FROM ajustements a WHERE a.joueur_id = j.id) AS ajustements
        FROM joueurs j
        LEFT JOIN mises m ON m.joueur_id = j.id
        LEFT JOIN paris p ON p.id = m.pari_id
        $filtre
        GROUP BY j.id, j.pseudo, j.equipe_id", $joueurId ? [$joueurId] : [])->fetchAll();
    return array_map(function ($l) {
        $disponible = capital() + (int)$l['ajustements'] - (int)$l['mise_totale'] + (int)$l['gains'];
        return [
            'id' => (int)$l['id'],
            'pseudo' => $l['pseudo'],
            'equipe_id' => $l['equipe_id'] === null ? null : (int)$l['equipe_id'],
            'disponible' => $disponible,
            'en_jeu' => (int)$l['en_jeu'],
            'total' => $disponible + (int)$l['en_jeu'],
            'paris_gagnes' => (int)$l['paris_gagnes'],
        ];
    }, $lignes);
}

function classement(): array
{
    $joueurs = soldes();
    usort($joueurs, fn($a, $b) => [$b['total'], $b['disponible'], strtolower($a['pseudo'])]
                                  <=> [$a['total'], $a['disponible'], strtolower($b['pseudo'])]);
    $rang = 0;
    $totalPrecedent = null;
    foreach ($joueurs as $i => &$j) {
        if ($j['total'] !== $totalPrecedent) {
            $rang = $i + 1;
            $totalPrecedent = $j['total'];
        }
        $j['rang'] = $rang;
    }
    unset($j);
    return $joueurs;
}

/** Classement des équipes à la moyenne de deniers publics par joueur (équitable entre petites et grandes équipes). */
function classement_equipes(array $joueurs, array $equipes): array
{
    $resultat = [];
    foreach ($equipes as $e) {
        $membres = array_filter($joueurs, fn($j) => $j['equipe_id'] === (int)$e['id']);
        if (!$membres) continue;
        $total = array_sum(array_column($membres, 'total'));
        $resultat[] = ['id' => (int)$e['id'], 'nom' => $e['nom'], 'nb_joueurs' => count($membres),
                       'total' => $total, 'moyenne' => intdiv($total, count($membres))];
    }
    usort($resultat, fn($a, $b) => [$b['moyenne'], $b['total'], strtolower($a['nom'])] <=> [$a['moyenne'], $a['total'], strtolower($b['nom'])]);
    $precedente = null;
    foreach ($resultat as $i => &$e) {
        $e['rang'] = $e['moyenne'] === $precedente ? $resultat[$i - 1]['rang'] : $i + 1;
        $precedente = $e['moyenne'];
    }
    unset($e);
    return $resultat;
}

// ---------------------------------------------------------------------------
// Anti-force brute : les échecs sont comptés par clé (pseudo, adresse IP) sur une fenêtre glissante.
// ---------------------------------------------------------------------------

function adresse_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'inconnue');
}

/** Refuse la requête si la clé a atteint sa limite d'essais sur la fenêtre. */
function limiter(string $cle, array $limite): void
{
    [$max, $fenetre] = $limite;
    $depuis = date('Y-m-d\TH:i:s', time() - $fenetre);
    $n = (int)q('SELECT COUNT(*) FROM tentatives WHERE cle = ? AND cree_le >= ?', [$cle, $depuis])->fetchColumn();
    if ($n >= $max) {
        throw new ErreurApi('Trop de tentatives : réessayez dans ' . intdiv($fenetre, 60) . ' minutes.', 429);
    }
}

function noter_tentative(string $cle): void
{
    q('INSERT INTO tentatives (cle, cree_le) VALUES (?, ?)', [$cle, maintenant()]);
    if (random_int(1, 50) === 1) { // ménage occasionnel
        q('DELETE FROM tentatives WHERE cree_le < ?', [date('Y-m-d\TH:i:s', time() - 86400)]);
    }
}

function oublier_tentatives(string $cle): void
{
    q('DELETE FROM tentatives WHERE cle = ?', [$cle]);
}

// ---------------------------------------------------------------------------
// Utilitaires
// ---------------------------------------------------------------------------

function donnees(): array
{
    static $d = null;
    return $d ??= (json_decode(file_get_contents('php://input') ?: '{}', true) ?: []);
}

function joueur_connecte(): ?array
{
    if (!isset($_SESSION['joueur_id'])) return null;
    $j = q('SELECT id, pseudo FROM joueurs WHERE id = ?', [$_SESSION['joueur_id']])->fetch();
    if (!$j) unset($_SESSION['joueur_id']);
    return $j ?: null;
}

function exiger_admin(): void
{
    if (empty($_SESSION['admin'])) throw new ErreurApi("Accès réservé à l'administration.", 403);
}

function lire_pari(int $id, bool $verrouiller = false): array
{
    $pari = q('SELECT * FROM paris WHERE id = ?' . ($verrouiller ? verrou() : ''), [$id])->fetch();
    if (!$pari) throw new ErreurApi('Pari introuvable.', 404);
    return $pari;
}

function lire_pari_actif(int $id): array
{
    $pari = lire_pari($id, true);
    if (!in_array($pari['statut'], STATUTS_ACTIFS, true)) throw new ErreurApi('Ce pari est déjà clôturé.', 409);
    return $pari;
}

function lire_date_limite(mixed $valeur): ?string
{
    if (!$valeur) return null;
    $d = DateTime::createFromFormat('Y-m-d\TH:i', substr((string)$valeur, 0, 16));
    if (!$d) throw new ErreurApi('Date limite invalide.');
    return $d->format('Y-m-d\TH:i');
}

/** Nombre de caractères UTF-8 (sans dépendre de l'extension mbstring). */
function longueur(string $s): int
{
    return (int)preg_match_all('/./us', $s);
}

/** Date et heure saisies (format datetime-local), ou null si vide. */
function lire_date_heure(mixed $valeur, string $message): ?string
{
    if (!$valeur) return null;
    $d = DateTime::createFromFormat('Y-m-d\TH:i', substr((string)$valeur, 0, 16));
    if (!$d) throw new ErreurApi($message);
    return $d->format('Y-m-d\TH:i');
}

/** Nombre décimal saisi (virgule, espaces et « % » acceptés). */
function lire_nombre(mixed $valeur, string $message): float
{
    $s = str_replace([',', ' ', "\u{202F}", "\u{00A0}", '%'], ['.', '', '', '', ''], trim((string)$valeur));
    if ($s === '' || !is_numeric($s) || abs((float)$s) > 1e15) throw new ErreurApi($message);
    return (float)$s;
}

/** Identifiant d'équipe existante, ou null. */
function lire_equipe(mixed $v): ?int
{
    if ($v === null || $v === '' || $v === 0 || $v === '0') return null;
    $id = entier($v, 'Équipe invalide.');
    if (!q('SELECT 1 FROM equipes WHERE id = ?', [$id])->fetch()) throw new ErreurApi('Équipe introuvable.', 404);
    return $id;
}

/**
 * Clé de comparaison d'un nom d'équipe : sans casse, accents, espaces ni ponctuation, pour que
 * « DG75 », « dg 75 » et « DG-75 » désignent la même équipe (sans dépendre de l'extension mbstring).
 */
function cle_equipe(string $nom): string
{
    $s = strtr($nom, ['À' => 'a', 'Â' => 'a', 'Ä' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'Ç' => 'c', 'ç' => 'c',
        'É' => 'e', 'È' => 'e', 'Ê' => 'e', 'Ë' => 'e', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'Î' => 'i', 'Ï' => 'i', 'î' => 'i', 'ï' => 'i', 'Ô' => 'o', 'Ö' => 'o', 'ô' => 'o', 'ö' => 'o',
        'Ù' => 'u', 'Û' => 'u', 'Ü' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'Ÿ' => 'y', 'ÿ' => 'y',
        'Œ' => 'oe', 'œ' => 'oe', 'Æ' => 'ae', 'æ' => 'ae']);
    return strtolower((string)preg_replace('/[^\p{L}\p{N}]+/u', '', $s));
}

/** Équipe existante dont le nom correspond (voir cle_equipe), ou null. */
function trouver_equipe(string $nom, ?int $sauf = null): ?array
{
    $cle = cle_equipe($nom);
    foreach (q('SELECT id, nom FROM equipes')->fetchAll() as $e) {
        if ((int)$e['id'] !== $sauf && cle_equipe($e['nom']) === $cle) return $e;
    }
    return null;
}

/**
 * Équipe choisie par un joueur : « equipe » (nom saisi : rejoint l'équipe existante correspondante,
 * ou la crée) ou « equipe_id ». Vide : sans équipe.
 * @return array{0: ?int, 1: ?string, 2: bool} [id, nom, créée]
 */
function equipe_saisie(array $d): array
{
    if (!array_key_exists('equipe', $d)) {
        $id = lire_equipe($d['equipe_id'] ?? null);
        return [$id, $id ? q('SELECT nom FROM equipes WHERE id = ?', [$id])->fetchColumn() : null, false];
    }
    $nom = (string)preg_replace('/\s+/u', ' ', trim((string)$d['equipe']));
    if ($nom === '') return [null, null, false];
    $nom = lire_nom_equipe($nom);
    if ($e = trouver_equipe($nom)) return [(int)$e['id'], $e['nom'], false];
    q('INSERT INTO equipes (nom) VALUES (?)', [$nom]);
    return [(int)db()->lastInsertId(), $nom, true];
}

/** Identifiant d'étape du calendrier existante, ou null. */
function lire_etape(mixed $v): ?int
{
    if ($v === null || $v === '' || $v === 0 || $v === '0') return null;
    $id = entier($v, 'Étape invalide.');
    if (!q('SELECT 1 FROM etapes WHERE id = ?', [$id])->fetch()) throw new ErreurApi('Étape du calendrier introuvable.', 404);
    return $id;
}

function entier(mixed $v, string $message): int
{
    if (filter_var($v, FILTER_VALIDATE_INT) === false) throw new ErreurApi($message);
    return (int)$v;
}

// ---------------------------------------------------------------------------
// Routes publiques
// ---------------------------------------------------------------------------

/**
 * Trophées obtenus : joueur_id => [code => date d'obtention]. Tout se déduit de l'historique (mises
 * non tardives, paris clôturés), sauf la mise « tapis », notée au moment de la mise.
 */
function trophees(): array
{
    $t = [];
    $obtenir = function (int $joueur, string $code, ?string $date) use (&$t) {
        if ($date && (!isset($t[$joueur][$code]) || $date < $t[$joueur][$code])) $t[$joueur][$code] = $date;
    };
    // Résultat de chaque joueur sur chaque pari clôturé, dans l'ordre des clôtures
    $resultats = q("
        SELECT m.joueur_id, m.pari_id, p.clos_le, p.type, p.flash,
               SUM(m.gain) AS gain,
               MAX(CASE WHEN m.issue_id = p.issue_gagnante_id THEN 1 ELSE 0 END) AS sur_gagnante,
               MAX(CASE WHEN m.gain > 0 AND m.gain >= 5 * m.montant THEN 1 ELSE 0 END) AS cote5,
               MAX(CASE WHEN p.type = 'estimation' AND ABS(m.estimation - p.valeur_reelle) < 1e-9 THEN 1 ELSE 0 END) AS exacte
        FROM mises m JOIN paris p ON p.id = m.pari_id
        WHERE p.statut = 'clos' AND m.tardive = 0
        GROUP BY m.joueur_id, m.pari_id, p.clos_le, p.type, p.flash
        ORDER BY p.clos_le, m.pari_id")->fetchAll();
    $series = []; // joueur => [sens (1 gagné, -1 perdu), longueur]
    foreach ($resultats as $r) {
        $j = (int)$r['joueur_id'];
        $gagne = (int)$r['gain'] > 0 && ($r['type'] === 'estimation' || $r['sur_gagnante']);
        $perdu = (int)$r['gain'] === 0;
        if ($r['cote5']) $obtenir($j, 'nostradamus', $r['clos_le']);
        if ($r['exacte']) $obtenir($j, 'mille', $r['clos_le']);
        if ($gagne && $r['flash']) $obtenir($j, 'eclair', $r['clos_le']);
        $sens = $gagne ? 1 : ($perdu ? -1 : 0); // remboursé : la série s'interrompt
        [$sensPrecedent, $longueur] = $series[$j] ?? [0, 0];
        $series[$j] = [$sens, $sens !== 0 && $sens === $sensPrecedent ? $longueur + 1 : ($sens !== 0 ? 1 : 0)];
        if ($series[$j][1] >= 3) $obtenir($j, $sens > 0 ? 'serie' : 'cassandre', $r['clos_le']);
    }
    foreach (q('SELECT joueur_id, MIN(cree_le) AS quand FROM mises WHERE tapis = 1 GROUP BY joueur_id') as $m) {
        $obtenir((int)$m['joueur_id'], 'tapis', $m['quand']);
    }
    // Rapporteur général : date à laquelle le N-ième joueur a misé sur un pari proposé
    $premieres = [];
    foreach (q('SELECT p.auteur_id, m.pari_id, m.joueur_id, MIN(m.cree_le) AS quand
                FROM mises m JOIN paris p ON p.id = m.pari_id
                WHERE p.auteur_id IS NOT NULL AND m.tardive = 0
                GROUP BY p.auteur_id, m.pari_id, m.joueur_id') as $m) {
        $premieres[$m['pari_id']]['auteur'] = (int)$m['auteur_id'];
        $premieres[$m['pari_id']]['dates'][] = $m['quand'];
    }
    foreach ($premieres as $p) {
        if (count($p['dates']) < RAPPORTEUR_SEUIL) continue;
        sort($p['dates']);
        $obtenir($p['auteur'], 'rapporteur', $p['dates'][RAPPORTEUR_SEUIL - 1]);
    }
    foreach ($t as &$codes) uksort($codes, fn($a, $b) => array_search($a, array_keys(TROPHEES)) <=> array_search($b, array_keys(TROPHEES)));
    unset($codes);
    return $t;
}

function route_etat(): array
{
    $moi = joueur_connecte();
    session_write_close(); // libère la session : les rafraîchissements ne se bloquent pas entre eux
    $joueurs = classement();
    $equipes = q('SELECT id, nom FROM equipes ORDER BY nom')->fetchAll();
    $trophees = trophees();
    foreach ($joueurs as &$j) $j['trophees'] = array_keys($trophees[$j['id']] ?? []);
    unset($j);
    $commentaires = [];
    foreach (q('SELECT c.id, c.pari_id, c.joueur_id, c.texte, c.cree_le, j.pseudo
                FROM commentaires c JOIN joueurs j ON j.id = c.joueur_id ORDER BY c.cree_le, c.id') as $c) {
        $commentaires[$c['pari_id']][] = ['id' => (int)$c['id'], 'joueur_id' => (int)$c['joueur_id'], 'joueur' => $c['pseudo'],
                                          'texte' => $c['texte'], 'date' => $c['cree_le']];
    }

    $issuesParPari = $masseIssue = $massePari = $poids = [];
    foreach (q('
        SELECT i.id, i.pari_id, i.libelle, i.poids_banque,
               (SELECT COALESCE(SUM(montant), 0) FROM mises WHERE issue_id = i.id AND tardive = 0) AS total_mise,
               (SELECT COUNT(DISTINCT joueur_id) FROM mises WHERE issue_id = i.id AND tardive = 0) AS nb_joueurs
        FROM issues i ORDER BY i.pari_id, i.ordre, i.id') as $i) {
        $masseIssue[$i['id']] = (int)$i['total_mise'];
        $poids[$i['id']] = $i['poids_banque'] === null ? null : (float)$i['poids_banque'];
        $massePari[$i['pari_id']] = ($massePari[$i['pari_id']] ?? 0) + (int)$i['total_mise'];
        $issuesParPari[$i['pari_id']][] = [
            'id' => (int)$i['id'],
            'libelle' => $i['libelle'],
            'total_mise' => (int)$i['total_mise'],
            'nb_joueurs' => (int)$i['nb_joueurs'],
            'part_banque' => amorce() * ($poids[$i['id']] ?? 1), // pour estimer les gains côté navigateur
            'cote_ajustee' => $poids[$i['id']] !== null,
        ];
    }

    // Estimations dévoilées une fois le pari clôturé (avant, elles restent secrètes)
    $estimations = [];
    foreach (q("
        SELECT m.pari_id, m.estimation, m.montant, m.gain, m.tardive, j.pseudo
        FROM mises m JOIN paris p ON p.id = m.pari_id JOIN joueurs j ON j.id = m.joueur_id
        WHERE p.type = 'estimation' AND p.statut = 'clos'") as $m) {
        $estimations[$m['pari_id']][] = [
            'joueur' => $m['pseudo'], 'estimation' => (float)$m['estimation'], 'montant' => (int)$m['montant'],
            'gain' => (int)$m['gain'], 'tardive' => (bool)$m['tardive'],
        ];
    }

    $paris = [];
    foreach (q("
        SELECT p.*, j.pseudo AS auteur,
               (SELECT COUNT(DISTINCT joueur_id) FROM mises WHERE pari_id = p.id AND tardive = 0) AS nb_joueurs,
               (SELECT COUNT(*) FROM mises WHERE pari_id = p.id AND tardive = 1) AS nb_tardives
        FROM paris p LEFT JOIN joueurs j ON j.id = p.auteur_id
        ORDER BY CASE WHEN p.statut IN ('ouvert', 'suspendu') THEN 0 ELSE 1 END,
                 CASE WHEN p.statut IN ('ouvert', 'suspendu') THEN p.position END,
                 CASE WHEN p.statut IN ('clos', 'annule') THEN p.clos_le END DESC, p.id") as $p) {
        $issues = $issuesParPari[$p['id']] ?? [];
        $estimation = $p['type'] === 'estimation';
        foreach ($issues as &$i) {
            $i['cote'] = $estimation ? null : cote($massePari[$p['id']], $i['total_mise'], count($issues), $poids[$i['id']]);
        }
        unset($i);
        $valeur = $p['valeur_reelle'] === null ? null : (float)$p['valeur_reelle'];
        $liste = $estimations[$p['id']] ?? [];
        if ($valeur !== null) {
            usort($liste, fn($a, $b) => [$a['tardive'], abs($a['estimation'] - $valeur), -$a['montant']]
                                        <=> [$b['tardive'], abs($b['estimation'] - $valeur), -$b['montant']]);
        }
        $paris[] = [
            'id' => (int)$p['id'],
            'type' => $p['type'],
            'titre' => $p['titre'],
            'description' => $p['description'],
            'categorie' => $p['categorie'],
            'unite' => (string)$p['unite'],
            'etape_id' => $p['etape_id'] === null ? null : (int)$p['etape_id'],
            'auteur' => $p['auteur'],
            'flash' => (bool)$p['flash'],
            'statut' => $p['statut'],
            'accepte_mises' => accepte_les_mises($p),
            'date_limite' => $p['date_limite'],
            'issue_gagnante_id' => $p['issue_gagnante_id'] === null ? null : (int)$p['issue_gagnante_id'],
            'valeur_reelle' => $valeur,
            'realise_le' => $p['realise_le'],
            'clos_le' => $p['clos_le'],
            'total_mise' => $massePari[$p['id']] ?? 0,
            'nb_joueurs' => (int)$p['nb_joueurs'],
            'nb_tardives' => (int)$p['nb_tardives'],
            'issues' => $issues,
            'estimations' => $estimation && $p['statut'] === 'clos' ? $liste : null,
            'commentaires' => array_slice($commentaires[$p['id']] ?? [], -50),
        ];
    }
    $typesParPari = array_column($paris, 'type', 'id');
    $tendances = tendances($paris);

    $mesMises = [];
    if ($moi) {
        foreach (q('
            SELECT m.id, m.pari_id, m.issue_id, m.montant, m.gain, m.cree_le, m.estimation, m.tardive,
                   p.titre AS pari_titre, p.statut AS statut_pari, i.libelle AS issue_libelle
            FROM mises m JOIN paris p ON p.id = m.pari_id JOIN issues i ON i.id = m.issue_id
            WHERE m.joueur_id = ? ORDER BY m.cree_le DESC, m.id DESC', [$moi['id']]) as $m) {
            $montant = (int)$m['montant'];
            $enCours = $m['gain'] === null;
            $choix = $typesParPari[$m['pari_id']] === 'choix';
            [$masse, $masseI, $n, $w] = [$massePari[$m['pari_id']], $masseIssue[$m['issue_id']],
                                         count($issuesParPari[$m['pari_id']]), $poids[$m['issue_id']]];
            $mesMises[] = [
                'id' => (int)$m['id'], 'pari_id' => (int)$m['pari_id'], 'issue_id' => (int)$m['issue_id'],
                'type_pari' => $typesParPari[$m['pari_id']],
                'montant' => $montant,
                'estimation' => $m['estimation'] === null ? null : (float)$m['estimation'],
                // en cours : cote actuelle de l'issue ; terminé : cote effectivement obtenue
                'cote' => $enCours ? ($choix ? cote($masse, $masseI, $n, $w) : null) : (int)$m['gain'] / $montant,
                'gain_estime' => $enCours && $choix && $masseI > 0 ? intdiv($montant * a_verser($masse, $masseI, $n, $w), $masseI) : null,
                'gain' => $enCours ? null : (int)$m['gain'], 'tardive' => (bool)$m['tardive'], 'cree_le' => $m['cree_le'],
                'pari_titre' => $m['pari_titre'], 'statut_pari' => $m['statut_pari'],
                'issue_libelle' => $m['issue_libelle'],
            ];
        }
    }

    $monClassement = null;
    foreach ($joueurs as $j) {
        if ($moi && $j['id'] === (int)$moi['id']) $monClassement = $j;
    }
    return [
        'capital' => capital(),
        'amorce' => amorce(),
        'maintenant' => maintenant(),
        'admin' => !empty($_SESSION['admin']),
        'invitation' => reglage_texte('code_invitation') !== '',
        'moi' => $monClassement,
        'classement' => $joueurs,
        'equipes' => array_map(fn($e) => ['id' => (int)$e['id'], 'nom' => $e['nom']], $equipes),
        'classement_equipes' => classement_equipes($joueurs, $equipes),
        'etapes' => array_map(fn($e) => ['id' => (int)$e['id'], 'date' => $e['date_etape'], 'titre' => $e['titre'],
                                        'description' => $e['description']],
                              q('SELECT * FROM etapes ORDER BY date_etape, id')->fetchAll()),
        'paris' => $paris,
        'tendances' => $tendances,
        'pari_du_jour' => pari_du_jour($paris, $tendances),
        'direct' => direct($paris, $joueurs),
        'depeches' => array_map(fn($d) => ['id' => (int)$d['id'], 'texte' => $d['texte'], 'date' => $d['cree_le']],
                                q('SELECT * FROM depeches ORDER BY cree_le DESC, id DESC LIMIT 20')->fetchAll()),
        'mes_mises' => $mesMises,
        'trophees' => array_map(fn($t) => ['icone' => $t[0], 'nom' => $t[1], 'condition' => $t[2]], TROPHEES),
        'mes_trophees' => $moi ? ($trophees[(int)$moi['id']] ?? []) : [],
        'fil' => fil_actualite(30, $trophees, $tendances, array_column($paris, 'titre', 'id')),
        'donnees_admin' => empty($_SESSION['admin']) ? null : donnees_admin(),
    ];
}

/** Probabilités implicites des cotes {issue: probabilité}, ramenées à 100 % ; null si aucune cote. */
function probabilites(array $cotes): ?array
{
    $inverses = array_map(fn($c) => $c ? 1 / $c : 0.0, $cotes);
    $somme = array_sum($inverses);
    return $somme > 0 ? array_map(fn($v) => $v / $somme, $inverses) : null;
}

/**
 * Tendances des paris à choix en cours : probabilité implicite de chaque issue, variation en points
 * depuis 24 h (ou depuis l'ouverture si le pari est plus récent) et depuis la dernière dépêche de
 * l'administration (7 derniers jours), courbe de chaque issue sur 30 jours, volume misé sur 24 h.
 */
function tendances(array $paris): array
{
    $il_y_a_24h = date('Y-m-d\TH:i:s', time() - 86400);
    $il_y_a_30j = date('Y-m-d\TH:i:s', time() - 30 * 86400);
    $depeche = q('SELECT id, texte, cree_le FROM depeches WHERE cree_le >= ? ORDER BY cree_le DESC, id DESC LIMIT 1',
                 [date('Y-m-d\TH:i:s', time() - 7 * 86400)])->fetch() ?: null;
    // Probabilités du dernier relevé de cotes au plus tard à une date (null si le pari est plus récent)
    $releve = function (int $pariId, string $date): ?array {
        $cotes = q('SELECT cotes FROM cotes_historique WHERE pari_id = ? AND cree_le <= ? ORDER BY cree_le DESC, id DESC LIMIT 1',
                   [$pariId, $date])->fetchColumn();
        return $cotes === false ? null : probabilites(json_decode($cotes, true) ?: []);
    };
    $ecart = fn(?array $avant, array $maintenant, int $id) => $avant && isset($avant[$id]) ? (int)round(($maintenant[$id] - $avant[$id]) * 100) : null;
    $resultat = [];
    foreach ($paris as $p) {
        if ($p['type'] !== 'choix' || !in_array($p['statut'], STATUTS_ACTIFS, true)) continue;
        $maintenant = probabilites(array_column($p['issues'], 'cote', 'id'));
        if (!$maintenant) continue;
        $depuis = '24h';
        $avant = $releve($p['id'], $il_y_a_24h);
        if (!$avant) { // pari ouvert depuis moins de 24 h : comparaison avec l'ouverture
            $premier = q('SELECT cotes FROM cotes_historique WHERE pari_id = ? ORDER BY id LIMIT 1', [$p['id']])->fetchColumn();
            $avant = $premier === false ? null : probabilites(json_decode($premier, true) ?: []);
            $depuis = 'ouverture';
        }
        $avantDepeche = $depeche ? $releve($p['id'], $depeche['cree_le']) : null;
        arsort($maintenant);
        $ordre = array_keys($maintenant);
        // Courbe de chaque issue (dans l'ordre des probabilités actuelles) sur 30 jours, 40 points au plus
        $points = [];
        foreach (q('SELECT cree_le, cotes FROM cotes_historique WHERE pari_id = ? AND cree_le >= ? ORDER BY cree_le, id',
                   [$p['id'], $il_y_a_30j]) as $h) {
            $probas = probabilites(json_decode($h['cotes'], true) ?: []);
            if ($probas) $points[] = [$h['cree_le'], array_map(fn($id) => round($probas[$id] ?? 0, 4), $ordre)];
        }
        if (count($points) > 40) {
            $pas = (count($points) - 1) / 39;
            $points = array_map(fn($k) => $points[(int)round($k * $pas)], range(0, 39));
        }
        $points[] = [maintenant(), array_map(fn($id) => round($maintenant[$id], 4), $ordre)];
        $resultat[] = [
            'pari_id' => $p['id'],
            'issues' => array_map(fn($id) => [
                'id' => $id,
                'probabilite' => round($maintenant[$id], 4),
                'variation' => $ecart($avant, $maintenant, $id),
                'variation_depeche' => $ecart($avantDepeche, $maintenant, $id),
            ], $ordre),
            'depuis' => $depuis,
            'depeche' => $avantDepeche ? ['texte' => $depeche['texte'], 'date' => $depeche['cree_le']] : null,
            'courbe' => $points,
            'derniere_mise' => q('SELECT MAX(cree_le) FROM mises WHERE pari_id = ? AND tardive = 0', [$p['id']])->fetchColumn() ?: null,
            'volume_24h' => (int)q('SELECT COALESCE(SUM(montant), 0) FROM mises WHERE pari_id = ? AND tardive = 0 AND cree_le >= ?',
                                   [$p['id'], $il_y_a_24h])->fetchColumn(),
        ];
    }
    return $resultat;
}

/**
 * Pari du jour : celui choisi par l'administration (jusqu'à ce qu'elle en change), tant qu'il accepte les
 * mises ; sinon le plus animé des dernières 24 heures (puis la plus grosse cagnotte).
 */
function pari_du_jour(array $paris, array $tendances): ?array
{
    $ouverts = array_values(array_filter($paris, fn($p) => $p['accepte_mises']));
    if (!$ouverts) return null;
    $choix = json_decode(reglage_texte('pari_du_jour') ?: 'null', true);
    if ($choix) {
        foreach ($ouverts as $p) if ($p['id'] === (int)$choix['id']) return ['id' => $p['id'], 'choisi' => true];
    }
    $volumes = array_column($tendances, 'volume_24h', 'pari_id');
    usort($ouverts, fn($a, $b) => [$volumes[$b['id']] ?? 0, $b['total_mise']] <=> [$volumes[$a['id']] ?? 0, $a['total_mise']]);
    return ['id' => $ouverts[0]['id'], 'choisi' => false];
}

/** Chiffres en direct de la page d'accueil. */
function direct(array $paris, array $joueurs): array
{
    $jour = q("SELECT COALESCE(SUM(montant), 0) AS somme, COUNT(*) AS nb, COUNT(DISTINCT joueur_id) AS actifs
               FROM mises WHERE tardive = 0 AND cree_le >= ?", [date('Y-m-d') . 'T00:00:00'])->fetch();
    return [
        'mises_jour' => (int)$jour['somme'],
        'nb_mises_jour' => (int)$jour['nb'],
        'joueurs_actifs_jour' => (int)$jour['actifs'],
        'marches_ouverts' => count(array_filter($paris, fn($p) => $p['accepte_mises'])),
        'joueurs' => count($joueurs),
        'en_jeu' => array_sum(array_map(fn($p) => in_array($p['statut'], STATUTS_ACTIFS, true) ? $p['total_mise'] : 0, $paris)),
    ];
}

/** Clé de l'état : change à chaque écriture, à la connexion ou à la déconnexion, et chaque minute (dates limites). */
function cle_etat(): string
{
    $revision = (string)q("SELECT valeur FROM reglages WHERE cle = 'revision'")->fetchColumn();
    return substr(md5(implode('|', [$revision, $_SESSION['joueur_id'] ?? '', empty($_SESSION['admin']) ? 0 : 1,
                                    date('Y-m-d H:i')])), 0, 12);
}

/** Courbes des deniers publics (total) de chaque joueur : le total ne change qu'aux clôtures et ajustements. */
function route_historique_joueurs(): array
{
    session_write_close();
    $variations = [];
    foreach (q('SELECT joueur_id, cree_le, montant FROM ajustements') as $a) {
        $variations[$a['joueur_id']][] = [$a['cree_le'], (int)$a['montant']];
    }
    foreach (q("SELECT m.joueur_id, p.clos_le, SUM(COALESCE(m.gain, 0) - m.montant) AS net
                FROM mises m JOIN paris p ON p.id = m.pari_id
                WHERE p.statut = 'clos' GROUP BY m.joueur_id, p.id, p.clos_le") as $c) {
        if ((int)$c['net'] !== 0) $variations[$c['joueur_id']][] = [$c['clos_le'], (int)$c['net']];
    }
    $totaux = array_column(soldes(), 'total', 'id');
    $joueurs = [];
    foreach (q('SELECT id, pseudo, cree_le FROM joueurs') as $j) {
        $total = capital();
        $points = [[$j['cree_le'], $total]];
        $liste = $variations[$j['id']] ?? [];
        usort($liste, fn($a, $b) => strcmp($a[0], $b[0]));
        foreach ($liste as [$date, $delta]) {
            $total += $delta;
            $points[] = [max($date, $j['cree_le']), $total];
        }
        $points[] = [maintenant(), $totaux[$j['id']] ?? $total];
        $joueurs[] = ['id' => (int)$j['id'], 'pseudo' => $j['pseudo'], 'points' => $points];
    }
    return ['joueurs' => $joueurs];
}

/**
 * Fiche d'un joueur : rang, variation de son total sur 24 h et 7 jours, % de paris gagnés, série en cours,
 * meilleur pari, plus grosse perte, trophées, dernières mises (sans dévoiler les estimations en cours).
 */
function route_fiche(): array
{
    session_write_close();
    $id = entier($_GET['joueur'] ?? null, 'Joueur invalide.');
    $j = q('SELECT id, pseudo, equipe_id, cree_le FROM joueurs WHERE id = ?', [$id])->fetch();
    if (!$j) throw new ErreurApi('Joueur introuvable.', 404);
    $classement = classement();
    $ligne = current(array_filter($classement, fn($x) => $x['id'] === $id));
    // Le total ne change qu'aux clôtures et aux ajustements : variation = somme des changements récents
    $changements = [];
    foreach (q('SELECT cree_le, montant FROM ajustements WHERE joueur_id = ?', [$id]) as $a) $changements[] = [$a['cree_le'], (int)$a['montant']];
    $resultats = q("SELECT m.pari_id, p.titre, p.clos_le, p.type, SUM(m.montant) AS mise, SUM(m.gain) AS gain,
                           MAX(CASE WHEN m.issue_id = p.issue_gagnante_id THEN 1 ELSE 0 END) AS sur_gagnante
                    FROM mises m JOIN paris p ON p.id = m.pari_id
                    WHERE m.joueur_id = ? AND p.statut = 'clos' AND m.tardive = 0
                    GROUP BY m.pari_id, p.titre, p.clos_le, p.type ORDER BY p.clos_le, m.pari_id", [$id])->fetchAll();
    $gagnes = $perdus = 0;
    $serie = [0, 0]; // [sens, longueur]
    $meilleur = $pire = null;
    foreach ($resultats as $r) {
        $net = (int)$r['gain'] - (int)$r['mise'];
        $changements[] = [$r['clos_le'], $net];
        $gagne = (int)$r['gain'] > 0 && ($r['type'] === 'estimation' || $r['sur_gagnante']);
        $perdu = (int)$r['gain'] === 0;
        $gagnes += (int)$gagne;
        $perdus += (int)$perdu;
        $sens = $gagne ? 1 : ($perdu ? -1 : 0);
        $serie = $sens !== 0 && $sens === $serie[0] ? [$sens, $serie[1] + 1] : [$sens, $sens !== 0 ? 1 : 0];
        $resume = ['pari_id' => (int)$r['pari_id'], 'titre' => $r['titre'], 'net' => $net, 'mise' => (int)$r['mise'],
                   'date' => $r['clos_le']];
        if ($net > 0 && (!$meilleur || $net > $meilleur['net'])) $meilleur = $resume;
        if ($net < 0 && (!$pire || $net < $pire['net'])) $pire = $resume;
    }
    $variation = fn(int $secondes) => array_sum(array_map(fn($c) => $c[0] >= date('Y-m-d\TH:i:s', time() - $secondes) ? $c[1] : 0, $changements));
    $mises = array_map(fn($m) => [
        'pari_id' => (int)$m['pari_id'], 'titre' => $m['titre'], 'montant' => (int)$m['montant'], 'date' => $m['cree_le'],
        'issue' => $m['type'] === 'estimation' ? null : $m['libelle'],
        'resultat' => $m['statut'] === 'annule' || $m['tardive'] ? 'rembourse' : ($m['gain'] === null ? 'en_cours' : ((int)$m['gain'] > 0 ? 'gagne' : 'perdu')),
        'gain' => $m['gain'] === null ? null : (int)$m['gain'],
    ], q('SELECT m.pari_id, m.montant, m.cree_le, m.gain, m.tardive, p.titre, p.type, p.statut, i.libelle
          FROM mises m JOIN paris p ON p.id = m.pari_id JOIN issues i ON i.id = m.issue_id
          WHERE m.joueur_id = ? ORDER BY m.cree_le DESC, m.id DESC LIMIT 6', [$id])->fetchAll());
    $trophees = trophees()[$id] ?? [];
    return [
        'id' => $id, 'pseudo' => $j['pseudo'], 'inscrit_le' => $j['cree_le'],
        'equipe' => $j['equipe_id'] ? q('SELECT nom FROM equipes WHERE id = ?', [$j['equipe_id']])->fetchColumn() : null,
        'rang' => $ligne['rang'], 'nb_joueurs' => count($classement), 'total' => $ligne['total'],
        'disponible' => $ligne['disponible'], 'en_jeu' => $ligne['en_jeu'],
        'variation_24h' => $variation(86400), 'variation_7j' => $variation(7 * 86400),
        'paris_joues' => $gagnes + $perdus, 'paris_gagnes' => $gagnes,
        'taux_reussite' => $gagnes + $perdus ? round($gagnes / ($gagnes + $perdus), 3) : null,
        'serie' => ['sens' => $serie[0] > 0 ? 'gagnee' : ($serie[0] < 0 ? 'perdue' : null), 'longueur' => $serie[1]],
        'meilleur_pari' => $meilleur, 'plus_grosse_perte' => $pire,
        'trophees' => array_map(fn($code, $date) => ['icone' => TROPHEES[$code][0], 'nom' => TROPHEES[$code][1], 'date' => $date],
                                array_keys($trophees), $trophees),
        'dernieres_mises' => $mises,
    ];
}

/** Dépêche de l'administration (« Réunion à Matignon ») : fil, bandeau et repère des variations. */
function route_admin_depeche(): array
{
    $texte = trim((string)preg_replace('/\s+/u', ' ', (string)(donnees()['texte'] ?? '')));
    if (longueur($texte) < 3 || longueur($texte) > 140) throw new ErreurApi('La dépêche doit faire entre 3 et 140 caractères.');
    return transaction(function () use ($texte) {
        q('INSERT INTO depeches (texte, cree_le) VALUES (?, ?)', [$texte, maintenant()]);
        return ['ok' => true];
    });
}

function route_admin_depeche_suppression(int $id): array
{
    return transaction(function () use ($id) {
        if (!q('SELECT 1 FROM depeches WHERE id = ?', [$id])->fetch()) throw new ErreurApi('Dépêche introuvable.', 404);
        q('DELETE FROM depeches WHERE id = ?', [$id]);
        return ['ok' => true];
    });
}

/** Choisit le pari du jour (jusqu'à nouvel ordre) ; vide : choix automatique. */
function route_admin_pari_du_jour(): array
{
    $id = donnees()['pari_id'] ?? '';
    return transaction(function () use ($id) {
        q("DELETE FROM reglages WHERE cle = 'pari_du_jour'");
        if ($id !== '' && $id !== null) {
            $pari = lire_pari(entier($id, 'Pari invalide.'));
            if (!accepte_les_mises($pari)) throw new ErreurApi("Ce pari n'accepte pas de mises : il ne peut pas être le pari du jour.", 409);
            q("INSERT INTO reglages (cle, valeur) VALUES ('pari_du_jour', ?)", [json_encode(['id' => (int)$pari['id'], 'depuis' => maintenant()])]);
        }
        return ['ok' => true];
    });
}

/**
 * Change la place d'un pari en cours dans l'ordre d'affichage (onglet « Paris en cours », tuiles de
 * l'accueil) : sens = haut (tout en haut), monter, descendre, bas (tout en bas).
 */
function route_admin_deplacer(int $pariId): array
{
    $sens = donnees()['sens'] ?? '';
    if (!in_array($sens, ['haut', 'monter', 'descendre', 'bas'], true)) throw new ErreurApi('Sens invalide.');
    return transaction(function () use ($pariId, $sens) {
        lire_pari_actif($pariId);
        $ids = array_map('intval', q("SELECT id FROM paris WHERE statut IN ('ouvert', 'suspendu') ORDER BY position, id")
            ->fetchAll(PDO::FETCH_COLUMN));
        $k = array_search($pariId, $ids, true);
        array_splice($ids, $k, 1);
        $cible = match ($sens) {
            'haut' => 0, 'bas' => count($ids), 'monter' => max(0, $k - 1), 'descendre' => min(count($ids), $k + 1),
        };
        array_splice($ids, $cible, 0, [$pariId]);
        foreach ($ids as $n => $id) q('UPDATE paris SET position = ? WHERE id = ?', [$n + 1, $id]);
        return ['ok' => true, 'position' => $cible + 1];
    });
}

/** Évolution des cotes d'un pari à choix. */
function route_historique_cotes(): array
{
    session_write_close();
    $pari = lire_pari(entier($_GET['pari'] ?? null, 'Pari invalide.'));
    $points = array_map(fn($h) => ['date' => $h['cree_le'], 'cotes' => json_decode($h['cotes'], true)],
                        q('SELECT cree_le, cotes FROM cotes_historique WHERE pari_id = ? ORDER BY id', [$pari['id']])->fetchAll());
    if (in_array($pari['statut'], STATUTS_ACTIFS, true) && $pari['type'] === 'choix') {
        $points[] = ['date' => maintenant(), 'cotes' => cotes_actuelles((int)$pari['id'])];
    }
    return [
        'issues' => array_map(fn($i) => ['id' => (int)$i['id'], 'libelle' => $i['libelle']],
                              q('SELECT id, libelle FROM issues WHERE pari_id = ? ORDER BY ordre, id', [$pari['id']])->fetchAll()),
        'points' => $points,
    ];
}

/**
 * @param array $tendances    voir tendances() : sert à annoncer les mouvements marquants
 * @param array $titres       id de pari => intitulé
 */
function fil_actualite(int $limite = 30, array $trophees = [], array $tendances = [], array $titres = []): array
{
    $evenements = [];
    foreach (q("
        SELECT m.id, m.cree_le, m.montant, m.par_admin, j.pseudo, i.libelle, p.titre, p.type
        FROM mises m JOIN joueurs j ON j.id = m.joueur_id
        JOIN issues i ON i.id = m.issue_id JOIN paris p ON p.id = m.pari_id
        ORDER BY m.cree_le DESC, m.id DESC LIMIT $limite") as $m) {
        // l'estimation elle-même reste secrète jusqu'à la clôture
        $evenements[] = ['date' => $m['cree_le'], 'type' => 'mise', 'joueur' => $m['pseudo'],
                         'montant' => (int)$m['montant'], 'issue' => $m['type'] === 'estimation' ? null : $m['libelle'],
                         'pari' => $m['titre'], 'par_admin' => (bool)$m['par_admin']];
    }
    foreach (q("
        SELECT p.clos_le, p.titre, p.statut, p.type, p.unite, p.valeur_reelle, i.libelle,
               (SELECT COUNT(*) FROM mises WHERE pari_id = p.id AND tardive = 0 AND gain > 0
                   AND (issue_id = p.issue_gagnante_id OR p.type = 'estimation')) AS nb_gagnants,
               (SELECT COALESCE(SUM(gain), 0) FROM mises WHERE pari_id = p.id AND tardive = 0) AS distribue,
               (SELECT COUNT(*) FROM mises WHERE pari_id = p.id AND tardive = 1) AS nb_tardives
        FROM paris p LEFT JOIN issues i ON i.id = p.issue_gagnante_id
        WHERE p.statut IN ('clos', 'annule')
        ORDER BY p.clos_le DESC LIMIT $limite") as $p) {
        $evenements[] = ['date' => $p['clos_le'], 'type' => $p['statut'], 'pari' => $p['titre'],
                         'issue' => $p['libelle'], 'nb_gagnants' => (int)$p['nb_gagnants'],
                         'distribue' => (int)$p['distribue'], 'nb_tardives' => (int)$p['nb_tardives'],
                         'valeur' => $p['valeur_reelle'] === null ? null : (float)$p['valeur_reelle'],
                         'unite' => (string)$p['unite']];
    }
    foreach (q("
        SELECT p.cree_le, p.titre, p.flash, p.date_limite, j.pseudo FROM paris p LEFT JOIN joueurs j ON j.id = p.auteur_id
        ORDER BY p.cree_le DESC, p.id DESC LIMIT $limite") as $p) {
        $evenements[] = ['date' => $p['cree_le'], 'type' => $p['flash'] ? 'flash' : 'pari', 'pari' => $p['titre'],
                         'joueur' => $p['pseudo'], 'date_limite' => $p['date_limite']];
    }
    foreach (q("
        SELECT c.cree_le, c.texte, j.pseudo, p.titre FROM commentaires c
        JOIN joueurs j ON j.id = c.joueur_id JOIN paris p ON p.id = c.pari_id
        ORDER BY c.cree_le DESC, c.id DESC LIMIT $limite") as $c) {
        $evenements[] = ['date' => $c['cree_le'], 'type' => 'commentaire', 'joueur' => $c['pseudo'], 'pari' => $c['titre'],
                         'texte' => $c['texte']];
    }
    foreach (q("SELECT texte, cree_le FROM depeches ORDER BY cree_le DESC, id DESC LIMIT $limite") as $d) {
        $evenements[] = ['date' => $d['cree_le'], 'type' => 'depeche', 'texte' => $d['texte']];
    }
    // Mouvements marquants : ±10 pts depuis la dernière dépêche, ou ±15 pts sur 24 h, datés de la dernière mise
    foreach ($tendances as $t) {
        if (!$t['derniere_mise']) continue;
        $issue = $t['issues'][0];
        foreach ($t['issues'] as $i) {
            $v = $t['depeche'] ? $i['variation_depeche'] : $i['variation'];
            if ($v !== null && $v > 0 && $v > ($t['depeche'] ? $issue['variation_depeche'] : $issue['variation'])) $issue = $i;
        }
        $variation = $t['depeche'] ? $issue['variation_depeche'] : $issue['variation'];
        if ($variation === null || abs($variation) < ($t['depeche'] ? 10 : 15)) continue;
        if ($t['depeche'] && $t['derniere_mise'] < $t['depeche']['date']) continue;
        $libelle = q('SELECT libelle FROM issues WHERE id = ?', [$issue['id']])->fetchColumn();
        $evenements[] = ['date' => $t['derniere_mise'], 'type' => 'mouvement', 'pari' => $titres[$t['pari_id']] ?? '',
                         'pari_id' => $t['pari_id'], 'issue' => $libelle, 'variation' => $variation,
                         'probabilite' => $issue['probabilite'],
                         'reference' => $t['depeche'] ? $t['depeche']['texte'] : ($t['depuis'] === '24h' ? null : 'ouverture')];
    }
    $pseudos = array_column(q('SELECT id, pseudo FROM joueurs')->fetchAll(), 'pseudo', 'id');
    foreach ($trophees as $joueurId => $codes) {
        foreach ($codes as $code => $date) {
            $evenements[] = ['date' => $date, 'type' => 'trophee', 'joueur' => $pseudos[$joueurId] ?? '?',
                             'icone' => TROPHEES[$code][0], 'trophee' => TROPHEES[$code][1]];
        }
    }
    foreach (q("
        SELECT a.cree_le, a.montant, a.motif, j.pseudo FROM ajustements a JOIN joueurs j ON j.id = a.joueur_id
        ORDER BY a.cree_le DESC, a.id DESC LIMIT $limite") as $a) {
        $evenements[] = ['date' => $a['cree_le'], 'type' => 'ajustement', 'joueur' => $a['pseudo'],
                         'montant' => (int)$a['montant'], 'motif' => $a['motif']];
    }
    foreach (q("SELECT cree_le, pseudo FROM joueurs ORDER BY cree_le DESC, id DESC LIMIT $limite") as $j) {
        $evenements[] = ['date' => $j['cree_le'], 'type' => 'joueur', 'joueur' => $j['pseudo']];
    }
    usort($evenements, fn($a, $b) => strcmp((string)$b['date'], (string)$a['date']));
    return array_slice($evenements, 0, $limite);
}

function route_inscription(): array
{
    $ip = adresse_ip();
    limiter("inscription:ip:$ip", LIMITE_INSCRIPTION_IP);
    $code = reglage_texte('code_invitation');
    if ($code !== '') {
        limiter("invitation:ip:$ip", LIMITE_INVITATION_IP);
        if (!hash_equals(strtolower($code), strtolower(trim((string)(donnees()['invitation'] ?? ''))))) {
            noter_tentative("invitation:ip:$ip");
            throw new ErreurApi("Code d'invitation incorrect : demandez-le à l'organisateur du jeu.", 403);
        }
    }
    $pseudo = lire_pseudo(donnees()['pseudo'] ?? '');
    $pin = lire_code(donnees()['pin'] ?? '');
    [$id, $equipe] = transaction(function () use ($pseudo, $pin) {
        if (q('SELECT 1 FROM joueurs WHERE pseudo = ?', [$pseudo])->fetch()) {
            throw new ErreurApi('Ce pseudo est déjà pris.', 409);
        }
        $equipe = equipe_saisie(donnees());
        q('INSERT INTO joueurs (pseudo, pin_hash, cree_le, equipe_id) VALUES (?, ?, ?, ?)',
          [$pseudo, password_hash($pin, PASSWORD_DEFAULT), maintenant(), $equipe[0]]);
        return [(int)db()->lastInsertId(), $equipe];
    });
    noter_tentative("inscription:ip:$ip");
    session_regenerate_id(true);
    $_SESSION['joueur_id'] = $id;
    return ['ok' => true, 'equipe' => $equipe[1], 'equipe_creee' => $equipe[2]];
}

function route_connexion(): array
{
    $pseudo = trim((string)(donnees()['pseudo'] ?? ''));
    $clePseudo = 'connexion:pseudo:' . strtolower($pseudo);
    $cleIp = 'connexion:ip:' . adresse_ip();
    limiter($clePseudo, LIMITE_CONNEXION_PSEUDO);
    limiter($cleIp, LIMITE_CONNEXION_IP);
    $j = q('SELECT id, pin_hash FROM joueurs WHERE pseudo = ?', [$pseudo])->fetch();
    if (!$j || !password_verify((string)(donnees()['pin'] ?? ''), $j['pin_hash'])) {
        noter_tentative($clePseudo);
        noter_tentative($cleIp);
        throw new ErreurApi('Pseudo ou code incorrect.', 401);
    }
    oublier_tentatives($clePseudo);
    session_regenerate_id(true);
    $_SESSION['joueur_id'] = (int)$j['id'];
    return ['ok' => true];
}

/** Mon profil : le joueur rejoint, crée ou quitte une équipe. */
function route_profil(): array
{
    $moi = joueur_connecte();
    if (!$moi) throw new ErreurApi('Connectez-vous.', 401);
    return transaction(function () use ($moi) {
        [$id, $nom, $creee] = equipe_saisie(donnees());
        q('UPDATE joueurs SET equipe_id = ? WHERE id = ?', [$id, $moi['id']]);
        return ['ok' => true, 'equipe' => $nom, 'equipe_creee' => $creee];
    });
}

function route_deconnexion(): array
{
    $_SESSION = [];
    return ['ok' => true];
}

/** Pari proposé par un joueur (ou créé par l'administration, sans limite). */
/** Intitulé, précisions et catégorie d'un pari, vérifiés. */
function lire_textes_pari(array $d): array
{
    $titre = trim((string)($d['titre'] ?? ''));
    $description = trim((string)($d['description'] ?? ''));
    $categorie = trim((string)($d['categorie'] ?? ''));
    if (longueur($titre) < 5 || longueur($titre) > 200) throw new ErreurApi("L'intitulé doit faire entre 5 et 200 caractères.");
    if (longueur($description) > 500) throw new ErreurApi('Les précisions sont limitées à 500 caractères.');
    if (longueur($categorie) > 40) throw new ErreurApi('La catégorie est limitée à 40 caractères.');
    return [$titre, $description, $categorie];
}

/** Libellés d'issues non vides, sans doublon, entre 2 et 8. */
function verifier_issues(array $libelles): array
{
    $issues = [];
    foreach ($libelles as $libelle) {
        $libelle = trim((string)$libelle);
        if ($libelle === '') continue;
        if (longueur($libelle) > 80) throw new ErreurApi('Chaque issue est limitée à 80 caractères.');
        foreach ($issues as $deja) {
            if (strcasecmp($deja, $libelle) === 0) throw new ErreurApi("L'issue « $libelle » est en double.");
        }
        $issues[] = $libelle;
    }
    if (count($issues) < 2 || count($issues) > 8) throw new ErreurApi('Il faut entre 2 et 8 issues.');
    return $issues;
}

function lire_joueur(int $id): array
{
    $j = q('SELECT id, pseudo FROM joueurs WHERE id = ?' . verrou(), [$id])->fetch();
    if (!$j) throw new ErreurApi('Joueur introuvable.', 404);
    return $j;
}

function lire_pseudo(mixed $v): string
{
    $pseudo = trim((string)$v);
    if (longueur($pseudo) < 2 || longueur($pseudo) > 30) throw new ErreurApi('Le pseudo doit faire entre 2 et 30 caractères.');
    return $pseudo;
}

function lire_code(mixed $v): string
{
    $pin = (string)$v;
    if (strlen($pin) < 4 || strlen($pin) > 64) throw new ErreurApi('Le code doit faire au moins 4 caractères.');
    return $pin;
}

function route_creer_pari(): array
{
    $moi = joueur_connecte();
    $admin = !empty($_SESSION['admin']);
    if (!$moi && !$admin) throw new ErreurApi('Connectez-vous pour proposer un pari.', 401);
    $d = donnees();
    [$titre, $description, $categorie] = lire_textes_pari($d);
    $type = (string)($d['type'] ?? 'choix');
    if (!in_array($type, TYPES_PARI, true)) throw new ErreurApi('Type de pari invalide.');
    $unite = trim((string)($d['unite'] ?? ''));
    if (longueur($unite) > 30) throw new ErreurApi("L'unité est limitée à 30 caractères.");
    // Un pari sur un chiffre n'a qu'une issue technique : chaque mise porte sa propre estimation.
    $issues = $type === 'estimation' ? ['Estimation']
        : verifier_issues(array_map(fn($i) => is_array($i) ? ($i['libelle'] ?? '') : $i, (array)($d['issues'] ?? [])));
    $dateLimite = lire_date_limite($d['date_limite'] ?? null);
    if ($dateLimite && $dateLimite <= substr(maintenant(), 0, 16)) throw new ErreurApi('La fin des mises doit être dans le futur.');

    return transaction(function () use ($moi, $admin, $titre, $description, $categorie, $issues, $dateLimite, $type, $unite, $d) {
        $etape = lire_etape($d['etape_id'] ?? null);
        if (!$admin) {
            q('SELECT id FROM joueurs WHERE id = ?' . verrou(), [$moi['id']]);
            $recents = q('SELECT COUNT(*) FROM paris WHERE auteur_id = ? AND cree_le >= ?',
                         [$moi['id'], date('Y-m-d\TH:i:s', time() - 86400)])->fetchColumn();
            if ($recents >= PROPOSITIONS_PAR_JOUR) {
                throw new ErreurApi('Vous avez déjà proposé ' . PROPOSITIONS_PAR_JOUR . ' paris ces dernières 24 heures.', 429);
            }
        }
        if (q("SELECT 1 FROM paris WHERE LOWER(titre) = LOWER(?) AND statut IN ('ouvert', 'suspendu')", [$titre])->fetch()) {
            throw new ErreurApi('Un pari en cours porte déjà cet intitulé.', 409);
        }
        $id = inserer_pari(db(), $categorie, $titre, $description, $issues, $dateLimite, $moi ? (int)$moi['id'] : null);
        q('UPDATE paris SET type = ?, unite = ?, etape_id = ? WHERE id = ?', [$type, $unite ?: null, $etape, $id]);
        capturer_cotes($id);
        return ['ok' => true, 'id' => $id];
    });
}

function route_mises(): array
{
    $moi = joueur_connecte();
    if (!$moi) throw new ErreurApi('Connectez-vous pour parier.', 401);
    return miser((int)$moi['id'], donnees(), false);
}

/**
 * Enregistre une mise. L'administration peut miser pour un joueur sur un pari suspendu ou dont la date
 * limite est passée (mise transmise à temps), mais pas au-delà du solde du joueur.
 */
function miser(int $joueurId, array $d, bool $parAdmin): array
{
    $montant = entier($d['montant'] ?? null, 'Mise invalide.');
    $issueId = entier($d['issue_id'] ?? null, 'Mise invalide.');
    if ($montant <= 0) throw new ErreurApi("La mise doit être d'au moins 1 denier public.");

    return transaction(function () use ($joueurId, $montant, $issueId, $parAdmin, $d) {
        $joueur = lire_joueur($joueurId); // verrouillé : une mise à la fois par joueur
        $issue = q('SELECT * FROM issues WHERE id = ?', [$issueId])->fetch();
        if (!$issue) throw new ErreurApi('Issue introuvable.', 404);
        $pari = lire_pari((int)$issue['pari_id'], true);
        if ($parAdmin ? !in_array($pari['statut'], STATUTS_ACTIFS, true) : !accepte_les_mises($pari)) {
            throw new ErreurApi("Ce pari n'accepte plus de mises.", 409);
        }
        $disponible = soldes($joueurId)[0]['disponible'];
        if ($montant > $disponible) {
            throw new ErreurApi(($parAdmin ? "Solde insuffisant : il reste $disponible deniers publics à {$joueur['pseudo']}."
                                           : "Solde insuffisant : il vous reste $disponible deniers publics."), 409);
        }
        if ($pari['type'] === 'estimation') { // une estimation par joueur, secrète jusqu'à la clôture
            $estimation = lire_nombre($d['estimation'] ?? '', 'Indiquez votre estimation (un nombre).');
            if (q('SELECT 1 FROM mises WHERE pari_id = ? AND joueur_id = ?', [$pari['id'], $joueurId])->fetch()) {
                throw new ErreurApi($parAdmin ? "{$joueur['pseudo']} a déjà donné son estimation." : 'Vous avez déjà donné votre estimation sur ce pari.', 409);
            }
            q('INSERT INTO mises (joueur_id, pari_id, issue_id, montant, cote_c, cree_le, par_admin, estimation, tapis) VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?)',
              [$joueurId, $pari['id'], $issueId, $montant, maintenant(), (int)$parAdmin, $estimation, (int)($montant === $disponible)]);
            return ['ok' => true, 'cote' => null, 'gain_estime' => null];
        }
        $masse = $montant + (int)q('SELECT COALESCE(SUM(montant), 0) FROM mises WHERE pari_id = ?', [$pari['id']])->fetchColumn();
        $masseIssue = $montant + (int)q('SELECT COALESCE(SUM(montant), 0) FROM mises WHERE issue_id = ?', [$issueId])->fetchColumn();
        $n = (int)q('SELECT COUNT(*) FROM issues WHERE pari_id = ?', [$pari['id']])->fetchColumn();
        $w = $issue['poids_banque'] === null ? null : (float)$issue['poids_banque'];
        $cote = cote($masse, $masseIssue, $n, $w);
        // cote_c : cote au moment de la mise, à titre indicatif (le gain se calcule à la clôture)
        q('INSERT INTO mises (joueur_id, pari_id, issue_id, montant, cote_c, cree_le, par_admin, tapis) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
          [$joueurId, $pari['id'], $issueId, $montant, (int)floor($cote * 100), maintenant(), (int)$parAdmin, (int)($montant === $disponible)]);
        capturer_cotes((int)$pari['id']);
        return ['ok' => true, 'cote' => $cote, 'gain_estime' => intdiv($montant * a_verser($masse, $masseIssue, $n, $w), $masseIssue)];
    });
}

/** Commentaire (« exposé des motifs ») d'un joueur sur un pari. */
function route_commentaire(): array
{
    $moi = joueur_connecte();
    if (!$moi) throw new ErreurApi('Connectez-vous pour commenter.', 401);
    $texte = trim((string)preg_replace('/\s+/u', ' ', (string)(donnees()['texte'] ?? '')));
    if ($texte === '') throw new ErreurApi('Le commentaire est vide.');
    if (longueur($texte) > COMMENTAIRE_MAX) throw new ErreurApi('Le commentaire est limité à ' . COMMENTAIRE_MAX . ' caractères.');
    $pariId = entier(donnees()['pari_id'] ?? null, 'Pari invalide.');
    return transaction(function () use ($moi, $texte, $pariId) {
        lire_pari($pariId);
        [$max, $fenetre] = COMMENTAIRES_PAR_FENETRE;
        $recents = (int)q('SELECT COUNT(*) FROM commentaires WHERE joueur_id = ? AND cree_le >= ?',
                          [$moi['id'], date('Y-m-d\TH:i:s', time() - $fenetre)])->fetchColumn();
        if ($recents >= $max) throw new ErreurApi('Doucement : attendez un peu avant de commenter à nouveau.', 429);
        q('INSERT INTO commentaires (pari_id, joueur_id, texte, cree_le) VALUES (?, ?, ?, ?)', [$pariId, $moi['id'], $texte, maintenant()]);
        return ['ok' => true];
    });
}

/** Suppression d'un commentaire, par son auteur ou par l'administration. */
function route_commentaire_suppression(int $id): array
{
    $moi = joueur_connecte();
    $admin = !empty($_SESSION['admin']);
    return transaction(function () use ($id, $moi, $admin) {
        $c = q('SELECT joueur_id FROM commentaires WHERE id = ?', [$id])->fetch();
        if (!$c) throw new ErreurApi('Commentaire introuvable.', 404);
        if (!$admin && (!$moi || (int)$c['joueur_id'] !== (int)$moi['id'])) {
            throw new ErreurApi('Seuls son auteur et l\'administration peuvent supprimer ce commentaire.', 403);
        }
        q('DELETE FROM commentaires WHERE id = ?', [$id]);
        return ['ok' => true];
    });
}

// ---------------------------------------------------------------------------
// Administration
// ---------------------------------------------------------------------------

/**
 * Pari flash : ouvert immédiatement pour quelques minutes (pendant une séance). La fin des mises est
 * arrondie à la minute supérieure, pour ne jamais fermer avant la durée annoncée.
 */
function route_admin_flash(): array
{
    $d = donnees();
    [$titre, $description] = lire_textes_pari($d);
    $issues = verifier_issues((array)($d['issues'] ?? ['Oui', 'Non']));
    $minutes = entier($d['minutes'] ?? null, 'Durée invalide.');
    if (!in_array($minutes, DUREES_FLASH, true)) throw new ErreurApi('Durée invalide.');
    $fin = date('Y-m-d\TH:i', (int)ceil((time() + $minutes * 60) / 60) * 60);
    return transaction(function () use ($titre, $description, $issues, $fin, $d) {
        if (q("SELECT 1 FROM paris WHERE LOWER(titre) = LOWER(?) AND statut IN ('ouvert', 'suspendu')", [$titre])->fetch()) {
            throw new ErreurApi('Un pari en cours porte déjà cet intitulé.', 409);
        }
        $id = inserer_pari(db(), 'Flash', $titre, $description, $issues, $fin);
        q('UPDATE paris SET flash = 1, etape_id = ? WHERE id = ?', [lire_etape($d['etape_id'] ?? null), $id]);
        capturer_cotes($id);
        return ['ok' => true, 'id' => $id, 'date_limite' => $fin];
    });
}

function route_admin_connexion(): array
{
    $cle = 'admin:ip:' . adresse_ip();
    limiter($cle, LIMITE_ADMIN_IP);
    if (!hash_equals((string)CONFIG['admin_password'], (string)(donnees()['mot_de_passe'] ?? ''))) {
        noter_tentative($cle);
        throw new ErreurApi('Mot de passe incorrect.', 401);
    }
    oublier_tentatives($cle);
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
    return ['ok' => true];
}

function route_admin_deconnexion(): array
{
    unset($_SESSION['admin']);
    return ['ok' => true];
}

/**
 * Modifie un pari : textes, date limite et libellés des issues, à tout moment. Sur un pari en cours,
 * on peut aussi ajouter des issues ou retirer celles qui n'ont reçu aucune mise.
 * Champs : titre, description, categorie, date_limite, issues {id: libellé}, retirer [id], nouvelles [libellé].
 */
function route_admin_modifier(int $pariId): array
{
    $d = donnees();
    return transaction(function () use ($d, $pariId) {
        $pari = lire_pari($pariId, true);
        $actif = in_array($pari['statut'], STATUTS_ACTIFS, true);
        if (isset($d['titre'])) {
            [$titre, $description, $categorie] = lire_textes_pari($d);
            q('UPDATE paris SET titre = ?, description = ?, categorie = ? WHERE id = ?', [$titre, $description, $categorie, $pariId]);
        }
        if (array_key_exists('date_limite', $d)) {
            q('UPDATE paris SET date_limite = ? WHERE id = ?', [lire_date_limite($d['date_limite']), $pariId]);
        }
        if (array_key_exists('etape_id', $d)) {
            q('UPDATE paris SET etape_id = ? WHERE id = ?', [lire_etape($d['etape_id']), $pariId]);
        }
        if (array_key_exists('unite', $d)) {
            $unite = trim((string)$d['unite']);
            if (longueur($unite) > 30) throw new ErreurApi("L'unité est limitée à 30 caractères.");
            q('UPDATE paris SET unite = ? WHERE id = ?', [$unite ?: null, $pariId]);
        }
        if ($pari['type'] === 'estimation') return ['ok' => true]; // issue technique unique, non modifiable

        $existantes = q('SELECT i.id, i.libelle, (SELECT COUNT(*) FROM mises WHERE issue_id = i.id) AS nb_mises
                         FROM issues i WHERE i.pari_id = ? ORDER BY i.ordre, i.id', [$pariId])->fetchAll();
        $retirer = array_map('intval', (array)($d['retirer'] ?? []));
        $nouvelles = array_values(array_filter(array_map('trim', array_map('strval', (array)($d['nouvelles'] ?? []))), 'strlen'));
        if (($retirer || $nouvelles) && !$actif) {
            throw new ErreurApi("On ne peut ajouter ou retirer des issues que sur un pari en cours.", 409);
        }
        $libelles = [];
        foreach ($existantes as $i) {
            if (in_array((int)$i['id'], $retirer, true)) {
                if ($i['nb_mises'] > 0) throw new ErreurApi("L'issue « {$i['libelle']} » a reçu des mises : impossible de la retirer.", 409);
                continue;
            }
            $libelles[(int)$i['id']] = trim((string)($d['issues'][$i['id']] ?? $i['libelle']));
        }
        if (in_array('', $libelles, true)) throw new ErreurApi('Une issue ne peut pas avoir un libellé vide.');
        verifier_issues([...array_values($libelles), ...$nouvelles]); // doublons, longueurs, 2 à 8 issues

        foreach ($retirer as $id) q('DELETE FROM issues WHERE id = ? AND pari_id = ?', [$id, $pariId]);
        foreach ($libelles as $id => $libelle) q('UPDATE issues SET libelle = ? WHERE id = ?', [$libelle, $id]);
        $ordre = (int)q('SELECT COALESCE(MAX(ordre), 0) FROM issues WHERE pari_id = ?', [$pariId])->fetchColumn();
        foreach ($nouvelles as $libelle) {
            q('INSERT INTO issues (pari_id, libelle, probabilite, ordre) VALUES (?, ?, 0, ?)', [$pariId, $libelle, ++$ordre]);
        }
        if ($actif && ($retirer || $nouvelles)) capturer_cotes($pariId);
        return ['ok' => true];
    });
}

function route_admin_statut(int $pariId): array
{
    $statut = donnees()['statut'] ?? '';
    if (!in_array($statut, STATUTS_ACTIFS, true)) throw new ErreurApi('Statut invalide.');
    return transaction(function () use ($pariId, $statut) {
        lire_pari_actif($pariId);
        q('UPDATE paris SET statut = ? WHERE id = ?', [$statut, $pariId]);
        return ['ok' => true];
    });
}

/**
 * Clôture un pari et paie les gagnants. Pari à choix : issue_id = issue réalisée ; les gagnants sont payés
 * à la cote finale, au prorata de leurs mises (sans amorce, si personne n'a vu juste, chacun est remboursé).
 * Pari sur un chiffre : valeur = valeur réelle ; la ou les estimations les plus proches se partagent la
 * cagnotte et l'apport de la banque, au prorata des mises.
 * realise_le (facultatif) : moment où le résultat a été connu ; les mises placées depuis sont remboursées
 * et ne comptent pas dans la cagnotte.
 */
function route_admin_cloture(int $pariId): array
{
    $d = donnees();
    $realiseLe = lire_date_heure($d['realise_le'] ?? null, "Heure de réalisation invalide.");
    if ($realiseLe && $realiseLe > substr(maintenant(), 0, 16)) throw new ErreurApi("L'heure de réalisation ne peut pas être dans le futur.");
    return transaction(function () use ($pariId, $d, $realiseLe) {
        $pari = lire_pari_actif($pariId);
        $mises = q('SELECT id, issue_id, montant, cree_le, par_admin, estimation FROM mises WHERE pari_id = ? ORDER BY id', [$pariId])->fetchAll();
        [$valides, $tardives] = separer_tardives($mises, $realiseLe);
        $masse = array_sum(array_column($valides, 'montant'));
        $gains = [];
        $issueId = $valeur = null;

        if ($pari['type'] === 'estimation') {
            $valeur = lire_nombre($d['valeur'] ?? '', 'Indiquez la valeur réelle (un nombre).');
            $ecartMin = $valides ? min(array_map(fn($m) => abs((float)$m['estimation'] - $valeur), $valides)) : null;
            $gagnantes = [];
            foreach ($valides as $m) {
                if (abs(abs((float)$m['estimation'] - $valeur) - $ecartMin) < 1e-9) $gagnantes[(int)$m['id']] = (int)$m['montant'];
            }
            if ($gagnantes) $gains = repartir($masse + amorce(), $gagnantes);
        } else {
            $issueId = entier($d['issue_id'] ?? null, "Choisissez l'issue réalisée.");
            if (!q('SELECT 1 FROM issues WHERE id = ? AND pari_id = ?', [$issueId, $pariId])->fetch()) {
                throw new ErreurApi("Cette issue n'appartient pas au pari.");
            }
            $gagnantes = [];
            foreach ($valides as $m) {
                if ((int)$m['issue_id'] === $issueId) $gagnantes[(int)$m['id']] = (int)$m['montant'];
            }
            $n = (int)q('SELECT COUNT(*) FROM issues WHERE pari_id = ?', [$pariId])->fetchColumn();
            $poids = q('SELECT poids_banque FROM issues WHERE id = ?', [$issueId])->fetchColumn();
            $poids = $poids === null ? null : (float)$poids;
            if ($gagnantes) {
                $gains = repartir(a_verser($masse, array_sum($gagnantes), $n, $poids), $gagnantes);
            } elseif (amorce() === 0) { // pari mutuel pur sans gagnant : chacun récupère sa mise
                foreach ($valides as $m) $gains[(int)$m['id']] = (int)$m['montant'];
            }
        }
        foreach ($valides as $m) q('UPDATE mises SET gain = ?, tardive = 0 WHERE id = ?', [$gains[(int)$m['id']] ?? 0, $m['id']]);
        foreach ($tardives as $m) q('UPDATE mises SET gain = montant, tardive = 1 WHERE id = ?', [$m['id']]);
        q("UPDATE paris SET statut = 'clos', issue_gagnante_id = ?, valeur_reelle = ?, realise_le = ?, clos_le = ? WHERE id = ?",
          [$issueId, $valeur, $realiseLe, maintenant(), $pariId]);
        return ['ok' => true, 'nb_tardives' => count($tardives)];
    });
}

/** Annule le pari : chaque joueur récupère ses mises. */
function route_admin_annulation(int $pariId): array
{
    return transaction(function () use ($pariId) {
        lire_pari_actif($pariId);
        q('UPDATE mises SET gain = montant WHERE pari_id = ?', [$pariId]);
        q("UPDATE paris SET statut = 'annule', clos_le = ? WHERE id = ?", [maintenant(), $pariId]);
        return ['ok' => true];
    });
}

/**
 * Fixe la cote actuelle de certaines issues ({id: cote}, « 1,8 » accepté) ; une valeur vide rétablit
 * la part par défaut de la banque. Les mises suivantes font ensuite évoluer la cote normalement.
 */
function route_admin_cotes(int $pariId): array
{
    $cotes = (array)(donnees()['cotes'] ?? []);
    return transaction(function () use ($pariId, $cotes) {
        if (lire_pari_actif($pariId)['type'] === 'estimation') throw new ErreurApi("Un pari sur un chiffre n'a pas de cote.", 409);
        $issues = q('SELECT i.id, i.libelle, (SELECT COALESCE(SUM(montant), 0) FROM mises WHERE issue_id = i.id) AS masse
                     FROM issues i WHERE i.pari_id = ?', [$pariId])->fetchAll();
        $masse = array_sum(array_column($issues, 'masse'));
        $apport = $masse + count($issues) * amorce(); // numérateur commun des cotes
        foreach ($issues as $i) {
            if (!array_key_exists($i['id'], $cotes)) continue;
            $saisie = str_replace([',', '×', 'x', ' '], ['.', '', '', ''], trim((string)$cotes[$i['id']]));
            if ($saisie === '') {
                q('UPDATE issues SET poids_banque = NULL WHERE id = ?', [$i['id']]);
                continue;
            }
            if (amorce() === 0) throw new ErreurApi("Avec une amorce nulle, les cotes ne dépendent que des mises : réglez d'abord une amorce.", 409);
            if (!is_numeric($saisie) || $saisie < 1.01 || $saisie > 1000) {
                throw new ErreurApi("Cote invalide pour « {$i['libelle']} » : entre 1,01 et 1 000.");
            }
            // cote = apport / (masse de l'issue + amorce × poids)  ⇒  poids = (apport / cote − masse de l'issue) / amorce
            $poids = ($apport / (float)$saisie - (int)$i['masse']) / amorce();
            if ($poids < 0) {
                $max = number_format($apport / (int)$i['masse'], 2, ',', ' ');
                throw new ErreurApi("Avec les mises déjà faites, la cote de « {$i['libelle']} » ne peut pas dépasser ×$max.", 409);
            }
            q('UPDATE issues SET poids_banque = ? WHERE id = ?', [$poids, $i['id']]);
        }
        capturer_cotes($pariId);
        return ['ok' => true];
    });
}

/**
 * Revient sur une clôture ou une annulation : les gains versés sont repris et le pari repasse en
 * « suspendu » (à reclôturer, ou à rouvrir aux mises). Un joueur qui a déjà remisé ses gains peut
 * se retrouver avec un solde négatif.
 */
function route_admin_reouverture(int $pariId): array
{
    return transaction(function () use ($pariId) {
        $pari = lire_pari($pariId, true);
        if (in_array($pari['statut'], STATUTS_ACTIFS, true)) throw new ErreurApi("Ce pari n'est pas clôturé.", 409);
        q('UPDATE mises SET gain = NULL, tardive = 0 WHERE pari_id = ?', [$pariId]);
        q("UPDATE paris SET statut = 'suspendu', issue_gagnante_id = NULL, valeur_reelle = NULL, realise_le = NULL,
           clos_le = NULL WHERE id = ?", [$pariId]);
        $negatifs = array_column(array_filter(soldes(), fn($j) => $j['disponible'] < 0), 'pseudo');
        return ['ok' => true, 'soldes_negatifs' => array_values($negatifs)];
    });
}

function route_admin_miser(): array
{
    return miser(entier(donnees()['joueur_id'] ?? null, 'Choisissez un joueur.'), donnees(), true);
}

/** Supprime une mise sur un pari en cours : le joueur récupère sa mise. */
function route_admin_suppression_mise(int $miseId): array
{
    return transaction(function () use ($miseId) {
        $m = q('SELECT * FROM mises WHERE id = ?', [$miseId])->fetch();
        if (!$m) throw new ErreurApi('Mise introuvable.', 404);
        lire_pari_actif((int)$m['pari_id']);
        q('DELETE FROM mises WHERE id = ?', [$miseId]);
        capturer_cotes((int)$m['pari_id']);
        return ['ok' => true];
    });
}

/** Renomme un joueur et/ou lui donne un nouveau code. */
function route_admin_joueur_maj(int $joueurId): array
{
    $d = donnees();
    return transaction(function () use ($d, $joueurId) {
        lire_joueur($joueurId);
        if (isset($d['pseudo'])) {
            $pseudo = lire_pseudo($d['pseudo']);
            if (q('SELECT 1 FROM joueurs WHERE pseudo = ? AND id <> ?', [$pseudo, $joueurId])->fetch()) {
                throw new ErreurApi('Ce pseudo est déjà pris.', 409);
            }
            q('UPDATE joueurs SET pseudo = ? WHERE id = ?', [$pseudo, $joueurId]);
        }
        if (array_key_exists('equipe_id', $d)) {
            q('UPDATE joueurs SET equipe_id = ? WHERE id = ?', [lire_equipe($d['equipe_id']), $joueurId]);
        }
        if (($d['pin'] ?? '') !== '') {
            q('UPDATE joueurs SET pin_hash = ? WHERE id = ?', [password_hash(lire_code($d['pin']), PASSWORD_DEFAULT), $joueurId]);
        }
        return ['ok' => true];
    });
}

/** Crédite (montant positif) ou débite (négatif) des deniers publics à un joueur. */
function route_admin_ajustement(int $joueurId): array
{
    $montant = entier(donnees()['montant'] ?? null, 'Montant invalide.');
    $motif = trim((string)(donnees()['motif'] ?? ''));
    if ($montant === 0) throw new ErreurApi('Le montant ne peut pas être nul.');
    if (longueur($motif) > 200) throw new ErreurApi('Le motif est limité à 200 caractères.');
    return transaction(function () use ($joueurId, $montant, $motif) {
        lire_joueur($joueurId);
        q('INSERT INTO ajustements (joueur_id, montant, motif, cree_le) VALUES (?, ?, ?, ?)', [$joueurId, $montant, $motif, maintenant()]);
        return ['ok' => true];
    });
}

/** Supprime un joueur, ses mises et ses ajustements (les paris qu'il a proposés restent). */
function route_admin_joueur_suppression(int $joueurId): array
{
    return transaction(function () use ($joueurId) {
        lire_joueur($joueurId);
        q('DELETE FROM mises WHERE joueur_id = ?', [$joueurId]);
        q('DELETE FROM commentaires WHERE joueur_id = ?', [$joueurId]);
        q('DELETE FROM ajustements WHERE joueur_id = ?', [$joueurId]);
        q('UPDATE paris SET auteur_id = NULL WHERE auteur_id = ?', [$joueurId]);
        q('DELETE FROM joueurs WHERE id = ?', [$joueurId]);
        return ['ok' => true];
    });
}

/** Capital de départ et amorce ; une valeur vide revient au réglage de config.php. */
function route_admin_reglages(): array
{
    $d = donnees();
    return transaction(function () use ($d) {
        foreach (['capital' => [0, 1000000], 'amorce' => [0, 100000]] as $cle => [$min, $max]) {
            if (!array_key_exists($cle, $d)) continue;
            q('DELETE FROM reglages WHERE cle = ?', [$cle]);
            if ($d[$cle] === '' || $d[$cle] === null) continue;
            $v = entier($d[$cle], 'Valeur invalide.');
            if ($v < $min || $v > $max) throw new ErreurApi("Valeur hors limites ($min à $max).");
            q('INSERT INTO reglages (cle, valeur) VALUES (?, ?)', [$cle, (string)$v]);
        }
        if (array_key_exists('code_invitation', $d)) {
            $code = trim((string)$d['code_invitation']);
            if (longueur($code) > 50) throw new ErreurApi("Le code d'invitation est limité à 50 caractères.");
            q("DELETE FROM reglages WHERE cle = 'code_invitation'");
            if ($code !== '') q("INSERT INTO reglages (cle, valeur) VALUES ('code_invitation', ?)", [$code]);
        }
        if (array_key_exists('amorce', $d)) {
            foreach (q("SELECT id FROM paris WHERE statut IN ('ouvert', 'suspendu')")->fetchAll(PDO::FETCH_COLUMN) as $id) {
                capturer_cotes((int)$id);
            }
        }
        return ['ok' => true];
    });
}

/** Données réservées à l'administration, ajoutées à l'état. */
function donnees_admin(): array
{
    return [
        'code_invitation' => reglage_texte('code_invitation'),
        'suspendus_en_bloc' => suspendus_en_bloc(),
        'capital_defaut' => (int)CAPITAL_DEFAUT,
        'amorce_defaut' => (int)AMORCE_DEFAUT,
        'mises' => array_map(fn($m) => [
            'id' => (int)$m['id'], 'joueur_id' => (int)$m['joueur_id'], 'pari_id' => (int)$m['pari_id'],
            'issue_id' => (int)$m['issue_id'], 'montant' => (int)$m['montant'],
            'gain' => $m['gain'] === null ? null : (int)$m['gain'], 'cree_le' => $m['cree_le'],
            'par_admin' => (bool)$m['par_admin'], 'tardive' => (bool)$m['tardive'],
            'estimation' => $m['estimation'] === null ? null : (float)$m['estimation'],
        ], q('SELECT * FROM mises ORDER BY cree_le DESC, id DESC')->fetchAll()),
        'ajustements' => array_map(fn($a) => [
            'joueur_id' => (int)$a['joueur_id'], 'montant' => (int)$a['montant'], 'motif' => $a['motif'], 'cree_le' => $a['cree_le'],
        ], q('SELECT * FROM ajustements ORDER BY cree_le DESC, id DESC')->fetchAll()),
    ];
}

/** Paris suspendus d'un coup par « Tout suspendre », encore suspendus. */
function suspendus_en_bloc(): array
{
    $ids = array_filter(array_map('intval', explode(',', reglage_texte('suspendus_en_bloc'))));
    if (!$ids) return [];
    $marques = implode(',', array_fill(0, count($ids), '?'));
    return array_map('intval', q("SELECT id FROM paris WHERE statut = 'suspendu' AND id IN ($marques)", array_values($ids))
        ->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * « Tout suspendre » : coupe les mises de tous les paris ouverts (pendant une séance, par exemple).
 * « Rouvrir » ne rouvre que les paris ainsi suspendus, pas ceux suspendus un par un.
 */
function route_admin_suspension_generale(): array
{
    $action = donnees()['action'] ?? '';
    return transaction(function () use ($action) {
        if ($action === 'suspendre') {
            $ids = q("SELECT id FROM paris WHERE statut = 'ouvert'")->fetchAll(PDO::FETCH_COLUMN);
            q("UPDATE paris SET statut = 'suspendu' WHERE statut = 'ouvert'");
            $tous = array_unique([...suspendus_en_bloc(), ...array_map('intval', $ids)]);
            q("DELETE FROM reglages WHERE cle = 'suspendus_en_bloc'");
            q("INSERT INTO reglages (cle, valeur) VALUES ('suspendus_en_bloc', ?)", [implode(',', $tous)]);
            return ['ok' => true, 'nb' => count($ids)];
        }
        if ($action === 'rouvrir') {
            $ids = suspendus_en_bloc();
            foreach ($ids as $id) q("UPDATE paris SET statut = 'ouvert' WHERE id = ?", [$id]);
            q("DELETE FROM reglages WHERE cle = 'suspendus_en_bloc'");
            return ['ok' => true, 'nb' => count($ids)];
        }
        throw new ErreurApi('Action invalide.');
    });
}

function lire_nom_equipe(mixed $v): string
{
    $nom = trim((string)$v);
    if (longueur($nom) < 2 || longueur($nom) > 40) throw new ErreurApi("Le nom d'équipe doit faire entre 2 et 40 caractères.");
    if (strlen(cle_equipe($nom)) < 2) throw new ErreurApi("Le nom d'équipe doit contenir au moins deux lettres ou chiffres.");
    return $nom;
}

function route_admin_creer_equipe(): array
{
    $nom = lire_nom_equipe(donnees()['nom'] ?? '');
    return transaction(function () use ($nom) {
        if ($e = trouver_equipe($nom)) throw new ErreurApi("L'équipe « {$e['nom']} » existe déjà.", 409);
        q('INSERT INTO equipes (nom) VALUES (?)', [$nom]);
        return ['ok' => true];
    });
}

function route_admin_equipe_maj(int $id): array
{
    $nom = lire_nom_equipe(donnees()['nom'] ?? '');
    return transaction(function () use ($id, $nom) {
        lire_equipe($id);
        if ($e = trouver_equipe($nom, $id)) throw new ErreurApi("L'équipe « {$e['nom']} » existe déjà.", 409);
        q('UPDATE equipes SET nom = ? WHERE id = ?', [$nom, $id]);
        return ['ok' => true];
    });
}

/** Supprime une équipe : ses membres se retrouvent sans équipe. */
function route_admin_equipe_suppression(int $id): array
{
    return transaction(function () use ($id) {
        lire_equipe($id);
        q('UPDATE joueurs SET equipe_id = NULL WHERE equipe_id = ?', [$id]);
        q('DELETE FROM equipes WHERE id = ?', [$id]);
        return ['ok' => true];
    });
}

/** Date (AAAA-MM-JJ), intitulé et précisions d'une étape du calendrier. */
function lire_etape_saisie(array $d): array
{
    $date = DateTime::createFromFormat('!Y-m-d', (string)($d['date'] ?? ''));
    if (!$date) throw new ErreurApi("Date de l'étape invalide.");
    $titre = trim((string)($d['titre'] ?? ''));
    $description = trim((string)($d['description'] ?? ''));
    if (longueur($titre) < 2 || longueur($titre) > 150) throw new ErreurApi("L'intitulé de l'étape doit faire entre 2 et 150 caractères.");
    if (longueur($description) > 500) throw new ErreurApi('Les précisions sont limitées à 500 caractères.');
    return [$date->format('Y-m-d'), $titre, $description];
}

function route_admin_creer_etape(): array
{
    [$date, $titre, $description] = lire_etape_saisie(donnees());
    return transaction(function () use ($date, $titre, $description) {
        q('INSERT INTO etapes (date_etape, titre, description) VALUES (?, ?, ?)', [$date, $titre, $description]);
        return ['ok' => true];
    });
}

function route_admin_etape_maj(int $id): array
{
    [$date, $titre, $description] = lire_etape_saisie(donnees());
    return transaction(function () use ($id, $date, $titre, $description) {
        lire_etape($id);
        q('UPDATE etapes SET date_etape = ?, titre = ?, description = ? WHERE id = ?', [$date, $titre, $description, $id]);
        return ['ok' => true];
    });
}

/** Supprime une étape : les paris qui y étaient rattachés restent, sans étape. */
function route_admin_etape_suppression(int $id): array
{
    return transaction(function () use ($id) {
        lire_etape($id);
        q('UPDATE paris SET etape_id = NULL WHERE etape_id = ?', [$id]);
        q('DELETE FROM etapes WHERE id = ?', [$id]);
        return ['ok' => true];
    });
}

/**
 * Télécharge une sauvegarde complète. SQLite : copie cohérente du fichier de base (à remettre en place
 * par FTP pour restaurer). MySQL, ou SQLite trop ancien : export JSON de toutes les tables.
 */
function route_admin_sauvegarde(): never
{
    session_write_close();
    $horodatage = date('Ymd-His');
    if (est_sqlite()) {
        $copie = __DIR__ . '/data/sauvegarde-' . bin2hex(random_bytes(8)) . '.db';
        try {
            db()->exec('VACUUM INTO ' . db()->quote($copie));
            header('Content-Type: application/octet-stream');
            header("Content-Disposition: attachment; filename=\"plf-sauvegarde-$horodatage.db\"");
            header('Content-Length: ' . filesize($copie));
            readfile($copie);
            @unlink($copie); // avant exit : exit n'exécute pas les blocs finally
            exit;
        } catch (PDOException) {
            // VACUUM INTO indisponible (SQLite < 3.27) : export JSON ci-dessous
            if (is_file($copie)) @unlink($copie);
        }
    }
    $export = ['exporte_le' => maintenant(), 'version' => VERSION_BASE];
    foreach (['reglages', 'joueurs', 'equipes', 'etapes', 'paris', 'issues', 'mises', 'ajustements', 'cotes_historique', 'commentaires', 'depeches'] as $table) {
        $export[$table] = q("SELECT * FROM $table")->fetchAll();
    }
    header('Content-Type: application/json; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"plf-sauvegarde-$horodatage.json\"");
    echo json_encode($export, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function route_admin_suppression(int $pariId): array
{
    return transaction(function () use ($pariId) {
        lire_pari($pariId, true);
        if (q('SELECT 1 FROM mises WHERE pari_id = ?', [$pariId])->fetch()) {
            throw new ErreurApi('Impossible de supprimer un pari sur lequel des mises existent : annulez-le.', 409);
        }
        q('DELETE FROM issues WHERE pari_id = ?', [$pariId]);
        q('DELETE FROM commentaires WHERE pari_id = ?', [$pariId]);
        q('DELETE FROM cotes_historique WHERE pari_id = ?', [$pariId]);
        q('DELETE FROM paris WHERE id = ?', [$pariId]);
        return ['ok' => true];
    });
}

// ---------------------------------------------------------------------------
// Aiguillage
// ---------------------------------------------------------------------------

try {
    demarrer_session();
    $route = trim((string)($_GET['r'] ?? ''), '/');
    $methode = $_SERVER['REQUEST_METHOD'];

    if ($methode === 'GET') {
        if ($route === 'etat') {
            $cle = cle_etat();
            if (($_GET['v'] ?? '') === $cle) { // rien n'a changé : réponse minimale
                session_write_close();
                repondre(['inchange' => true, 'v' => $cle]);
            }
            repondre(route_etat() + ['v' => $cle]);
        }
        if ($route === 'historique-joueurs') repondre(route_historique_joueurs());
        if ($route === 'historique-cotes') repondre(route_historique_cotes());
        if ($route === 'fiche') repondre(route_fiche());
        if ($route === 'admin/sauvegarde') {
            exiger_admin();
            route_admin_sauvegarde();
        }
        throw new ErreurApi('Route inconnue.', 404);
    }
    if ($methode !== 'POST') throw new ErreurApi('Route inconnue.', 404);

    $publiques = [
        'inscription' => 'route_inscription',
        'connexion' => 'route_connexion',
        'deconnexion' => 'route_deconnexion',
        'mises' => 'route_mises',
        'paris' => 'route_creer_pari',
        'profil' => 'route_profil',
        'commentaires' => 'route_commentaire',
        'admin/connexion' => 'route_admin_connexion',
        'admin/deconnexion' => 'route_admin_deconnexion',
    ];
    if (isset($publiques[$route])) repondre($publiques[$route]());
    if (preg_match('#^commentaires/(\d+)/suppression$#', $route, $m)) repondre(route_commentaire_suppression((int)$m[1]));

    exiger_admin();
    if ($route === 'admin/mises') repondre(route_admin_miser());
    if ($route === 'admin/reglages') repondre(route_admin_reglages());
    if ($route === 'admin/suspension-generale') repondre(route_admin_suspension_generale());
    if ($route === 'admin/flash') repondre(route_admin_flash());
    if ($route === 'admin/depeches') repondre(route_admin_depeche());
    if ($route === 'admin/pari-du-jour') repondre(route_admin_pari_du_jour());
    if ($route === 'admin/equipes') repondre(route_admin_creer_equipe());
    if ($route === 'admin/etapes') repondre(route_admin_creer_etape());
    $routesAdmin = [
        'paris' => ['maj' => 'route_admin_modifier', 'statut' => 'route_admin_statut', 'cloture' => 'route_admin_cloture',
                    'annulation' => 'route_admin_annulation', 'reouverture' => 'route_admin_reouverture',
                    'cotes' => 'route_admin_cotes', 'deplacer' => 'route_admin_deplacer',
                    'suppression' => 'route_admin_suppression'],
        'joueurs' => ['maj' => 'route_admin_joueur_maj', 'ajustement' => 'route_admin_ajustement',
                      'suppression' => 'route_admin_joueur_suppression'],
        'mises' => ['suppression' => 'route_admin_suppression_mise'],
        'equipes' => ['maj' => 'route_admin_equipe_maj', 'suppression' => 'route_admin_equipe_suppression'],
        'etapes' => ['maj' => 'route_admin_etape_maj', 'suppression' => 'route_admin_etape_suppression'],
        'depeches' => ['suppression' => 'route_admin_depeche_suppression'],
    ];
    if (preg_match('#^admin/(paris|joueurs|mises|equipes|etapes|depeches)/(\d+)/([a-z]+)$#', $route, $m) && isset($routesAdmin[$m[1]][$m[3]])) {
        repondre($routesAdmin[$m[1]][$m[3]]((int)$m[2]));
    }
    throw new ErreurApi('Route inconnue.', 404);
} catch (ErreurApi $e) {
    repondre(['erreur' => $e->getMessage()], $e->getCode() ?: 400);
} catch (Throwable $e) {
    error_log('PLF paris : ' . $e);
    repondre(['erreur' => 'Erreur interne du serveur.' . (!empty(CONFIG['debug']) ? ' ' . $e->getMessage() : '')], 500);
}
