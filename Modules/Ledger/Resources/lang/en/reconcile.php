<?php

declare(strict_types=1);

return [
    'page_title' => 'Reconcile',
    'heading' => 'Reconcile',
    'intro' => "Confirm an account's statement balance against your cleared transactions. When they match, complete the reconcile to lock those rows in place.",

    'account' => 'Account',
    'choose_account' => 'Choose an account…',
    'statement_date' => 'Statement date',
    'statement_balance' => 'Statement balance (:symbol)',
    'balance_help' => 'Pre-filled from your latest imported statement when available — negative for money owed, editable either way.',

    'cleared_balance' => 'Cleared balance',
    'statement_target' => 'Statement target',
    'difference' => 'Difference',

    'pill' => [
        'choose_account' => 'choose an account',
        'choose_date' => 'choose a statement date',
        'enter_balance' => 'enter a statement balance',
        'matched' => 'matched — :amount',
        'discrepancy' => 'discrepancy — :amount',
        'reconciled_through' => 'reconciled through :date',
    ],

    'mismatch_html' => 'The statement balance doesn\'t match your cleared balance yet. Toggle cleared rows on the <a href=":url" class="underline">transactions list</a> or adjust the entered balance until the difference reaches zero — this flow never creates a balancing entry.',
    'unreachable_no_baseline_html' => 'No toggling of rows can bring this difference to zero. This account has no opening balance recorded, so its balance is measured from zero. Import the statement the account opens with, or set the opening balance in <a href=":url" class="underline">Settings</a>.',
    'unreachable' => 'No toggling of rows can bring this difference to zero: it lies outside the range of every row on this account up to the date given. Check the statement date and the balance you entered.',

    'check' => 'Check',
    'complete' => 'Complete reconcile',
    'complete_unavailable' => 'Nothing left to lock up to this date — toggle more rows cleared, or choose a later statement date.',

    'errors' => [
        'choose_account' => 'Choose an account first.',
        'invalid_balance_date' => 'Enter a valid statement balance and date.',
        'mismatch' => 'The statement balance does not match the cleared balance yet — adjust cleared rows or the entered balance until the difference is zero.',
    ],

    'toast' => [
        'nothing_to_lock' => 'Nothing to lock for this statement date.',
        'complete' => 'Reconcile complete — :count row locked.|Reconcile complete — :count rows locked.',
    ],
];
