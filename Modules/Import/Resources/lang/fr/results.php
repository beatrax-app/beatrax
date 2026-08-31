<?php

declare(strict_types=1);

return [
    'page_title' => 'Import terminé',
    'heading' => 'Import terminé',

    'summary' => ':count transaction importée|:count transactions importées',
    'summary_duplicates' => ' · :count doublon ignoré| · :count doublons ignorés',
    'summary_enriched' => ' · :count enrichies',
    'summary_errors' => ' · :count erreur| · :count erreurs',

    'show_duplicates' => 'Afficher les doublons ignorés (:count)',
    'duplicates_help' => 'Les doublons sont des lignes déjà présentes dans ton registre — elles sont ignorées sans bruit lors d\'un nouvel import.',
    'show_errors' => 'Afficher les erreurs (:count)',
    'errors_help' => 'Les erreurs sont des lignes qui n\'ont pas pu être analysées ; elles n\'ont pas été ajoutées à ton registre.',

    'upload_another' => 'Envoyer un autre relevé',

    'chain' => [
        'heading' => 'Résolution des chaînes…',
        'pending' => 'En file d\'attente. Le résolveur de chaînes va démarrer sous peu.',
        'running' => 'Liaison des chaînes de financement et décomposition des règlements du relevé.',
    ],

    'issues' => [
        'row' => 'Ligne :row : :reason',
        'file_stopped' => 'Le fichier n\'a pas pu être lu au-delà de la ligne :row. Rien après cette ligne n\'a été importé.',
        'file_none' => 'Le fichier n\'a pas pu être lu du tout.',
        'detail' => 'Le lecteur a signalé : :reason',
        'duplicate' => 'La ligne :row était déjà dans ton registre.',
        'more' => '+ :count non listées',
    ],
];
