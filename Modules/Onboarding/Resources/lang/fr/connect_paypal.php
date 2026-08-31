<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Ton compte PayPal',
    'h1' => 'Connecte ton compte PayPal',

    'lede_html' => 'Dépose ton export d’activité PayPal — une ligne par transaction, pas le récapitulatif de solde. PayPal nomme ses rapports dans la langue de ton compte, et pour l’instant nous lisons la paire néerlandaise : <em lang="nl">Rapport Transactiegegevens</em>, pas <span lang="nl">Saldorapport</span>. Si le tien sort dans une autre langue, bascule PayPal en néerlandais avant de le télécharger.',

    'format_group_aria' => 'PayPal n\'exporte qu\'en CSV',
    'got_it_as' => 'Reçu au format :',
    'badge_only_format' => 'seul format',

    'mini' => [
        'login_label' => 'Se connecter',
        'custom_label' => 'Relevés personnalisés',
        'range_label' => 'Choisis une période',
        'range_sub' => 'Les 12 derniers mois',
        'download_label' => 'Télécharger en CSV',
    ],

    'drop_lead' => 'Dépose ici ton export d’activité',
    'browse_file' => 'ou parcours tes fichiers',

    'file_ready' => '· ✓ prêt',

    'skip' => 'Passer cette étape',
    'continue' => 'Continuer →',

    'errors' => [
        'required' => 'Dépose d’abord ton export d’activité PayPal dans la zone.',
        'max' => 'Ce fichier est trop volumineux. Un export d’activité PayPal fait normalement bien moins de 10 Mo.',
        'extensions' => 'Ce fichier ne ressemble pas à un CSV PayPal. Télécharge l’export d’activité — une ligne par transaction, pas le récapitulatif de solde — en CSV.',
        'unreadable' => 'Impossible de lire ce fichier. L\'erreur complète est dans /dev/logs.',
    ],
];
