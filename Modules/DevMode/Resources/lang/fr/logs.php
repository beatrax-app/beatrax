<?php

declare(strict_types=1);

return [
    'heading' => 'Journaux',
    'subtitle' => 'Suivi en direct du fichier de log Laravel du jour, avec double expurgation à l\'écriture et au streaming.',
    'truncate' => 'Vider',
    'truncate_confirm' => 'Vider le fichier de log du jour ? C\'est irréversible.',
    'truncate_title' => 'Vide le fichier de log du jour (conserve l\'inode pour que le suivi reprenne proprement)',
    'filters_aria' => 'Filtres de log',
    'severity_aria' => 'Filtre de gravité',
    'channel_placeholder' => 'Filtre de canal…',
    'channel_aria' => 'Filtre de canal',
    'contains_placeholder' => 'Rechercher dans ce qui est affiché…',
    'contains_aria' => 'Filtre « contient »',
    'pause' => 'Pause',
    'resume' => 'Reprendre',
    'waiting' => 'En attente de lignes de log…',
    'copy' => 'Copier',
    'copy_title' => 'Copier l\'entrée complète',
    'copy_title_copied' => 'Copié',
    'copy_aria' => 'Copier l\'entrée de log',
    'copy_aria_copied' => 'Copié dans le presse-papiers',
    'dismiss' => 'Masquer',
    'dismiss_title' => 'Masquer de la vue (ne modifie pas le fichier de log)',
    'dismiss_aria' => 'Masquer l\'entrée de log de la vue',
    'totals' => [
        'showing' => 'Affichées',
        'of' => 'sur',
        'received' => 'reçues (tampon max 10k)',
        'lines_today' => 'lignes aujourd\'hui',
        'today' => 'aujourd\'hui',
        'across' => 'réparties sur',
        'daily_files' => 'fichiers quotidiens',
    ],

    'status' => [
        'poll_interrupted' => 'Lecture des logs interrompue. Nouvelle tentative…',
        'paused' => 'En pause.',
        'copy_failed_prefix' => 'Échec de la copie : ',
        'clipboard_unavailable' => 'presse-papiers indisponible',
    ],

    'toast' => [
        'truncated' => 'Log vidé — :size libérés.',
        'nothing' => 'Rien à vider.',
    ],
];
