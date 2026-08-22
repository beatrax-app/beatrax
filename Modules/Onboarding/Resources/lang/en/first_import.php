<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Review & commit',
    'h1' => 'Review everything we found',

    'lede_counts' => ':transactions across :sources.',
    'source' => ':count source|:count sources',
    'lede_confirm' => 'Confirm your starting balances, then commit.',

    'empty' => 'Nothing to review yet. Drop a statement on the earlier steps to see your transactions here.',

    'sb_eyebrow_label' => '🧮 STARTING BALANCES ·',
    'account_detected' => ':count ACCOUNT DETECTED|:count ACCOUNTS DETECTED',
    'sb_lede' => 'We detected the starting balance for each account. Confirm or edit before we commit.',

    'txn' => ':count transaction|:count transactions',
    'to_commit' => 'to commit ·',
    'already_imported' => ':count already imported|:count already imported',
    'commit_committing' => 'Committing…',
    'commit_count' => 'Commit everything (:count transaction) →|Commit everything (:count transactions) →',
    'commit_empty' => 'Commit everything (—) →',
    'skip' => 'Skip for now',

    'errors' => [
        'nothing_to_commit' => 'Nothing to commit.',
        'commit_failed' => "We couldn't commit your statements. Nothing was changed — try again.",
    ],

    'section' => [
        'from_prefix' => 'FROM ',
        'from_bank' => 'FROM YOUR BANK STATEMENT',
        'from_ics' => 'FROM YOUR ICS CARD STATEMENTS',
        'from_paypal' => 'FROM PAYPAL',
        'row' => ':count ROW|:count ROWS',
        'badge_ready' => '✓ READY',
        'badge_empty' => 'EMPTY',
        'badge_error' => 'NEEDS RE-UPLOAD',
        'error_body' => "We couldn't read all of the files for this source. Try a different file →",
        'partial_body' => 'Some of this file could not be read and was left out: :reason',
        'empty_body' => 'This statement is empty.',
        'col_date' => 'Date',
        'col_type' => 'Type',
        'col_counterparty' => 'Counterparty',
        'col_amount' => 'Amount',
        'load_more' => 'Load more (:remaining remaining)',
        'rows_shown' => ':count row shown|:count rows shown',
    ],
];
