<?php

declare(strict_types=1);

return [
    'page_title' => 'Import voltooid',
    'heading' => 'Import voltooid',

    'summary' => ':count transactie geïmporteerd|:count transacties geïmporteerd',
    'summary_duplicates' => ' · :count duplicaat overgeslagen| · :count duplicaten overgeslagen',
    'summary_enriched' => ' · :count verrijkt',
    'summary_errors' => ' · :count fout| · :count fouten',

    'show_duplicates' => 'Overgeslagen duplicaten tonen (:count)',
    'duplicates_help' => 'Duplicaten zijn regels die al in je grootboek staan — ze worden bij een herimport stilzwijgend overgeslagen.',
    'show_errors' => 'Fouten tonen (:count)',
    'errors_help' => 'Fouten zijn regels die niet konden worden ingelezen; ze zijn niet aan je grootboek toegevoegd.',

    'upload_another' => 'Nog een afschrift uploaden',

    'issues' => [
        'row' => 'Regel :row: :reason',
        'file' => 'Het bestand kon niet volledig worden gelezen: :reason',
        'duplicate' => 'Regel :row stond al in je grootboek.',
        'more' => '+ :count niet vermeld',
        'unknown_reason' => 'Er is geen reden vastgelegd.',
    ],
];
