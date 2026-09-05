<?php

declare(strict_types=1);

return [
    'search_placeholder' => 'Rakstiet, lai meklētu skatus, komandas un darbības. Nospiediet Esc, lai aizvērtu.',
    'search_aria' => 'Rakstiet, lai meklētu skatus, komandas un darbības',
    'dialog_aria' => 'Komandu palete',
    'token_suggest_aria' => 'Marķieru ieteikumi',
    'rail_view' => 'Skats',
    'rail_dev' => 'Izstrāde',
    'rail_action' => 'Darbība',
    'rail_recent' => 'Nesenie',
    'no_recent' => 'Vēl nav neseno izvēļu.',
    'section_transactions' => 'Darījumi',
    'section_counterparties' => 'Darījuma partneri',
    'section_categories' => 'Kategorijas',
    'section_goals_recurring' => 'Mērķi un regulārie maksājumi',
    'no_name' => '(bez nosaukuma)',
    'see_all' => 'Skatīt :count rezultātu →|Skatīt :count rezultātu →|Skatīt visus :count rezultātus →',
    'no_transactions' => 'Neviens darījums neatbilst „:query”',
    'source_txn' => 'darījums',
    'source_counterparty' => 'partneris',
    'source_category' => 'kategorija',
    'results_aria' => 'Rezultāti',
    'no_results' => 'Nav rezultātu.',
    'foot_navigate' => 'pārvietoties',
    'foot_select' => 'izvēlēties',
    'foot_close' => 'aizvērt',
    'close_aria' => 'Aizvērt meklēšanu',
    'close_caption' => 'Aizvērt',
    'foot_try' => 'Mēģiniet',
    'results' => ':count rezultātu|:count rezultāts|:count rezultāti',

    'action' => [
        'run_import' => ['label' => 'Palaist importu', 'hint' => 'Atvērt importēšanas vedni'],
        'scan_email' => ['label' => 'Atvērt pastkastes', 'hint' => 'Jūsu pievienotās pastkastes'],
        'open_profile' => ['label' => 'Atvērt profilu', 'hint' => 'Iestatījumi — konts un preferences'],
        'toggle_theme' => ['label' => 'Atvērt izskata iestatījumus', 'hint' => 'Gaišs, tumšs vai sistēmas'],
    ],

    'run_command' => 'Izpildīt :command',

    'nav' => [
        'overview' => ['label' => 'Izstrādes pārskats', 'hint' => 'Sistēmas elementi + nesenās izpildes'],
        'artisan' => ['label' => 'Artisan izpildītājs', 'hint' => 'Atļauto komandu izpilde'],
        'audit' => ['label' => 'Izstrādes audita žurnāls', 'hint' => 'Jūsu darbības izstrādes režīmā'],
        'logs' => ['label' => 'Žurnālu sekotājs', 'hint' => 'laravel-*.log tiešraides plūsma'],
        'queue' => ['label' => 'Rindas inspektors', 'hint' => 'Gaidošie / neizdevušies / partijas'],
        'doctor' => ['label' => 'Doctor', 'hint' => 'Sistēmas pārbaudes'],
        'sql' => ['label' => 'SQL panelis', 'hint' => 'Pārlūks tikai SELECT vaicājumiem'],
        'system' => ['label' => 'Sistēmas momentuzņēmums', 'hint' => 'Vide + ceļi + konfigurācija'],
        'horizon' => ['label' => 'Horizon', 'hint' => 'Iegultais rindas panelis'],
        'sync_health' => ['label' => 'Sinhronizācijas stāvoklis', 'hint' => 'Karantīnā ievietotās vai izlaistās apvienošanas darbības'],
    ],
];
