<?php

declare(strict_types=1);

return [
    'search_placeholder' => 'Rašyk, kad ieškotum rodinių, komandų ir veiksmų. Uždaryti — Esc.',
    'search_aria' => 'Rašyk, kad ieškotum rodinių, komandų ir veiksmų',
    'dialog_aria' => 'Komandų paletė',
    'token_suggest_aria' => 'Žymų pasiūlymai',
    'rail_view' => 'Rodinys',
    'rail_dev' => 'Kūrėjas',
    'rail_action' => 'Veiksmas',
    'rail_recent' => 'Naujausi',
    'no_recent' => 'Kol kas naujausių pasirinkimų nėra.',
    'section_transactions' => 'Operacijos',
    'section_counterparties' => 'Kitos šalys',
    'section_categories' => 'Kategorijos',
    'section_goals_recurring' => 'Tikslai ir pasikartojantys mokėjimai',
    'no_name' => '(be pavadinimo)',
    'see_all' => 'Žiūrėti :count rezultatą →|Žiūrėti :count rezultatus →|Žiūrėti :count rezultatų →',
    'no_transactions' => 'Nė viena operacija neatitinka „:query“',
    'source_txn' => 'operacija',
    'source_counterparty' => 'kita šalis',
    'source_category' => 'kategorija',
    'results_aria' => 'Rezultatai',
    'no_results' => 'Rezultatų nėra.',
    'foot_navigate' => 'naršyti',
    'foot_select' => 'pasirinkti',
    'foot_close' => 'uždaryti',
    'close_aria' => 'Uždaryti paiešką',
    'close_caption' => 'Uždaryti',
    'foot_try' => 'Pabandyk',
    'results' => ':count rezultatas|:count rezultatai|:count rezultatų',

    'action' => [
        'run_import' => ['label' => 'Vykdyti importą', 'hint' => 'Atverti importo vediklį'],
        'scan_email' => ['label' => 'Atverti pašto dėžutes', 'hint' => 'Tavo prijungtos pašto dėžutės'],
        'open_profile' => ['label' => 'Atverti profilį', 'hint' => 'Nustatymai — paskyra ir nuostatos'],
        'toggle_theme' => ['label' => 'Atverti išvaizdos nustatymus', 'hint' => 'Šviesi, tamsi arba sistemos'],
    ],

    'run_command' => 'Vykdyti :command',

    'nav' => [
        'overview' => ['label' => 'Kūrėjo apžvalga', 'hint' => 'Sistemos plytelės + naujausi vykdymai'],
        'artisan' => ['label' => 'Artisan vykdyklė', 'hint' => 'Leidžiamų komandų vykdymas'],
        'audit' => ['label' => 'Kūrėjo audito žurnalas', 'hint' => 'Tavo veiksmai kūrėjo režimu'],
        'logs' => ['label' => 'Žurnalų sekiklis', 'hint' => 'Tiesioginis laravel-*.log srautas'],
        'queue' => ['label' => 'Eilės inspektorius', 'hint' => 'Laukiančios / nepavykusios / paketai'],
        'doctor' => ['label' => 'Doctor', 'hint' => 'Sistemos patikros'],
        'sql' => ['label' => 'SQL skydelis', 'hint' => 'Naršyklė tik SELECT užklausoms'],
        'system' => ['label' => 'Sistemos momentinis vaizdas', 'hint' => 'Aplinka + keliai + konfigūracija'],
        'horizon' => ['label' => 'Horizon', 'hint' => 'Įtaisytas eilės skydelis'],
        'sync_health' => ['label' => 'Sinchronizavimo būklė', 'hint' => 'Karantinuotos arba praleistos sujungimo operacijos'],
    ],
];
