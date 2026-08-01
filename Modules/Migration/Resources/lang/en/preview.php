<?php

declare(strict_types=1);

return [
    'page_title' => 'Preview import',

    'heading' => 'Preview import',
    'subtitle' => 'Review what will change. Nothing is saved until you confirm.',

    'stats' => [
        'category' => 'Categories',
        'account' => 'Accounts',
        'payee' => 'Counterparties',
        'transaction' => 'Transactions',
        'budget' => 'Budget months',
    ],

    'fully_mapped' => '✓ fully mapped',
    'all_clean' => 'Everything mapped cleanly — nothing needs your attention before you confirm.',

    'groups' => [
        'conflict' => 'Needs your decision',
        'category' => 'Unresolved categories',
        'payee' => 'Unresolved payees',
        'extra' => 'Not imported',
    ],

    'keep_or_take_aria' => 'Keep local or take source for :label',
    'keep_local' => 'Keep local',
    'take_source' => 'Take source',

    'footer_note' => 'This will create or update the counts shown above in your categories, budgets, and ledger.',
    'discard_button' => 'Discard import',
    'confirm_button' => 'Confirm import',
];
