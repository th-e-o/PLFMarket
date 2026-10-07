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

// Paris créés à l'installation.
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

const VERSION_BASE = 2;
const PROPOSITIONS_PAR_JOUR = 5;

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
define('CAPITAL', (int)(CONFIG['capital'] ?? 1000));
define('AMORCE', max(0, (int)(CONFIG['amorce'] ?? 100)));
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
        if (($pdo->query('SELECT COUNT(*) FROM paris')->fetchColumn() == 0) && (CONFIG['exemples'] ?? true)) {
            foreach (PARIS_EXEMPLES as $p) inserer_pari($pdo, ...$p);
        }
    }
    if ($version < 2) { // paris proposés par les joueurs, nouveaux paris
        $pdo->exec('ALTER TABLE paris ADD COLUMN auteur_id INTEGER');
        foreach (PARIS_V2 as $p) inserer_pari($pdo, ...$p);
    }
    $pdo->exec("DELETE FROM reglages WHERE cle = 'version'");
    $pdo->prepare("INSERT INTO reglages (cle, valeur) VALUES ('version', ?)")->execute([(string)VERSION_BASE]);
}

function inserer_pari(PDO $pdo, string $categorie, string $titre, string $description, array $issues,
                      ?string $dateLimite = null, ?int $auteurId = null): int
{
    $pdo->prepare('INSERT INTO paris (titre, description, categorie, date_limite, cree_le) VALUES (?, ?, ?, ?, ?)')
        ->execute([$titre, $description, $categorie, $dateLimite, maintenant()]);
    $pariId = (int)$pdo->lastInsertId();
    if ($auteurId) $pdo->prepare('UPDATE paris SET auteur_id = ? WHERE id = ?')->execute([$auteurId, $pariId]);
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
 * Pari mutuel amorcé : la « banque » mise fictivement AMORCE clochettes sur chaque issue, pour que les
 * cotes soient attrayantes dès l'ouverture (×2 sur un Oui/Non) et stables tant qu'il y a peu de mises.
 * Cote d'une issue = (masse misée + amorces) / (masse misée sur l'issue + amorce) ; null sans amorce
 * tant que personne n'y a misé. À la clôture, les gagnants sont payés à cette cote ; la part de la
 * banque n'est versée à personne. Elle crée des clochettes quand les joueurs gagnent, en détruit sinon.
 */
function cote(int $masse, int $masseIssue, int $nbIssues): ?float
{
    return $masseIssue + AMORCE > 0 ? ($masse + $nbIssues * AMORCE) / ($masseIssue + AMORCE) : null;
}

