<?php
// Génère config.php à partir des variables d'environnement (secrets GitHub) lors du déploiement.
$admin = getenv('PLF_ADMIN_PASSWORD');
if (!$admin) {
    fwrite(STDERR, "Le secret PLF_ADMIN_PASSWORD n'est pas défini dans le dépôt GitHub.\n");
    exit(1);
}
$config = [
    'admin_password' => $admin,
    'capital' => (int)(getenv('PLF_CAPITAL') ?: 1000),
    'exemples' => true,
    'debug' => false,
];
if ($dsn = getenv('PLF_DB_DSN')) { // facultatif : MySQL au lieu de SQLite
    $config['db'] = ['dsn' => $dsn, 'user' => getenv('PLF_DB_USER') ?: null, 'password' => getenv('PLF_DB_PASSWORD') ?: null];
}
file_put_contents(__DIR__ . '/../config.php',
    "<?php\n// Généré par le déploiement GitHub : ne pas modifier sur le serveur.\nreturn " . var_export($config, true) . ";\n");
echo "config.php généré (base : " . ($dsn ? 'MySQL' : 'SQLite') . ").\n";
