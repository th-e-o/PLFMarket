<?php
// Copiez ce fichier en config.php et renseignez vos paramètres.
return [
    // Mot de passe de l'administration (…/#admin). Choisissez-le long.
    'admin_password' => 'changez-moi',

    // Clochettes de départ par joueur.
    'capital' => 1000,

    // Mise fictive de la banque sur chaque issue d'un pari : rend les cotes attrayantes dès l'ouverture
    // (×2 sur un Oui/Non) et les stabilise tant qu'il y a peu de mises. 0 : pari mutuel pur.
    'amorce' => 100,

    // Base de données : par défaut, un fichier SQLite dans data/ (rien à configurer).
    // Pour utiliser MySQL à la place (espace client OVH → Hébergements → Bases de données) :
    // 'db' => [
    //     'dsn' => 'mysql:host=VOTRE_SERVEUR.mysql.db;dbname=VOTRE_BASE;charset=utf8mb4',
    //     'user' => 'VOTRE_UTILISATEUR',
    //     'password' => 'VOTRE_MOT_DE_PASSE',
    // ],

    // true : crée 7 paris d'illustration à l'installation (pour une démonstration). En production : false.
    'exemples' => false,

    // true : affiche le détail des erreurs serveur (à désactiver en production).
    'debug' => false,
];
