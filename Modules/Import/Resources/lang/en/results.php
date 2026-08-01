<?php

declare(strict_types=1);

return [
    'page_title' => 'Import complete',
    'heading' => 'Import complete',

    'summary' => 'Imported :inserted transactions · skipped :duplicates duplicates',
    'summary_enriched' => ' · :count enriched',
    'summary_errors' => ' · :count errors',

    'show_duplicates' => 'Show skipped duplicates (:count)',
    'duplicates_help' => 'Duplicates are rows already present in your ledger — they are silently skipped on re-import.',
    'show_errors' => 'Show errors (:count)',
    'errors_help' => 'Errors are rows that could not be parsed; they were not added to your ledger.',

    'upload_another' => 'Upload another statement',
];
