<?php

declare(strict_types=1);

return [
    'search_placeholder' => 'Skriv för att söka bland vyer, kommandon och åtgärder. Tryck på Esc för att stänga.',
    'search_aria' => 'Skriv för att söka bland vyer, kommandon och åtgärder',
    'dialog_aria' => 'Kommandopalett',
    'token_suggest_aria' => 'Tokenförslag',
    'rail_view' => 'Vy',
    'rail_dev' => 'Dev',
    'rail_action' => 'Åtgärd',
    'rail_recent' => 'Senaste',
    'no_recent' => 'Inga tidigare val än.',
    'section_transactions' => 'Transaktioner',
    'section_counterparties' => 'Motparter',
    'section_categories' => 'Kategorier',
    'section_goals_recurring' => 'Mål & återkommande',
    'no_name' => '(inget namn)',
    'see_all' => 'Visa :count resultat →|Visa alla :count resultat →',
    'no_transactions' => 'Inga transaktioner matchar ":query"',
    'source_txn' => 'txn',
    'source_counterparty' => 'motpart',
    'source_category' => 'kategori',
    'results_aria' => 'Resultat',
    'no_results' => 'Inga resultat.',
    'foot_navigate' => 'navigera',
    'foot_select' => 'välj',
    'foot_close' => 'stäng',
    'close_aria' => 'Stäng sökningen',
    'close_caption' => 'Stäng',
    'foot_try' => 'Testa',
    'results' => ':count resultat|:count resultat',

    'action' => [
        'run_import' => ['label' => 'Kör import', 'hint' => 'Öppna importguiden'],
        'scan_email' => ['label' => 'Skanna e-post nu', 'hint' => 'Kör synkroniseringen av brevlådan direkt'],
        'open_profile' => ['label' => 'Öppna profilen', 'hint' => 'Inställningar — konto och preferenser'],
        'toggle_theme' => ['label' => 'Byt tema', 'hint' => 'Växla mellan ljust och mörkt tema'],
    ],

    'run_command' => 'Kör :command',

    'nav' => [
        'overview' => ['label' => 'Dev-översikt', 'hint' => 'Systemrutor + senaste körningar'],
        'artisan' => ['label' => 'Artisan-runner', 'hint' => 'Kör godkända kommandon'],
        'audit' => ['label' => 'Dev-granskningslogg', 'hint' => 'Varje åtgärd i dev-läget'],
        'logs' => ['label' => 'Loggvisare', 'hint' => 'Direktström av laravel-*.log'],
        'queue' => ['label' => 'Köinspektör', 'hint' => 'Väntande / misslyckade / batcher'],
        'doctor' => ['label' => 'Doctor', 'hint' => 'Systemprober'],
        'sql' => ['label' => 'SQL-panel', 'hint' => 'Läsare med enbart SELECT'],
        'system' => ['label' => 'Ögonblicksbild av systemet', 'hint' => 'Miljö + sökvägar + konfiguration'],
        'horizon' => ['label' => 'Horizon', 'hint' => 'Inbäddad köpanel'],
        'sync_health' => ['label' => 'Synkstatus', 'hint' => 'Sammanslagningar i karantän eller överhoppade'],
    ],
];
