<?php

declare(strict_types=1);

return [
    'page_title' => 'Upload statement',
    'heading' => 'Upload statement',
    'migrate_prompt' => 'Moving from another budgeting app?',
    'migrate_link' => 'Import from YNAB or Actual',
    'subtitle' => 'Drop in a CSV, CAMT.053, MT940 or PDF statement, or an email receipt file.',
    'mime_hint' => 'Supported files: bank CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, card-statement PDF, email message (.eml), or mailbox archive (.mbox).',

    'type_label' => 'Import type',

    'types' => [
        'csv' => 'CSV file',
        'camt053' => 'CAMT.053 statement (XML)',
        'mt940' => 'MT940 statement',
        'pdf' => 'Card statement (PDF)',
        'email' => 'Email receipt file',
    ],

    'format_label' => 'Format',
    'format_from_file' => 'The format was set to :format to match the file you picked. Change it if that is wrong.',
    'file_label' => 'File',
    'submit' => 'Upload statement',

    'formats' => [
        'activity_download' => 'Activity Download (CSV)',
        'email_message' => 'Email message (.eml)',
        'mailbox_archive' => 'Mailbox archive (.mbox)',
    ],

    'errors' => [
        'file_max' => 'That file is too large. Drop in a statement export under the size limit for the chosen format.',
        'file_extensions' => "That file doesn't look like a supported statement export. Drop in a bank CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, a card-statement PDF, an email message (.eml), or a mailbox archive (.mbox).",
        'type_format' => 'The :attribute value is not valid for the :type import type.',
        'process_failed' => 'Could not process this file (:class). The full error is in /dev/logs.',
    ],
];
