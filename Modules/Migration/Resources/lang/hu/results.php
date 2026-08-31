<?php

declare(strict_types=1);

return [
    'page_title' => 'Import kész',

    'heading_complete' => 'Import kész',
    'heading_update' => 'Frissítés alkalmazva',

    'summary_line' => ':categories, :budget_months és :transactions importálva.',
    'summary_categories' => ':count kategória|:count kategória',
    'summary_budget_months' => ':count költségvetési hónap|:count költségvetési hónap',
    'summary_transactions' => ':count tranzakció|:count tranzakció',
    'summary_attention' => ':count elem továbbra is figyelmet igényel — lásd alább.|:count elem továbbra is figyelmet igényel — lásd alább.',

    'stats' => [
        'category' => 'Kategóriák',
        'account' => 'Számlák',
        // i18n-review: hu · stats.payee — the count is payees the import
        // linked, not ones it created; check the participle agrees here.
        'payee' => 'Összekapcsolt partnerek',
        'transaction' => 'Tranzakciók',
        'budget' => 'Költségvetési hónapok',
    ],

    'groups' => [
        'extra' => 'Nem importálva',
        'conflict' => 'Döntést igényel',
    ],

    'view_transactions' => 'Tranzakciók megtekintése',
    'view_budgets' => 'Költségvetések megtekintése',
    'back' => 'Vissza a migrálásokhoz',
];
