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

    'chain' => [
        'heading' => 'Láncok feloldása',
        'pending' => 'A láncok feloldása nem indult el, ezért a finanszírozási láncok nem lettek összekapcsolva.',
        'running' => 'Finanszírozási láncok összekapcsolása és a kivonatelszámolások felbontása.',
    ],

    'issues' => [
        'row' => ':row. sor: :reason',
        'file_stopped' => 'A fájlt nem sikerült a(z) :row. soron túl beolvasni. Az azutáni sorokból semmi nem került importálásra.',
        'file_none' => 'A fájlt egyáltalán nem sikerült beolvasni.',
        'detail' => 'A beolvasó jelentése: :reason',
        'duplicate' => 'A :row. sor már szerepelt a főkönyvedben.',
        'more' => '+ :count nincs felsorolva',
    ],
];
