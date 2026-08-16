<?php

declare(strict_types=1);

return [
    'welcome' => [
        'page_title' => 'Bienvenue',
        'heading' => 'Bienvenue dans Beatrax',
        'subtitle' => 'Ton tableau de bord financier 100 % local est prêt. Crée ton premier compte pour commencer.',
        'get_started' => 'Commencer',
    ],

    'setup' => [
        'page_title' => 'Configuration…',
        'pending_heading' => 'Configuration…',
        'pending_body' => 'Beatrax prépare tes données. Ça ne prend qu\'un instant.',
        'failed_body' => 'La configuration n\'a pas pu se terminer. Redémarre Beatrax ; si ça échoue encore, le journal indique la raison.',
        'ready_heading' => 'Prêt',
        'ready_body' => 'Configuration terminée. On continue…',
    ],

    'staging' => [
        'page_title' => 'Fichier reçu',
        'heading_prefix' => 'Fichier reçu : ',
        'button_label' => 'Démarrer l\'import',
        'csv_subtitle' => 'Un export bancaire ou PayPal — démarre l\'import pour prévisualiser et confirmer.',
        'eml_subtitle' => 'Un reçu par e-mail — démarre l\'import pour l\'associer à sa transaction.',
        'empty_heading' => 'Impossible d\'ouvrir ce fichier',
        'empty_body' => 'Beatrax n\'a pas pu lire le fichier que tu as ouvert. Essaie plutôt de l\'importer depuis la page Imports.',
        'open_imports' => 'Ouvrir les imports',
    ],

    'close' => [
        'title' => 'Garder Beatrax actif ?',
        'body' => 'Fermer la fenêtre peut soit quitter Beatrax complètement, soit le laisser tourner discrètement dans la barre de menus pour que les analyses d\'e-mails planifiées continuent.',
        'button_quit' => 'Quitter Beatrax',
        'button_keep_in_tray' => 'Laisser tourner en arrière-plan',
        'checkbox_remember' => 'Retenir mon choix',
    ],
];
