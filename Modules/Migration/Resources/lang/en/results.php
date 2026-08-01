<?php

declare(strict_types=1);

return [
    'page_title' => 'Import complete',

    'heading_complete' => 'Import complete',
    'heading_update' => 'Update applied',

    'summary_line' => 'Imported :categories categories, :budget_months budget months, and :transactions transactions.',
    'summary_attention' => ':count items still need attention — see below.',

    'stats' => [
        'category' => 'Categories',
        'account' => 'Accounts',
        'payee' => 'Counterparties',
        'transaction' => 'Transactions',
        'budget' => 'Budget months',
    ],

    'groups' => [
        'category' => 'Still not imported — categories',
        'payee' => 'Still not imported — payees',
        'extra' => 'Not imported',
        'conflict' => 'Needs your decision',
    ],

    'view_transactions' => 'View transactions',
    'view_budgets' => 'View budgets',
    'back' => 'Back to migrations',
];
