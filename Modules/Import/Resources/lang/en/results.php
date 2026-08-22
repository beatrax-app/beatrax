<?php

declare(strict_types=1);

return [
    'page_title' => 'Import complete',
    'heading' => 'Import complete',

    'summary' => 'Imported :count transaction|Imported :count transactions',
    'summary_duplicates' => ' · skipped :count duplicate| · skipped :count duplicates',
    'summary_enriched' => ' · :count enriched',
    'summary_errors' => ' · :count error| · :count errors',

    'show_duplicates' => 'Show skipped duplicates (:count)',
    'duplicates_help' => 'Duplicates are rows already present in your ledger — they are silently skipped on re-import.',
    'show_errors' => 'Show errors (:count)',
    'errors_help' => 'Errors are rows that could not be parsed; they were not added to your ledger.',

    'upload_another' => 'Upload another statement',

    'issues' => [
        'row' => 'Row :row: :reason',
        'file_stopped' => 'The file could not be read past row :row. Nothing after that row was imported.',
        'file_none' => 'The file could not be read at all.',
        'detail' => 'The reader reported: :reason',
        'duplicate' => 'Row :row was already in your ledger.',
        'more' => '+ :count not listed',
    ],
];
