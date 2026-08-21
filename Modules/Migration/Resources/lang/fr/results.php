<?php

declare(strict_types=1);

return [
    'page_title' => 'Import terminé',

    'heading_complete' => 'Import terminé',
    'heading_update' => 'Mise à jour appliquée',

    'summary_line' => ':categories, :budget_months et :transactions importés.',
    'summary_categories' => ':count catégorie|:count catégories',
    'summary_budget_months' => ':count mois de budget|:count mois de budget',
    'summary_transactions' => ':count transaction|:count transactions',
    'summary_attention' => ':count élément demande encore ton attention — voir ci-dessous.|:count éléments demandent encore ton attention — voir ci-dessous.',

    'stats' => [
        'category' => 'Catégories',
        'account' => 'Comptes',
        'payee' => 'Tiers',
        'transaction' => 'Transactions',
        'budget' => 'Mois budgétaires',
    ],

    'groups' => [
        'category' => 'Toujours pas importées — catégories',
        'payee' => 'Toujours pas importés — tiers',
        'extra' => 'Non importés',
        'conflict' => 'Ta décision est requise',
    ],

    'view_transactions' => 'Voir les transactions',
    'view_budgets' => 'Voir les budgets',
    'back' => 'Retour aux migrations',
];
