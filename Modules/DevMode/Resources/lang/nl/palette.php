<?php

declare(strict_types=1);

return [
    'search_placeholder' => 'Typ om weergaven, commando’s en acties te zoeken. Druk op Esc om te sluiten.',
    'search_aria' => 'Typ om weergaven, commando’s en acties te zoeken',
    'dialog_aria' => 'Commandopalet',
    'token_suggest_aria' => 'Tokensuggesties',
    'rail_view' => 'Weergave',
    'rail_dev' => 'Dev',
    'rail_action' => 'Actie',
    'rail_recent' => 'Recent',
    'no_recent' => 'Nog geen recente keuzes.',
    'section_transactions' => 'Transacties',
    'section_counterparties' => 'Tegenpartijen',
    'section_categories' => 'Categorieën',
    'section_goals_recurring' => 'Doelen & terugkerend',
    'no_name' => '(geen naam)',
    'see_all' => 'Bekijk :count resultaat →|Bekijk alle :count resultaten →',
    'no_transactions' => 'Geen transacties komen overeen met ":query"',
    'source_txn' => 'txn',
    'source_counterparty' => 'tegenpartij',
    'source_category' => 'categorie',
    'results_aria' => 'Resultaten',
    'no_results' => 'Geen resultaten.',
    'foot_navigate' => 'navigeren',
    'foot_select' => 'selecteren',
    'foot_close' => 'sluiten',
    'close_aria' => 'Zoeken sluiten',
    'close_caption' => 'Sluiten',
    'foot_try' => 'Probeer',
    'results' => ':count resultaat|:count resultaten',

    'action' => [
        'run_import' => ['label' => 'Import uitvoeren', 'hint' => 'De importwizard openen'],
        'scan_email' => ['label' => 'Postvakken openen', 'hint' => 'Je gekoppelde postvakken'],
        'open_profile' => ['label' => 'Profiel openen', 'hint' => 'Instellingen — account en voorkeuren'],
        'toggle_theme' => ['label' => 'Weergave-instellingen openen', 'hint' => 'Licht, donker of systeem'],
    ],

    'run_command' => ':command uitvoeren',

    'nav' => [
        'overview' => ['label' => 'Dev-overzicht', 'hint' => 'Systeemtegels + recente runs'],
        'artisan' => ['label' => 'Artisan-runner', 'hint' => "Commando's uit de whitelist uitvoeren"],
        'audit' => ['label' => 'Dev-auditlog', 'hint' => 'Jouw acties in dev-modus'],
        'logs' => ['label' => 'Log-tailer', 'hint' => 'Live stream van laravel-*.log'],
        'queue' => ['label' => 'Wachtrij-inspecteur', 'hint' => 'In behandeling / mislukt / batches'],
        'doctor' => ['label' => 'Doctor', 'hint' => 'Systeemprobes'],
        'sql' => ['label' => 'SQL-paneel', 'hint' => 'Browser met alleen SELECT'],
        'system' => ['label' => 'Systeemsnapshot', 'hint' => 'Omgeving + paden + configuratie'],
        'horizon' => ['label' => 'Horizon', 'hint' => 'Ingebouwd wachtrijdashboard'],
        'sync_health' => ['label' => 'Sync-status', 'hint' => 'Merge-acties in quarantaine of overgeslagen'],
    ],
];
