<?php

declare(strict_types=1);

return [
    'page_title' => 'Import dokončený',
    'heading' => 'Import dokončený',

    'summary' => 'Naimportovaná :count transakcia|Naimportované :count transakcie|Naimportovaných :count transakcií',
    'summary_duplicates' => ' · preskočený :count duplikát| · preskočené :count duplikáty| · preskočených :count duplikátov',
    'summary_enriched' => ' · obohatené: :count',
    'summary_errors' => ' · :count chyba| · :count chyby| · :count chýb',

    'show_duplicates' => 'Zobraziť preskočené duplikáty (:count)',
    'duplicates_help' => 'Duplikáty sú riadky, ktoré už v tvojej knihe sú — pri opätovnom importe sa potichu preskočia.',
    'show_errors' => 'Zobraziť chyby (:count)',
    'errors_help' => 'Chyby sú riadky, ktoré sa nepodarilo spracovať; do knihy sa nepridali.',

    'upload_another' => 'Nahrať ďalší výpis z účtu',

    'issues' => [
        'row' => 'Riadok :row: :reason',
        'file' => 'Súbor sa nepodarilo načítať celý: :reason',
        'duplicate' => 'Riadok :row už bol v tvojej knihe.',
        'more' => '+ :count neuvedených',
        'unknown_reason' => 'Dôvod nebol zaznamenaný.',
    ],
];
