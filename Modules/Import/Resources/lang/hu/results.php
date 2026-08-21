<?php

declare(strict_types=1);

return [
    'page_title' => 'Import kész',
    'heading' => 'Import kész',

    'summary' => ':count tranzakció importálva|:count tranzakció importálva',
    'summary_duplicates' => ' · :count duplikátum kihagyva| · :count duplikátum kihagyva',
    'summary_enriched' => ' · :count kiegészítve',
    'summary_errors' => ' · :count hiba| · :count hiba',

    'show_duplicates' => 'Kihagyott duplikátumok mutatása (:count)',
    'duplicates_help' => 'A duplikátumok olyan sorok, amelyek már szerepelnek a főkönyvedben — újraimportáláskor jelzés nélkül kimaradnak.',
    'show_errors' => 'Hibák mutatása (:count)',
    'errors_help' => 'A hibák olyan sorok, amelyeket nem sikerült beolvasni; ezek nem kerültek a főkönyvedbe.',

    'upload_another' => 'Másik számlakivonat feltöltése',

    'issues' => [
        'row' => ':row. sor: :reason',
        'file' => 'A fájlt nem sikerült teljesen beolvasni: :reason',
        'duplicate' => 'A :row. sor már szerepelt a főkönyvedben.',
        'more' => '+ :count nincs felsorolva',
        'unknown_reason' => 'Nem rögzítettünk okot.',
    ],
];
