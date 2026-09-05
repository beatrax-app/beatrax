<?php

declare(strict_types=1);

return [
    'search_placeholder' => 'Scrie ca să cauți vizualizări, comenzi și acțiuni. Apasă Esc ca să închizi.',
    'search_aria' => 'Scrie ca să cauți vizualizări, comenzi și acțiuni',
    'dialog_aria' => 'Paletă de comenzi',
    'token_suggest_aria' => 'Sugestii de tokenuri',
    'rail_view' => 'Vizualizare',
    'rail_dev' => 'Dev',
    'rail_action' => 'Acțiune',
    'rail_recent' => 'Recente',
    'no_recent' => 'Încă nicio alegere recentă.',
    'section_transactions' => 'Tranzacții',
    'section_counterparties' => 'Contrapărți',
    'section_categories' => 'Categorii',
    'section_goals_recurring' => 'Obiective și recurente',
    'no_name' => '(fără nume)',
    'see_all' => 'Vezi :count rezultat →|Vezi toate cele :count rezultate →|Vezi toate cele :count de rezultate →',
    'no_transactions' => 'Nicio tranzacție pentru „:query”',
    'source_txn' => 'txn',
    'source_counterparty' => 'contraparte',
    'source_category' => 'categorie',
    'results_aria' => 'Rezultate',
    'no_results' => 'Niciun rezultat.',
    'foot_navigate' => 'navigare',
    'foot_select' => 'selectare',
    'foot_close' => 'închide',
    'close_aria' => 'Închide căutarea',
    'close_caption' => 'Închide',
    'foot_try' => 'Încearcă',
    'results' => ':count rezultat|:count rezultate|:count de rezultate',

    'action' => [
        'run_import' => ['label' => 'Rulează un import', 'hint' => 'Deschide asistentul de import'],
        'scan_email' => ['label' => 'Deschide căsuțele de e-mail', 'hint' => 'Căsuțele tale de e-mail conectate'],
        'open_profile' => ['label' => 'Deschide profilul', 'hint' => 'Setări — cont și preferințe'],
        'toggle_theme' => ['label' => 'Deschide setările de aspect', 'hint' => 'Luminoasă, întunecată sau sistem'],
    ],

    'run_command' => 'Rulează :command',

    'nav' => [
        // i18n-review: ro · nav.overview.hint — the overview widgets are called
        // "panouri" here; no other ro DevMode string names them, so there is no
        // in-app precedent to match.
        'overview' => ['label' => 'Prezentare generală dev', 'hint' => 'Panouri de sistem + rulări recente'],
        // i18n-review: ro · nav.artisan.hint — "whitelisted" has no settled Romanian
        // form in this app; the descriptive "din lista permisă" is used. A native
        // reader settles whether the console keeps the English word instead.
        'artisan' => ['label' => 'Runner Artisan', 'hint' => 'Rulează comenzi din lista permisă'],
        'audit' => ['label' => 'Jurnal de audit dev', 'hint' => 'Acțiunile tale din Dev Mode'],
        'logs' => ['label' => 'Urmărire jurnale', 'hint' => 'Flux live din laravel-*.log'],
        'queue' => ['label' => 'Inspector de coadă', 'hint' => 'În așteptare / eșuate / loturi'],
        'doctor' => ['label' => 'Doctor', 'hint' => 'Probe de sistem'],
        'sql' => ['label' => 'Panou SQL', 'hint' => 'Răsfoire doar pentru SELECT'],
        'system' => ['label' => 'Instantaneu de sistem', 'hint' => 'Mediu + căi + configurație'],
        'horizon' => ['label' => 'Horizon', 'hint' => 'Tablou de bord de coadă încorporat'],
        // i18n-review: ro · nav.sync_health.hint — no ro string anywhere names a
        // CRDT "merge op"; "operațiuni de îmbinare" is coined here. The label itself
        // matches Sync's own "Starea sincronizării".
        'sync_health' => ['label' => 'Starea sincronizării', 'hint' => 'Operațiuni de îmbinare în carantină / omise'],
    ],
];
