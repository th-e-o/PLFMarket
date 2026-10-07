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

// Paris d'exemple : libellés et probabilités (%) à ajuster par l'administrateur.
const PARIS_EXEMPLES = [
    ['Procédure', "Le Gouvernement engagera-t-il sa responsabilité (art. 49.3) sur le PLF ?",
     "Sur au moins une partie du texte, à n'importe quelle lecture.",
     [['Oui', 60], ['Non', 40]]],
    ['Vote', "Une motion de censure sera-t-elle adoptée pendant l'examen du PLF ?",
     "Motion déposée en réponse à un 49.3 ou motion spontanée (art. 49.2).",
     [['Oui', 20], ['Non', 80]]],
    ['Vote', "La première partie (recettes) sera-t-elle adoptée par l'Assemblée en 1re lecture ?",
     "Vote sur l'ensemble de la première partie, hors 49.3.",
     [['Oui', 35], ['Non', 65]]],
    ['Procédure', "La commission mixte paritaire (CMP) sera-t-elle conclusive ?",
     "« Non » inclut le cas où aucune CMP ne se réunit.",
     [['Oui', 50], ['Non', 50]]],
    ['Calendrier', "Quand la loi de finances sera-t-elle promulguée ?",
     "Date de publication au Journal officiel.",
     [['Avant le 20 décembre', 25], ['Entre le 20 et le 31 décembre', 45],
      ['Après le 31 décembre (loi spéciale)', 30]]],
    ['Conseil constitutionnel', "Le Conseil constitutionnel censurera-t-il au moins une disposition ?",
     "Censure totale ou partielle, cavaliers budgétaires compris.",
     [['Oui', 85], ['Non', 15]]],
    ['Chiffres', "Combien d'amendements seront déposés en séance à l'Assemblée en 1re lecture ?",
     "Total première et seconde parties, tel que publié par l'Assemblée.",
     [['Moins de 3 000', 30], ['De 3 000 à 5 000', 45], ['Plus de 5 000', 25]]],
];

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

