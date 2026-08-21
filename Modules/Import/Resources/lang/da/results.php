<?php

declare(strict_types=1);

return [
    'page_title' => 'Importen er færdig',
    'heading' => 'Importen er færdig',

    'summary' => 'Importerede :count transaktion|Importerede :count transaktioner',
    'summary_duplicates' => ' · sprang :count dublet over| · sprang :count dubletter over',
    'summary_enriched' => ' · :count berigede',
    'summary_errors' => ' · :count fejl| · :count fejl',

    'show_duplicates' => 'Vis oversprungne dubletter (:count)',
    'duplicates_help' => 'Dubletter er rækker, der allerede findes blandt dine transaktioner — de springes stille over ved gentagen import.',
    'show_errors' => 'Vis fejl (:count)',
    'errors_help' => 'Fejl er rækker, der ikke kunne indlæses; de blev ikke føjet til dine transaktioner.',

    'upload_another' => 'Upload endnu et kontoudtog',

    'issues' => [
        'row' => 'Række :row: :reason',
        'file' => 'Filen kunne ikke læses helt: :reason',
        'duplicate' => 'Række :row var allerede i din hovedbog.',
        'more' => '+ :count ikke vist',
        'unknown_reason' => 'Der blev ikke registreret nogen årsag.',
    ],
];
