<?php

declare(strict_types=1);

return [
    'page_title' => 'Import dokončen',
    'heading' => 'Import dokončen',

    'summary' => 'Naimportována :count transakce|Naimportovány :count transakce|Naimportováno :count transakcí',
    'summary_duplicates' => ' · přeskočena :count duplicita| · přeskočeny :count duplicity| · přeskočeno :count duplicit',
    'summary_enriched' => ' · obohaceno: :count',
    'summary_errors' => ' · :count chyba| · :count chyby| · :count chyb',

    'show_duplicates' => 'Zobrazit přeskočené duplicity (:count)',
    'duplicates_help' => 'Duplicity jsou řádky, které už v knize máš — při opětovném importu se tiše přeskočí.',
    'show_errors' => 'Zobrazit chyby (:count)',
    'errors_help' => 'Chyby jsou řádky, které se nepodařilo zpracovat; do knihy se nepřidaly.',

    'upload_another' => 'Nahrát další výpis z účtu',

    'chain' => [
        'heading' => 'Řešení řetězců',
        'pending' => 'Řešení řetězců se nespustilo, takže řetězce financování nebyly propojeny.',
        'running' => 'Propojují se řetězce financování a rozkládají se vyrovnání z výpisu z účtu.',
    ],

    'issues' => [
        'row' => 'Řádek :row: :reason',
        'file_stopped' => 'Soubor se nepodařilo načíst dále než po řádek :row. Nic za tímto řádkem nebylo importováno.',
        'file_none' => 'Soubor se nepodařilo načíst vůbec.',
        'detail' => 'Čtečka ohlásila: :reason',
        'duplicate' => 'Řádek :row už byl v tvé knize.',
        'more' => '+ :count neuvedeno',
    ],
];
