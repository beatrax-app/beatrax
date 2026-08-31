<?php

declare(strict_types=1);

return [
    'page_title' => 'Import from YNAB / Actual',

    'eyebrow' => 'Migrations',
    'heading' => 'Import from YNAB / Actual',
    'intro' => 'Bring your category tree, budget history, and transactions over from YNAB4, new YNAB, or Actual Budget. Nothing is written to your ledger until you review and confirm.',
    'reconcile_context' => 'Checking for updates against your last :product import.',

    'source_label' => 'Source',
    'file_label' => 'File',
    'parse_button' => 'Parse export',

    'hints' => [
        'ynab4' => "Export your full budget as a ZIP file from YNAB4's File → Export menu.",
        'nynab' => 'Export your budget from nYNAB via File → Export Budget, then zip up the exported CSV files.',
        'actual' => "Export your budget as a ZIP file from Actual Budget's Settings → Export data.",
    ],

    'errors' => [
        'unrecognised' => "This doesn't look like a YNAB4, nYNAB, or Actual export we can read. Check the file and try again.",
        'file_too_large' => 'That file is too large for a migration export.',
        'archive_reader_unavailable' => 'This build of the app has no ZIP reader that can open this export, so it cannot be read here. Import it on the desktop app instead, or re-zip the export with ordinary compression.',
        'internal_detail' => 'The app could not read this export (:code). The full details are in the app log; quote this code if you report a problem.',
    ],
];
