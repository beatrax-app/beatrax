<?php

declare(strict_types=1);

return [
    'page_title' => 'Importen är klar',
    'heading' => 'Importen är klar',

    'summary' => 'Importerade :count transaktion|Importerade :count transaktioner',
    'summary_duplicates' => ' · hoppade över :count dubblett| · hoppade över :count dubbletter',
    'summary_enriched' => ' · :count berikade',
    'summary_errors' => ' · :count fel| · :count fel',

    'show_duplicates' => 'Visa överhoppade dubbletter (:count)',
    'duplicates_help' => 'Dubbletter är rader som redan finns bland dina transaktioner — de hoppas över tyst vid ny import.',
    'show_errors' => 'Visa fel (:count)',
    'errors_help' => 'Fel är rader som inte kunde läsas in; de lades inte till bland dina transaktioner.',

    'upload_another' => 'Ladda upp ett kontoutdrag till',

    'chain' => [
        'heading' => 'Löser upp kedjor…',
        'pending' => 'I kö. Kedjelösaren startar snart.',
        'running' => 'Länkar finansieringskedjor och delar upp avräkningar från kontoutdraget.',
    ],

    'issues' => [
        'row' => 'Rad :row: :reason',
        'file_stopped' => 'Filen gick inte att läsa längre än till rad :row. Inget efter den raden importerades.',
        'file_none' => 'Filen gick inte att läsa alls.',
        'detail' => 'Inläsaren rapporterade: :reason',
        'duplicate' => 'Rad :row fanns redan bland dina transaktioner.',
        'more' => '+ :count listas inte',
    ],
];
