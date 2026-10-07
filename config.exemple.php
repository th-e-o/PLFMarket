<?php
// Copiez ce fichier en config.php et renseignez vos paramètres.
return [
    // Mot de passe de l'administration (…/#admin). Choisissez-le long.
    'admin_password' => 'changez-moi',

    // Clochettes de départ par joueur.
    'capital' => 1000,

    // Base de données : par défaut, un fichier SQLite dans data/ (rien à configurer).
    // Pour utiliser MySQL à la place (espace client OVH → Hébergements → Bases de données) :
    // 'db' => [
    //     'dsn' => 'mysql:host=VOTRE_SERVEUR.mysql.db;dbname=VOTRE_BASE;charset=utf8mb4',
    //     'user' => 'VOTRE_UTILISATEUR',
    //     'password' => 'VOTRE_MOT_DE_PASSE',
    // ],

    // Crée les 7 paris d'exemple lors de la première installation.
    'exemples' => true,

    // true : affiche le détail des erreurs serveur (à désactiver en production).
    'debug' => false,
];
