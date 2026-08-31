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

    'all_clean' => 'Everything mapped cleanly — there is nothing here for you to decide.',

    'nothing_staged' => 'This export held nothing to import — there is nothing here to confirm.',

    'groups' => [
        'conflict' => 'Needs your decision',
        'extra' => 'Not imported',
    ],

    'keep_or_take_aria' => 'Keep local or take source for :label',
    'keep_local' => 'Keep local',
    'take_source' => 'Take source',

    'footer_note' => 'This will create or update the counts shown above in your categories, budgets, and ledger.',
    'discard_button' => 'Discard import',
    'discard_confirm' => 'Discard this import? Everything read out of your export file is deleted here, and getting it back means uploading and parsing the whole file again. Nothing has reached your ledger yet.',
    'confirm_button' => 'Confirm import',
];
