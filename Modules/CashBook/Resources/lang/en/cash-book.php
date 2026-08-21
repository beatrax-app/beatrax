<?php

declare(strict_types=1);

return [
    'page_title' => 'Cash book',
    'heading' => 'Cash book',
    'intro' => 'Record cash and other off-bank spending by hand. Manual entries flow into the same ledger as your imports — they categorise, recur-detect, and count toward your month.',

    'direction' => 'Direction',
    'expense' => 'Expense',
    'income' => 'Income',

    'amount' => 'Amount (€)',
    'date' => 'Date',
    'counterparty' => 'Counterparty',
    'counterparty_placeholder' => 'e.g. Bakery',
    'category' => 'Category',
    'optional' => '(optional)',
    'uncategorized' => 'Uncategorized',
    'note' => 'Note',

    'add_entry' => 'Add entry',
    'manual_entries' => 'Manual entries',
    'no_entries' => 'No manual entries yet.',
    'delete_entry' => 'Delete entry',
    'delete' => 'Delete',
    'delete_confirm' => 'Delete this entry?',
    'delete_keep' => 'Keep',

    'errors' => [
        'amount_positive' => 'Enter an amount greater than zero.',
        'invalid_date' => 'Enter a valid date.',
    ],

    'toast' => [
        'added' => 'Cash entry added.',
        'removed' => 'Cash entry removed.',
    ],
];
