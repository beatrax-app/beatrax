<?php

declare(strict_types=1);

return [
    'search_placeholder' => 'Tape pour chercher des vues, des commandes et des actions. Appuie sur Esc pour fermer.',
    'search_aria' => 'Tape pour chercher des vues, des commandes et des actions',
    'dialog_aria' => 'Palette de commandes',
    'token_suggest_aria' => 'Suggestions de tokens',
    'rail_view' => 'Vue',
    'rail_dev' => 'Dev',
    'rail_action' => 'Action',
    'rail_recent' => 'Récent',
    'no_recent' => 'Pas encore de choix récents.',
    'section_transactions' => 'Transactions',
    'section_counterparties' => 'Tiers',
    'section_categories' => 'Catégories',
    'section_goals_recurring' => 'Objectifs et récurrences',
    'no_name' => '(sans nom)',
    'see_all' => 'Voir le :count résultat →|Voir les :count résultats →',
    'no_transactions' => 'Aucune transaction ne correspond à ":query"',
    'source_txn' => 'txn',
    'source_counterparty' => 'tiers',
    'source_category' => 'catégorie',
    'results_aria' => 'Résultats',
    'no_results' => 'Aucun résultat.',
    'foot_navigate' => 'naviguer',
    'foot_select' => 'sélectionner',
    'foot_close' => 'fermer',
    'close_aria' => 'Fermer la recherche',
    'close_caption' => 'Fermer',
    'foot_try' => 'Essaie',
    'results' => ':count résultat|:count résultats',

    'action' => [
        'run_import' => ['label' => 'Lancer un import', 'hint' => 'Ouvrir l\'assistant d\'import'],
        'scan_email' => ['label' => 'Analyser les e-mails maintenant', 'hint' => 'Lancer immédiatement la synchronisation de la boîte de réception'],
        'open_profile' => ['label' => 'Ouvrir le profil', 'hint' => 'Paramètres — compte et préférences'],
        'toggle_theme' => ['label' => 'Changer de thème', 'hint' => 'Basculer entre le thème clair et le thème sombre'],
    ],

    'run_command' => 'Lancer :command',

    'nav' => [
        'overview' => ['label' => 'Aperçu dev', 'hint' => 'Tuiles système + exécutions récentes'],
        'artisan' => ['label' => 'Runner Artisan', 'hint' => 'Lancer les commandes autorisées'],
        'audit' => ['label' => "Journal d'audit dev", 'hint' => 'Chaque action du mode dev'],
        'logs' => ['label' => 'Suivi des journaux', 'hint' => 'Flux en direct de laravel-*.log'],
        'queue' => ['label' => 'Inspecteur de file', 'hint' => 'En attente / échoués / lots'],
        'doctor' => ['label' => 'Doctor', 'hint' => 'Sondes système'],
        'sql' => ['label' => 'Panneau SQL', 'hint' => 'Navigateur SELECT uniquement'],
        'system' => ['label' => 'Instantané du système', 'hint' => 'Environnement + chemins + configuration'],
        'horizon' => ['label' => 'Horizon', 'hint' => 'Tableau de bord de file intégré'],
        'sync_health' => ['label' => 'État de la sync', 'hint' => 'Opérations de fusion en quarantaine ou ignorées'],
    ],
];
