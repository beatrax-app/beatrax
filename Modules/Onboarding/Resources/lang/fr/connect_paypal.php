<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Ton compte PayPal',
    'h1' => 'Connecte ton compte PayPal',

    'lede_html' => 'Dépose ton export PayPal du détail des transactions — appelé <em lang="nl">Rapport Transactiegegevens</em> dans un compte PayPal néerlandais. Le rapport de solde (<span lang="nl">Saldorapport</span>) ne convient pas — il nous faut les données événement par événement.',

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

    'drop_lead' => 'Dépose ici ton CSV du détail des transactions',
    'browse_file' => 'ou parcours tes fichiers',

    'file_ready' => '· ✓ prêt',

    'skip' => 'Passer cette étape',
    'continue' => 'Continuer →',

    'errors' => [
        'required' => 'Dépose d\'abord ton CSV PayPal Rapport Transactiegegevens dans la zone.',
        'max' => 'Ce fichier est trop volumineux. Les exports PayPal Rapport Transactiegegevens font normalement bien moins de 10 Mo.',
        'extensions' => 'Ce fichier ne ressemble pas à un CSV PayPal. Télécharge Rapport Transactiegegevens (et non le rapport de solde Saldorapport) en CSV depuis PayPal.',
        'unreadable' => 'Impossible de lire ce fichier. L\'erreur complète est dans /dev/logs.',
    ],
];
