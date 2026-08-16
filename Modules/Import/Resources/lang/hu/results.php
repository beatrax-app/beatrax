<?php

declare(strict_types=1);

return [
    'page_title' => 'Import kész',
    'heading' => 'Import kész',

    'summary' => ':inserted tranzakció importálva · :duplicates duplikátum kihagyva',
    'summary_enriched' => ' · :count kiegészítve',
    'summary_errors' => ' · :count hiba',

    'show_duplicates' => 'Kihagyott duplikátumok mutatása (:count)',
    'duplicates_help' => 'A duplikátumok olyan sorok, amelyek már szerepelnek a főkönyvedben — újraimportáláskor jelzés nélkül kimaradnak.',
    'show_errors' => 'Hibák mutatása (:count)',
    'errors_help' => 'A hibák olyan sorok, amelyeket nem sikerült beolvasni; ezek nem kerültek a főkönyvedbe.',

    'upload_another' => 'Másik számlakivonat feltöltése',
];
