<?php

declare(strict_types=1);

return [
    'page_title' => 'Import complete',

    'heading_complete' => 'Import complete',
    'heading_update' => 'Update applied',

    'summary_line' => 'Imported :categories, :budget_months, and :transactions.',
    'summary_categories' => ':count category|:count categories',
    'summary_budget_months' => ':count budget month|:count budget months',
    'summary_transactions' => ':count transaction|:count transactions',
    'summary_attention' => ':count item still needs attention — see below.|:count items still need attention — see below.',

    'stats' => [
        'category' => 'Categories',
        'account' => 'Accounts',
        'payee' => 'Counterparties',
        'transaction' => 'Transactions',
        'budget' => 'Budget months',
    ],

    'groups' => [
        'extra' => 'Not imported',
        'conflict' => 'Needs your decision',
    ],

    'view_transactions' => 'View transactions',
    'view_budgets' => 'View budgets',
    'back' => 'Back to migrations',
];