function initialiser_base(PDO $pdo): void
{
    try {
        $pdo->query('SELECT 1 FROM mises LIMIT 1');
        return; // déjà installée
    } catch (PDOException) {
    }
    foreach (array_filter(array_map('trim', explode(';', est_sqlite() ? SCHEMA_SQLITE : SCHEMA_MYSQL))) as $ordre) {
        $pdo->exec($ordre);
    }
    if (($pdo->query('SELECT COUNT(*) FROM paris')->fetchColumn() == 0) && (CONFIG['exemples'] ?? true)) {
        foreach (PARIS_EXEMPLES as [$categorie, $titre, $description, $issues]) {
            q('INSERT INTO paris (titre, description, categorie, cree_le) VALUES (?, ?, ?, ?)',
              [$titre, $description, $categorie, maintenant()]);
            $pariId = (int)$pdo->lastInsertId();
            foreach ($issues as $n => [$libelle, $pct]) {
                q('INSERT INTO issues (pari_id, libelle, probabilite, ordre) VALUES (?, ?, ?, ?)',
                  [$pariId, $libelle, $pct / 100, $n]);
            }
        }
    }
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

/** Cote en centièmes : l'inverse de la probabilité, au minimum 1,01. */
function cote_c(float $probabilite): int
{
    return max(101, (int)round(100 / $probabilite));
}

function gain(int $montant, int $coteC): int
{
    return intdiv($montant * $coteC, 100);
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
               COUNT(CASE WHEN p.statut = 'clos' AND m.gain > 0 THEN 1 END) AS paris_gagnes
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

/** Probabilité saisie en pourcentage (ex. 35) → fraction (0,35). */
function lire_probabilite(mixed $valeur): float
{
    $pct = str_replace(',', '.', trim((string)$valeur));
    if (!is_numeric($pct) || $pct <= 0 || $pct >= 100) {
        throw new ErreurApi('Chaque probabilité doit être strictement comprise entre 0 et 100 %.');
    }
    return (float)$pct / 100;
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

    $issuesParPari = [];
    foreach (q('
        SELECT i.id, i.pari_id, i.libelle, i.probabilite,
               (SELECT COALESCE(SUM(montant), 0) FROM mises WHERE issue_id = i.id) AS total_mise,
               (SELECT COUNT(DISTINCT joueur_id) FROM mises WHERE issue_id = i.id) AS nb_joueurs,
               (SELECT COALESCE(SUM(montant * cote_c), 0) FROM mises WHERE issue_id = i.id) AS a_verser_c
        FROM issues i ORDER BY i.pari_id, i.ordre, i.id') as $i) {
        $issuesParPari[$i['pari_id']][] = [
            'id' => (int)$i['id'],
            'libelle' => $i['libelle'],
            'probabilite' => (float)$i['probabilite'],
            'cote' => cote_c((float)$i['probabilite']) / 100,
            'total_mise' => (int)$i['total_mise'],
            'nb_joueurs' => (int)$i['nb_joueurs'],
            'a_verser' => intdiv((int)$i['a_verser_c'], 100),
        ];
    }

    $paris = [];
    foreach (q("
        SELECT p.*, (SELECT COUNT(DISTINCT joueur_id) FROM mises WHERE pari_id = p.id) AS nb_joueurs
        FROM paris p
        ORDER BY CASE p.statut WHEN 'ouvert' THEN 0 WHEN 'suspendu' THEN 1 ELSE 2 END,
                 CASE WHEN p.statut IN ('clos', 'annule') THEN p.clos_le END DESC,
                 p.date_limite IS NULL, p.date_limite, p.id") as $p) {
        $issues = $issuesParPari[$p['id']] ?? [];
        $paris[] = [
            'id' => (int)$p['id'],
            'titre' => $p['titre'],
            'description' => $p['description'],
            'categorie' => $p['categorie'],
            'statut' => $p['statut'],
            'accepte_mises' => accepte_les_mises($p),
            'date_limite' => $p['date_limite'],
            'issue_gagnante_id' => $p['issue_gagnante_id'] === null ? null : (int)$p['issue_gagnante_id'],
            'clos_le' => $p['clos_le'],
            'total_mise' => array_sum(array_column($issues, 'total_mise')),
            'nb_joueurs' => (int)$p['nb_joueurs'],
            'issues' => $issues,
        ];
    }

    $mesMises = [];
    if ($moi) {
        foreach (q('
            SELECT m.id, m.pari_id, m.issue_id, m.montant, m.cote_c, m.gain, m.cree_le,
                   p.titre AS pari_titre, p.statut AS statut_pari, i.libelle AS issue_libelle
            FROM mises m JOIN paris p ON p.id = m.pari_id JOIN issues i ON i.id = m.issue_id
            WHERE m.joueur_id = ? ORDER BY m.cree_le DESC, m.id DESC', [$moi['id']]) as $m) {
            $mesMises[] = [
                'id' => (int)$m['id'], 'pari_id' => (int)$m['pari_id'], 'issue_id' => (int)$m['issue_id'],
                'montant' => (int)$m['montant'], 'cote' => $m['cote_c'] / 100,
                'gain' => $m['gain'] === null ? null : (int)$m['gain'], 'cree_le' => $m['cree_le'],
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
        'maintenant' => maintenant(),
        'admin' => !empty($_SESSION['admin']),
        'moi' => $monClassement,
        'classement' => $joueurs,
        'paris' => $paris,
        'mes_mises' => $mesMises,
        'fil' => fil_actualite(),
    ];
}

function fil_actualite(int $limite = 20): array
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
               (SELECT COUNT(*) FROM mises WHERE pari_id = p.id AND gain > 0) AS nb_gagnants,
               (SELECT COALESCE(SUM(gain), 0) FROM mises WHERE pari_id = p.id) AS distribue
        FROM paris p LEFT JOIN issues i ON i.id = p.issue_gagnante_id
        WHERE p.statut IN ('clos', 'annule')
        ORDER BY p.clos_le DESC LIMIT $limite") as $p) {
        $evenements[] = ['date' => $p['clos_le'], 'type' => $p['statut'], 'pari' => $p['titre'],
                         'issue' => $p['libelle'], 'nb_gagnants' => (int)$p['nb_gagnants'],
                         'distribue' => (int)$p['distribue']];
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
        $coteC = cote_c((float)$issue['probabilite']);
        q('INSERT INTO mises (joueur_id, pari_id, issue_id, montant, cote_c, cree_le) VALUES (?, ?, ?, ?, ?, ?)',
          [$moi['id'], $pari['id'], $issueId, $montant, $coteC, maintenant()]);
        return ['ok' => true, 'cote' => $coteC / 100, 'gain_potentiel' => gain($montant, $coteC)];
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

function route_admin_creer_pari(): array
{
    $d = donnees();
    $titre = trim((string)($d['titre'] ?? ''));
    if ($titre === '') throw new ErreurApi('Le titre est obligatoire.');
    $issues = array_values(array_filter($d['issues'] ?? [], fn($i) => trim((string)($i['libelle'] ?? '')) !== ''));
    if (count($issues) < 2) throw new ErreurApi('Il faut au moins deux issues.');
    $probas = array_map(fn($i) => lire_probabilite($i['probabilite'] ?? null), $issues);
    $dateLimite = lire_date_limite($d['date_limite'] ?? null);

    return transaction(function () use ($d, $titre, $issues, $probas, $dateLimite) {
        q('INSERT INTO paris (titre, description, categorie, date_limite, cree_le) VALUES (?, ?, ?, ?, ?)',
          [$titre, trim((string)($d['description'] ?? '')), trim((string)($d['categorie'] ?? '')), $dateLimite, maintenant()]);
        $pariId = (int)db()->lastInsertId();
        foreach ($issues as $n => $i) {
            q('INSERT INTO issues (pari_id, libelle, probabilite, ordre) VALUES (?, ?, ?, ?)',
              [$pariId, trim((string)$i['libelle']), $probas[$n], $n]);
        }
        return ['ok' => true, 'id' => $pariId];
    });
}

/** Modifie la date limite et les probabilités (les mises déjà faites gardent leur cote). */
function route_admin_modifier(int $pariId): array
{
    $d = donnees();
    return transaction(function () use ($d, $pariId) {
        lire_pari_actif($pariId);
        if (array_key_exists('date_limite', $d)) {
            q('UPDATE paris SET date_limite = ? WHERE id = ?', [lire_date_limite($d['date_limite']), $pariId]);
        }
        foreach (($d['probabilites'] ?? []) as $issueId => $pct) {
            q('UPDATE issues SET probabilite = ? WHERE id = ? AND pari_id = ?',
              [lire_probabilite($pct), (int)$issueId, $pariId]);
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

/** Déclare l'issue réalisée et verse les gains (mise × cote) aux gagnants. */
function route_admin_cloture(int $pariId): array
{
    $issueId = entier(donnees()['issue_id'] ?? null, "Choisissez l'issue réalisée.");
    return transaction(function () use ($pariId, $issueId) {
        lire_pari_actif($pariId);
        if (!q('SELECT 1 FROM issues WHERE id = ? AND pari_id = ?', [$issueId, $pariId])->fetch()) {
            throw new ErreurApi("Cette issue n'appartient pas au pari.");
        }
        foreach (q('SELECT id, issue_id, montant, cote_c FROM mises WHERE pari_id = ?', [$pariId])->fetchAll() as $m) {
            $g = (int)$m['issue_id'] === $issueId ? gain((int)$m['montant'], (int)$m['cote_c']) : 0;
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
        'admin/connexion' => 'route_admin_connexion',
        'admin/deconnexion' => 'route_admin_deconnexion',
    ];
    if (isset($publiques[$route])) repondre($publiques[$route]());

    exiger_admin();
    if ($route === 'admin/paris') repondre(route_admin_creer_pari());
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
