<?php

declare(strict_types=1);

return [
    'page_title' => 'Cash book',
    'heading' => 'Cash book',
    'intro' => 'Record cash and other off-bank spending by hand. Manual entries flow into the same ledger as your imports — they categorise, resolve their counterparty, recur-detect, and count toward your month.',

    'direction' => 'Direction',
    'expense' => 'Expense',
    'income' => 'Income',

    'amount' => 'Amount (:symbol)',
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
    'delete_entry_caption' => 'Delete',
    'delete' => 'Delete',
    'delete_confirm' => 'Delete this entry?',
    'delete_keep' => 'Keep',

    'errors' => [
        'amount_positive' => 'Enter an amount greater than zero.',
        'amount_too_large' => 'That amount is too large. Check the digits.',
        'amount_unreadable' => 'That amount could not be read. Enter it with at most :decimals decimal place, for example :example.|That amount could not be read. Enter it with at most :decimals decimal places, for example :example.',
        'amount_unreadable_whole' => 'That amount could not be read. This currency has no decimals, so enter a whole number, for example :example.',
        'invalid_date' => 'Enter a valid date.',
        'not_recorded' => 'That entry was not recorded. Try adding it again.',
    ],

    'toast' => [
        'added' => 'Cash entry added.',
        'removed' => 'Cash entry removed.',
        'reconciled_locked' => 'This transaction is reconciled. Un-reconcile it to make changes.',
    ],
];