/** Clochettes à partager entre les mises sur l'issue réalisée (masse de l'issue × cote). */
function a_verser(int $masse, int $masseIssue, int $nbIssues): int
{
    return intdiv($masseIssue * ($masse + $nbIssues * AMORCE), $masseIssue + AMORCE);
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

function accepte_les_mises(array $pari): bool
{
    return $pari['statut'] === 'ouvert'
        && !($pari['date_limite'] && $pari['date_limite'] <= substr(maintenant(), 0, 16));
}

/** Clochettes de chaque joueur : disponibles, engagées sur des paris en cours, total. */
function soldes(?int $joueurId = null): array
{
    $filtre = $joueurId ? 'WHERE j.id = ?' : '';
    $lignes = q("
        SELECT j.id, j.pseudo,
               COALESCE(SUM(m.montant), 0) AS mise_totale,
               COALESCE(SUM(CASE WHEN p.statut IN ('ouvert', 'suspendu') THEN m.montant END), 0) AS en_jeu,
               COALESCE(SUM(m.gain), 0) AS gains,
               COUNT(CASE WHEN p.statut = 'clos' AND m.issue_id = p.issue_gagnante_id THEN 1 END) AS paris_gagnes
        FROM joueurs j
        LEFT JOIN mises m ON m.joueur_id = j.id
        LEFT JOIN paris p ON p.id = m.pari_id
        $filtre
        GROUP BY j.id, j.pseudo", $joueurId ? [$joueurId] : [])->fetchAll();
    return array_map(function ($l) {
        $disponible = CAPITAL - (int)$l['mise_totale'] + (int)$l['gains'];
        return [
            'id' => (int)$l['id'],
            'pseudo' => $l['pseudo'],
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

function entier(mixed $v, string $message): int
{
    if (filter_var($v, FILTER_VALIDATE_INT) === false) throw new ErreurApi($message);
    return (int)$v;
}

// ---------------------------------------------------------------------------
// Routes publiques
// ---------------------------------------------------------------------------

function route_etat(): array
{
    $moi = joueur_connecte();
    session_write_close(); // libère la session : les rafraîchissements ne se bloquent pas entre eux
    $joueurs = classement();

    $issuesParPari = $masseIssue = $massePari = [];
    foreach (q('
        SELECT i.id, i.pari_id, i.libelle,
               (SELECT COALESCE(SUM(montant), 0) FROM mises WHERE issue_id = i.id) AS total_mise,
               (SELECT COUNT(DISTINCT joueur_id) FROM mises WHERE issue_id = i.id) AS nb_joueurs
        FROM issues i ORDER BY i.pari_id, i.ordre, i.id') as $i) {
        $masseIssue[$i['id']] = (int)$i['total_mise'];
        $massePari[$i['pari_id']] = ($massePari[$i['pari_id']] ?? 0) + (int)$i['total_mise'];
        $issuesParPari[$i['pari_id']][] = [
            'id' => (int)$i['id'],
            'libelle' => $i['libelle'],
            'total_mise' => (int)$i['total_mise'],
            'nb_joueurs' => (int)$i['nb_joueurs'],
        ];
    }
    foreach ($issuesParPari as $pariId => &$issues) {
        foreach ($issues as &$i) $i['cote'] = cote($massePari[$pariId], $i['total_mise'], count($issues));
        unset($i);
    }
    unset($issues);

    $paris = [];
    foreach (q("
        SELECT p.*, j.pseudo AS auteur,
               (SELECT COUNT(DISTINCT joueur_id) FROM mises WHERE pari_id = p.id) AS nb_joueurs
        FROM paris p LEFT JOIN joueurs j ON j.id = p.auteur_id
        ORDER BY CASE p.statut WHEN 'ouvert' THEN 0 WHEN 'suspendu' THEN 1 ELSE 2 END,
                 CASE WHEN p.statut IN ('clos', 'annule') THEN p.clos_le END DESC,
                 p.date_limite IS NULL, p.date_limite, p.id") as $p) {
        $issues = $issuesParPari[$p['id']] ?? [];
        $paris[] = [
            'id' => (int)$p['id'],
            'titre' => $p['titre'],
            'description' => $p['description'],
            'categorie' => $p['categorie'],
            'auteur' => $p['auteur'],
            'statut' => $p['statut'],
            'accepte_mises' => accepte_les_mises($p),
            'date_limite' => $p['date_limite'],
            'issue_gagnante_id' => $p['issue_gagnante_id'] === null ? null : (int)$p['issue_gagnante_id'],
            'clos_le' => $p['clos_le'],
            'total_mise' => $massePari[$p['id']] ?? 0,
            'nb_joueurs' => (int)$p['nb_joueurs'],
            'issues' => $issues,
        ];
    }

    $mesMises = [];
    if ($moi) {
        foreach (q('
            SELECT m.id, m.pari_id, m.issue_id, m.montant, m.gain, m.cree_le,
                   p.titre AS pari_titre, p.statut AS statut_pari, i.libelle AS issue_libelle
            FROM mises m JOIN paris p ON p.id = m.pari_id JOIN issues i ON i.id = m.issue_id
            WHERE m.joueur_id = ? ORDER BY m.cree_le DESC, m.id DESC', [$moi['id']]) as $m) {
            $montant = (int)$m['montant'];
            $enCours = $m['gain'] === null;
            [$masse, $masseI, $n] = [$massePari[$m['pari_id']], $masseIssue[$m['issue_id']], count($issuesParPari[$m['pari_id']])];
            $mesMises[] = [
                'id' => (int)$m['id'], 'pari_id' => (int)$m['pari_id'], 'issue_id' => (int)$m['issue_id'],
                'montant' => $montant,
                // en cours : cote actuelle de l'issue ; terminé : cote effectivement obtenue
                'cote' => $enCours ? cote($masse, $masseI, $n) : (int)$m['gain'] / $montant,
                'gain_estime' => $enCours ? intdiv($montant * a_verser($masse, $masseI, $n), $masseI) : null,
                'gain' => $enCours ? null : (int)$m['gain'], 'cree_le' => $m['cree_le'],
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
        'capital' => CAPITAL,
        'amorce' => AMORCE,
        'maintenant' => maintenant(),
        'admin' => !empty($_SESSION['admin']),
        'moi' => $monClassement,
        'classement' => $joueurs,
        'paris' => $paris,
        'mes_mises' => $mesMises,
        'fil' => fil_actualite(),
    ];
}

function fil_actualite(int $limite = 30): array
{
    $evenements = [];
    foreach (q("
        SELECT m.id, m.cree_le, m.montant, j.pseudo, i.libelle, p.titre
        FROM mises m JOIN joueurs j ON j.id = m.joueur_id
        JOIN issues i ON i.id = m.issue_id JOIN paris p ON p.id = m.pari_id
        ORDER BY m.cree_le DESC, m.id DESC LIMIT $limite") as $m) {
        $evenements[] = ['date' => $m['cree_le'], 'type' => 'mise', 'joueur' => $m['pseudo'],
                         'montant' => (int)$m['montant'], 'issue' => $m['libelle'], 'pari' => $m['titre']];
    }
    foreach (q("
        SELECT p.clos_le, p.titre, p.statut, i.libelle,
               (SELECT COUNT(*) FROM mises WHERE issue_id = p.issue_gagnante_id) AS nb_gagnants,
               (SELECT COALESCE(SUM(gain), 0) FROM mises WHERE pari_id = p.id) AS distribue
        FROM paris p LEFT JOIN issues i ON i.id = p.issue_gagnante_id
        WHERE p.statut IN ('clos', 'annule')
        ORDER BY p.clos_le DESC LIMIT $limite") as $p) {
        $evenements[] = ['date' => $p['clos_le'], 'type' => $p['statut'], 'pari' => $p['titre'],
                         'issue' => $p['libelle'], 'nb_gagnants' => (int)$p['nb_gagnants'],
                         'distribue' => (int)$p['distribue']];
    }
    foreach (q("
        SELECT p.cree_le, p.titre, j.pseudo FROM paris p LEFT JOIN joueurs j ON j.id = p.auteur_id
        ORDER BY p.cree_le DESC, p.id DESC LIMIT $limite") as $p) {
        $evenements[] = ['date' => $p['cree_le'], 'type' => 'pari', 'pari' => $p['titre'], 'joueur' => $p['pseudo']];
    }
    foreach (q("SELECT cree_le, pseudo FROM joueurs ORDER BY cree_le DESC, id DESC LIMIT $limite") as $j) {
        $evenements[] = ['date' => $j['cree_le'], 'type' => 'joueur', 'joueur' => $j['pseudo']];
    }
    usort($evenements, fn($a, $b) => strcmp((string)$b['date'], (string)$a['date']));
    return array_slice($evenements, 0, $limite);
}

function route_inscription(): array
{
    $pseudo = trim((string)(donnees()['pseudo'] ?? ''));
    $pin = (string)(donnees()['pin'] ?? '');
    if (longueur($pseudo) < 2 || longueur($pseudo) > 30) {
        throw new ErreurApi('Le pseudo doit faire entre 2 et 30 caractères.');
    }
    if (strlen($pin) < 4 || strlen($pin) > 64) throw new ErreurApi('Le code doit faire au moins 4 caractères.');
    if (q('SELECT 1 FROM joueurs WHERE pseudo = ?', [$pseudo])->fetch()) {
        throw new ErreurApi('Ce pseudo est déjà pris.', 409);
    }
    try {
        q('INSERT INTO joueurs (pseudo, pin_hash, cree_le) VALUES (?, ?, ?)',
          [$pseudo, password_hash($pin, PASSWORD_DEFAULT), maintenant()]);
    } catch (PDOException) {
        throw new ErreurApi('Ce pseudo est déjà pris.', 409);
    }
    session_regenerate_id(true);
    $_SESSION['joueur_id'] = (int)db()->lastInsertId();
    return ['ok' => true];
}

function route_connexion(): array
{
    $j = q('SELECT id, pin_hash FROM joueurs WHERE pseudo = ?', [trim((string)(donnees()['pseudo'] ?? ''))])->fetch();
    if (!$j || !password_verify((string)(donnees()['pin'] ?? ''), $j['pin_hash'])) {
        throw new ErreurApi('Pseudo ou code incorrect.', 401);
    }
    session_regenerate_id(true);
    $_SESSION['joueur_id'] = (int)$j['id'];
    return ['ok' => true];
}

function route_deconnexion(): array
{
    $_SESSION = [];
    return ['ok' => true];
}

/** Pari proposé par un joueur (ou créé par l'administration, sans limite). */
function route_creer_pari(): array
{
    $moi = joueur_connecte();
    $admin = !empty($_SESSION['admin']);
    if (!$moi && !$admin) throw new ErreurApi('Connectez-vous pour proposer un pari.', 401);
    $d = donnees();
    $titre = trim((string)($d['titre'] ?? ''));
    $description = trim((string)($d['description'] ?? ''));
    $categorie = trim((string)($d['categorie'] ?? ''));
    if (longueur($titre) < 5 || longueur($titre) > 200) throw new ErreurApi("L'intitulé doit faire entre 5 et 200 caractères.");
    if (longueur($description) > 500) throw new ErreurApi('Les précisions sont limitées à 500 caractères.');
    if (longueur($categorie) > 40) throw new ErreurApi('La catégorie est limitée à 40 caractères.');
    $issues = [];
    foreach ((array)($d['issues'] ?? []) as $i) {
        $libelle = trim((string)(is_array($i) ? ($i['libelle'] ?? '') : $i));
        if ($libelle === '') continue;
        if (longueur($libelle) > 80) throw new ErreurApi('Chaque issue est limitée à 80 caractères.');
        foreach ($issues as $deja) {
            if (strcasecmp($deja, $libelle) === 0) throw new ErreurApi("L'issue « $libelle » est en double.");
        }
        $issues[] = $libelle;
    }
    if (count($issues) < 2 || count($issues) > 8) throw new ErreurApi('Il faut entre 2 et 8 issues.');
    $dateLimite = lire_date_limite($d['date_limite'] ?? null);
    if ($dateLimite && $dateLimite <= substr(maintenant(), 0, 16)) throw new ErreurApi('La fin des mises doit être dans le futur.');

    return transaction(function () use ($moi, $admin, $titre, $description, $categorie, $issues, $dateLimite) {
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
        return ['ok' => true, 'id' => $id];
    });
}

function route_mises(): array
{
    $moi = joueur_connecte();
    if (!$moi) throw new ErreurApi('Connectez-vous pour parier.', 401);
    $montant = entier(donnees()['montant'] ?? null, 'Mise invalide.');
    $issueId = entier(donnees()['issue_id'] ?? null, 'Mise invalide.');
    if ($montant <= 0) throw new ErreurApi("La mise doit être d'au moins 1 clochette.");

    return transaction(function () use ($moi, $montant, $issueId) {
        q('SELECT id FROM joueurs WHERE id = ?' . verrou(), [$moi['id']]); // une mise à la fois par joueur
        $issue = q('SELECT * FROM issues WHERE id = ?', [$issueId])->fetch();
        if (!$issue) throw new ErreurApi('Issue introuvable.', 404);
        $pari = lire_pari((int)$issue['pari_id'], true);
        if (!accepte_les_mises($pari)) throw new ErreurApi("Ce pari n'accepte plus de mises.", 409);
        $disponible = soldes((int)$moi['id'])[0]['disponible'];
        if ($montant > $disponible) {
            throw new ErreurApi("Solde insuffisant : il vous reste $disponible clochettes.", 409);
        }
        $masse = $montant + (int)q('SELECT COALESCE(SUM(montant), 0) FROM mises WHERE pari_id = ?', [$pari['id']])->fetchColumn();
        $masseIssue = $montant + (int)q('SELECT COALESCE(SUM(montant), 0) FROM mises WHERE issue_id = ?', [$issueId])->fetchColumn();
        $n = (int)q('SELECT COUNT(*) FROM issues WHERE pari_id = ?', [$pari['id']])->fetchColumn();
        $cote = cote($masse, $masseIssue, $n);
        // cote_c : cote au moment de la mise, à titre indicatif (le gain se calcule à la clôture)
        q('INSERT INTO mises (joueur_id, pari_id, issue_id, montant, cote_c, cree_le) VALUES (?, ?, ?, ?, ?, ?)',
          [$moi['id'], $pari['id'], $issueId, $montant, (int)floor($cote * 100), maintenant()]);
        return ['ok' => true, 'cote' => $cote, 'gain_estime' => intdiv($montant * a_verser($masse, $masseIssue, $n), $masseIssue)];
    });
}

// ---------------------------------------------------------------------------
// Administration
// ---------------------------------------------------------------------------

function route_admin_connexion(): array
{
    if (!hash_equals((string)CONFIG['admin_password'], (string)(donnees()['mot_de_passe'] ?? ''))) {
        throw new ErreurApi('Mot de passe incorrect.', 401);
    }
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
    return ['ok' => true];
}

function route_admin_deconnexion(): array
{
    unset($_SESSION['admin']);
    return ['ok' => true];
}

/** Modifie la date limite des mises. */
function route_admin_modifier(int $pariId): array
{
    $d = donnees();
    return transaction(function () use ($d, $pariId) {
        lire_pari_actif($pariId);
        if (array_key_exists('date_limite', $d)) {
            q('UPDATE paris SET date_limite = ? WHERE id = ?', [lire_date_limite($d['date_limite']), $pariId]);
        }
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
 * Déclare l'issue réalisée et paie les gagnants à la cote finale, au prorata de leurs mises.
 * Sans amorce, si personne n'a misé sur l'issue réalisée, chacun est remboursé.
 */
function route_admin_cloture(int $pariId): array
{
    $issueId = entier(donnees()['issue_id'] ?? null, "Choisissez l'issue réalisée.");
    return transaction(function () use ($pariId, $issueId) {
        lire_pari_actif($pariId);
        if (!q('SELECT 1 FROM issues WHERE id = ? AND pari_id = ?', [$issueId, $pariId])->fetch()) {
            throw new ErreurApi("Cette issue n'appartient pas au pari.");
        }
        $mises = q('SELECT id, issue_id, montant FROM mises WHERE pari_id = ? ORDER BY id', [$pariId])->fetchAll();
        $gagnantes = [];
        foreach ($mises as $m) {
            if ((int)$m['issue_id'] === $issueId) $gagnantes[(int)$m['id']] = (int)$m['montant'];
        }
        $n = (int)q('SELECT COUNT(*) FROM issues WHERE pari_id = ?', [$pariId])->fetchColumn();
        $masse = array_sum(array_column($mises, 'montant'));
        $gains = $gagnantes ? repartir(a_verser($masse, array_sum($gagnantes), $n), $gagnantes) : [];
        foreach ($mises as $m) {
            $g = !$gagnantes && AMORCE === 0 ? (int)$m['montant'] : ($gains[(int)$m['id']] ?? 0);
            q('UPDATE mises SET gain = ? WHERE id = ?', [$g, $m['id']]);
        }
        q("UPDATE paris SET statut = 'clos', issue_gagnante_id = ?, clos_le = ? WHERE id = ?",
          [$issueId, maintenant(), $pariId]);
        return ['ok' => true];
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

function route_admin_suppression(int $pariId): array
{
    return transaction(function () use ($pariId) {
        lire_pari($pariId, true);
        if (q('SELECT 1 FROM mises WHERE pari_id = ?', [$pariId])->fetch()) {
            throw new ErreurApi('Impossible de supprimer un pari sur lequel des mises existent : annulez-le.', 409);
        }
        q('DELETE FROM issues WHERE pari_id = ?', [$pariId]);
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

    if ($methode === 'GET' && $route === 'etat') repondre(route_etat());
    if ($methode !== 'POST') throw new ErreurApi('Route inconnue.', 404);

    $publiques = [
        'inscription' => 'route_inscription',
        'connexion' => 'route_connexion',
        'deconnexion' => 'route_deconnexion',
        'mises' => 'route_mises',
        'paris' => 'route_creer_pari',
        'admin/connexion' => 'route_admin_connexion',
        'admin/deconnexion' => 'route_admin_deconnexion',
    ];
    if (isset($publiques[$route])) repondre($publiques[$route]());

    exiger_admin();
    if (preg_match('#^admin/paris/(\d+)/(maj|statut|cloture|annulation|suppression)$#', $route, $m)) {
        $f = ['maj' => 'route_admin_modifier', 'statut' => 'route_admin_statut', 'cloture' => 'route_admin_cloture',
              'annulation' => 'route_admin_annulation', 'suppression' => 'route_admin_suppression'][$m[2]];
        repondre($f((int)$m[1]));
    }
    throw new ErreurApi('Route inconnue.', 404);
} catch (ErreurApi $e) {
    repondre(['erreur' => $e->getMessage()], $e->getCode() ?: 400);
} catch (Throwable $e) {
    error_log('PLF paris : ' . $e);
    repondre(['erreur' => 'Erreur interne du serveur.' . (!empty(CONFIG['debug']) ? ' ' . $e->getMessage() : '')], 500);
}
