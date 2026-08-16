<?php

declare(strict_types=1);

return [
    'page_title' => 'Upload statement',
    'heading' => 'Upload statement',
    'migrate_prompt' => 'Moving from another budgeting app?',
    'migrate_link' => 'Import from YNAB or Actual',
    'subtitle' => 'Drop in a bank, card, or PayPal export, or an email receipt file.',
    'mime_hint' => "That file doesn't look like a supported statement export. Drop in a bank CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, a card-statement PDF, an email message (.eml), or a mailbox archive (.mbox).",

    'source_label' => 'Source',

    'issuer_other_bank' => 'Other bank (N26, Revolut, ING…)',
    'issuer_email_file' => 'Email file (.eml, .mbox)',

    'format_label' => 'Format',
    'file_label' => 'File',
    'submit' => 'Upload statement',

    'formats' => [
        'activity_download' => 'Activity Download (CSV)',
        'email_message' => 'Email message (.eml)',
        'mailbox_archive' => 'Mailbox archive (.mbox)',
        'ing_nl' => 'ING Netherlands (CSV)',
    ],

    'errors' => [
        'file_max' => 'That file is too large. Drop in a statement export under the size limit for the chosen format.',
        'file_extensions' => "That file doesn't look like a supported statement export. Drop in a bank CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, a card-statement PDF, an email message (.eml), or a mailbox archive (.mbox).",
        'issuer_format' => 'The :attribute value is not valid for the :source source.',
        'process_failed' => 'Could not process this file (:class). The full error is in /dev/logs.',
    ],
];
