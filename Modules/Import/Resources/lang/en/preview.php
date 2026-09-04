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
    'unreadable_html' => 'This preview cannot be read. <a href="/imports/new" class="underline">Re-upload the file</a> to try again.',

    'save_name' => 'Save name',
    'account_name_label' => 'Account name',
    'account_placeholder' => 'e.g. Main savings account',
    'rename_aria' => 'Rename this counterparty',

    'unknown_iban_prefix' => 'We found an unfamiliar IBAN:',

    'unknown_account_prefix' => 'We found an unfamiliar account:',
    'unknown_iban_suffix' => 'Name this account.',

    'ics' => [
        'name' => 'ICS card',
        'heading' => 'Name your ICS card account.',
        'help' => "This is the first time you've imported ICS data. Give this card a name so it shows up consistently across the app.",
        'placeholder' => 'e.g. ICS card',
    ],

    'paypal' => [
        'name' => 'PayPal',
        'heading' => 'Name your PayPal account.',
        'help' => "This is the first time you've imported PayPal data. Give this wallet a name so it shows up consistently across the app.",
        'placeholder' => 'e.g. PayPal',
    ],

    'google_play' => [
        'name' => 'Google Play',
        'heading' => 'Name your Google Play account.',
        'help' => "This is the first time you've imported a Google Play receipt. Give this account a name so it shows up consistently across the app.",
        'placeholder' => 'e.g. Google Play',
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

    'rows_shown' => 'Rows shown: :shown of :total',

    'show_more' => 'Show more rows',

    'errors' => [
        'app_locked' => 'Unlock the app to import: the encryption keys cannot be used while it is locked.',
        'archive_holds_one_message' => 'This file is a single email message, not a mailbox archive, so reading it as an archive would find nothing in it. Upload it again with the format set to Email message.',
        'email_file_is_an_archive' => 'This file is a mailbox archive: it holds more than one message, and reading it as a single message would take only the first. Upload it again with the format set to Mailbox archive.',
        'file_stopped_short' => 'The header matched, so the format is right. Reading stopped before the end of the file. One unreadable row does this, and so does a file too large for this device. Try a shorter date range.',
        'file_unreadable' => 'This file could not be read.',
        'file_unreadable_detail' => 'The app could not read this file (:code). The full details are in the app log; quote this code if you report a problem.',
        'iban_not_in_preview' => 'This IBAN is not part of the current preview.',
        'not_an_email_file' => 'This file is neither an email message nor a mailbox archive, so there is nothing in it to read as a receipt. Pick the import type and format that match the file you have.',
        'pdf_has_no_text_layer' => 'This PDF holds no text — it is a scan or a photo of a statement, so there is nothing in it to read. Download the statement itself from your bank, or use a CSV export instead.',
        'pdf_password_protected' => 'This PDF is password-protected, so no reader can open it. Save an unprotected copy from your PDF viewer and import that one.',
        'pdf_reader_unavailable' => 'This build of the app has no PDF reader at all, so a PDF statement cannot be opened here. Import this file on another device, or use a CSV export from your bank instead.',
        'row_belongs_to_another_statement' => 'This row belongs to a transaction in another statement file. Import that statement as well — the two are read together.',
        'row_unreadable' => 'This row could not be read.',
        'row_unreadable_detail' => 'The app could not read this row (:code). The full details are in the app log; quote this code if you report a problem.',
        'unknown_account' => 'This row belongs to an account you have not named yet.',
    ],

    'receipts' => [
        'heading' => 'This file was read as email',
        'saved' => 'What it carried is listed below, and every message has been saved.',
        'none_imported' => 'Nothing here became a transaction, so nothing was added to your ledger.',
        'shown' => 'Messages shown: :shown of :total',
        'no_subject' => 'No subject',

        'state' => [
            'read' => 'Read as a payment — confirm this import to add it to your ledger.',
            'not_a_payment' => 'Not a payment. This message announces something rather than confirming one.',
            'unreadable' => 'Saved. The app reads receipts from this sender, but found no amount, merchant and reference in this message.',
            'unknown_sender' => 'Saved. The app does not read receipts from this sender, so it took nothing from the message.',
        ],
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
