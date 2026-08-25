<?php

declare(strict_types=1);

return [
    'page_title' => 'Preview import',
    'heading' => 'Preview import',
    'discard' => 'Discard import',
    'confirm' => 'Confirm import',
    'subtitle' => 'Review the parsed rows. Nothing is saved to your ledger until you confirm.',

    'already_imported' => 'This file has already been imported.',

    'already_imported_link' => 'View the import result',

    'expired_html' => 'The preview has expired. <a href="/imports/new" class="underline">Re-upload the file</a> to try again.',

    'save_name' => 'Save name',
    'account_name_label' => 'Account name',
    'account_placeholder' => 'e.g. Main savings account',
    'rename_aria' => 'Rename this counterparty',

    'unknown_iban_prefix' => 'We found an unfamiliar IBAN:',

    'unknown_account_prefix' => 'We found an unfamiliar account:',
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
        'failed_detail' => 'the details are in the job log',
        'open_horizon' => 'Open Horizon',
        'failed_suffix' => 'to retry or inspect.',
    ],

    'errors' => [
        'app_locked' => 'Unlock the app to import: the encryption keys cannot be used while it is locked.',
        'file_unreadable' => 'This file could not be read.',
        'iban_not_in_preview' => 'This IBAN is not part of the current preview.',
        'pdf_reader_unavailable' => 'PDF statements need the pdftotext program, which is not installed here. Import this file on a desktop that has it, or use a CSV export from your bank instead.',
        'row_unreadable' => 'This row could not be read.',
        'unknown_account' => 'This row belongs to an account you have not named yet.',
    ],

    'failed' => [
        'heading' => 'This file could not be read',
        'no_rows' => 'No transactions were found in this file, so there is nothing to import.',
        'nothing_read' => 'Nothing in this file could be read as a transaction, so there is nothing to import.',
        'every_row' => 'Every row in this file failed to read, so there is nothing to import. Each one is listed below with the reason.',
        'likely_cause' => 'The usual cause is a header row that does not match the source you chose. Check the bank and the format on the upload screen, or download the statement from your bank again.',
        'truncated_heading' => 'Only part of this file could be read',
        'truncated' => 'Reading stopped part-way through the file. Anything after that point was not read and will not be imported.',
        'some_rows' => 'Some rows could not be read. They are marked below and will be skipped; confirming imports the rest.',
        'detail_label' => 'What the parser reported:',
        'rows_read_label' => 'Rows read',
        'rows_skipped_label' => 'Rows skipped',
    ],
];
