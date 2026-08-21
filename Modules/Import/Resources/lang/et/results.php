<?php

declare(strict_types=1);

return [
    'page_title' => 'Import on lõpetatud',
    'heading' => 'Import on lõpetatud',

    'summary' => 'Imporditud :count tehing|Imporditud :count tehingut',
    'summary_duplicates' => ' · vahele jäetud :count duplikaat| · vahele jäetud :count duplikaati',
    'summary_enriched' => ' · :count täiendatud',
    'summary_errors' => ' · :count viga| · :count viga',

    'show_duplicates' => 'Näita vahele jäetud duplikaate (:count)',
    'duplicates_help' => 'Duplikaadid on read, mis on sinu pearaamatus juba olemas — need jäetakse uuesti importimisel vaikselt vahele.',
    'show_errors' => 'Näita vigu (:count)',
    'errors_help' => 'Vead on read, mida ei õnnestunud töödelda; neid ei lisatud sinu pearaamatusse.',

    'upload_another' => 'Laadi üles järgmine väljavõte',

    'issues' => [
        'row' => 'Rida :row: :reason',
        'file_stopped' => 'Faili ei õnnestunud lugeda kaugemale kui rida :row. Midagi pärast seda rida ei imporditud.',
        'file_none' => 'Faili ei õnnestunud üldse lugeda.',
        'detail' => 'Lugeja teatas: :reason',
        'duplicate' => 'Rida :row oli juba sinu pearaamatus.',
        'more' => '+ :count pole loetletud',
        'unknown_reason' => 'Põhjust ei salvestatud.',
    ],
];
