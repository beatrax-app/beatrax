<?php

declare(strict_types=1);

return [
    'page_title' => 'Preview import',
    'heading' => 'Preview import',
    'discard' => 'Discard import',
    'confirm' => 'Confirm import',
    'subtitle' => 'Review the parsed rows. Nothing is saved to your ledger until you confirm.',

    'expired_html' => 'The preview has expired. <a href="/imports/new" class="underline">Re-upload the file</a> to try again.',

    'save_name' => 'Save name',
    'account_name_label' => 'Account name',
    'account_placeholder' => 'e.g. Main savings account',
    'rename_aria' => 'Rename this counterparty',

    'unknown_iban_prefix' => 'We found an unfamiliar IBAN:',
    'unknown_iban_suffix' => 'Name this account.',

    'ics' => [
        'heading' => 'Name your ICS card account.',
        'help' => "This is the first time you've imported ICS data. Give this card a name so it shows up consistently across the app.",
        'placeholder' => 'e.g. ICS card',
    ],

    'paypal' => [
        'heading' => 'Name your PayPal account.',
        'help' => "This is the first time you've imported PayPal data. Give this wallet a name so it shows up consistently across the app.",
        'placeholder' => 'e.g. PayPal',
    ],

    'col_date' => 'Date',
    'col_funding_source' => 'Funding source',
    'col_counterparty' => 'Counterparty',
    'col_amount' => 'Amount',
    'col_status' => 'Status',

    'status' => [
        'new' => 'New',
        'new_title' => 'Will be added to your ledger.',
        'duplicate' => 'Duplicate',
        'duplicate_title' => 'Already imported — will be skipped.',
        'enriched' => 'Enriched',
        'enriched_title' => 'Existing row will be updated with a stronger source reference.',
        'error' => 'Error',
    ],

    'chain' => [
        'heading' => 'Resolving chains…',
        'pending' => 'Queued. The chain resolver will start shortly.',
        'running' => 'Linking funding chains and decomposing statement settlements.',
        'failed_prefix' => 'Chain resolution failed:',
        'unknown_error' => 'an unknown error occurred',
        'open_horizon' => 'Open Horizon',
        'failed_suffix' => 'to retry or inspect.',
    ],

    'errors' => [
        'iban_not_in_preview' => 'This IBAN is not part of the current preview.',
    ],
];
