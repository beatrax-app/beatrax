<?php

declare(strict_types=1);

return [
    'search_placeholder' => 'Skriv for at søge i visninger, kommandoer og handlinger. Tryk på Esc for at lukke.',
    'search_aria' => 'Skriv for at søge i visninger, kommandoer og handlinger',
    'dialog_aria' => 'Kommandopalet',
    'token_suggest_aria' => 'Tokenforslag',
    'rail_view' => 'Visning',
    'rail_dev' => 'Dev',
    'rail_action' => 'Handling',
    'rail_recent' => 'Seneste',
    'no_recent' => 'Ingen seneste valg endnu.',
    'section_transactions' => 'Transaktioner',
    'section_counterparties' => 'Modparter',
    'section_categories' => 'Kategorier',
    'section_goals_recurring' => 'Mål & tilbagevendende',
    'no_name' => '(intet navn)',
    'see_all' => 'Se :count resultat →|Se alle :count resultater →',
    'no_transactions' => 'Ingen transaktioner matcher ":query"',
    'source_txn' => 'txn',
    'source_counterparty' => 'modpart',
    'source_category' => 'kategori',
    'results_aria' => 'Resultater',
    'no_results' => 'Ingen resultater.',
    'foot_navigate' => 'navigér',
    'foot_select' => 'vælg',
    'foot_close' => 'luk',
    'close_aria' => 'Luk søgning',
    'close_caption' => 'Luk',
    'foot_try' => 'Prøv',
    'results' => ':count resultat|:count resultater',

    'action' => [
        'run_import' => ['label' => 'Kør import', 'hint' => 'Åbn importguiden'],
        'scan_email' => ['label' => 'Scan e-mail nu', 'hint' => 'Kør synkroniseringen af postkassen med det samme'],
        'open_profile' => ['label' => 'Åbn profilen', 'hint' => 'Indstillinger — konto og præferencer'],
        'toggle_theme' => ['label' => 'Skift tema', 'hint' => 'Skift mellem lyst og mørkt tema'],
    ],

    'run_command' => 'Kør :command',

    'nav' => [
        'overview' => ['label' => 'Dev-overblik', 'hint' => 'Systemfelter + seneste kørsler'],
        'artisan' => ['label' => 'Artisan-runner', 'hint' => 'Kør godkendte kommandoer'],
        'audit' => ['label' => 'Dev-auditlog', 'hint' => 'Hver handling i dev-tilstand'],
        'logs' => ['label' => 'Logvisning', 'hint' => 'Livestream af laravel-*.log'],
        'queue' => ['label' => 'Kø-inspektør', 'hint' => 'Afventende / mislykkede / batches'],
        'doctor' => ['label' => 'Doctor', 'hint' => 'Systemprober'],
        'sql' => ['label' => 'SQL-panel', 'hint' => 'Browser med kun SELECT'],
        'system' => ['label' => 'Øjebliksbillede af systemet', 'hint' => 'Miljø + stier + konfiguration'],
        'horizon' => ['label' => 'Horizon', 'hint' => 'Indlejret kø-dashboard'],
        'sync_health' => ['label' => 'Sync-status', 'hint' => 'Fletteoperationer i karantæne eller sprunget over'],
    ],
];
