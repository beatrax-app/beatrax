<?php

declare(strict_types=1);

return [
    'search_placeholder' => 'Skriv for å søke i visninger, kommandoer og handlinger. Trykk på Esc for å lukke.',
    'search_aria' => 'Skriv for å søke i visninger, kommandoer og handlinger',
    'dialog_aria' => 'Kommandopalett',
    'token_suggest_aria' => 'Tokenforslag',
    'rail_view' => 'Visning',
    'rail_dev' => 'Dev',
    'rail_action' => 'Handling',
    'rail_recent' => 'Nylig',
    'no_recent' => 'Ingen nylige valg ennå.',
    'section_transactions' => 'Transaksjoner',
    'section_counterparties' => 'Motparter',
    'section_categories' => 'Kategorier',
    'section_goals_recurring' => 'Mål & gjentakende',
    'no_name' => '(uten navn)',
    'see_all' => 'Se :count resultat →|Se alle :count resultater →',
    'no_transactions' => 'Ingen transaksjoner matcher ":query"',
    'source_txn' => 'txn',
    'source_counterparty' => 'motpart',
    'source_category' => 'kategori',
    'results_aria' => 'Resultater',
    'no_results' => 'Ingen resultater.',
    'foot_navigate' => 'naviger',
    'foot_select' => 'velg',
    'foot_close' => 'lukk',
    'close_aria' => 'Lukk søket',
    'close_caption' => 'Lukk',
    'foot_try' => 'Prøv',
    'results' => ':count resultat|:count resultater',

    'action' => [
        'run_import' => ['label' => 'Kjør import', 'hint' => 'Åpne importveiviseren'],
        'scan_email' => ['label' => 'Skann e-post nå', 'hint' => 'Kjør synkroniseringen av postkassen med én gang'],
        'open_profile' => ['label' => 'Åpne profilen', 'hint' => 'Innstillinger — konto og preferanser'],
        'toggle_theme' => ['label' => 'Bytt tema', 'hint' => 'Bytt mellom lyst og mørkt tema'],
    ],

    'run_command' => 'Kjør :command',

    'nav' => [
        'overview' => ['label' => 'Dev-oversikt', 'hint' => 'Systemfliser + siste kjøringer'],
        'artisan' => ['label' => 'Artisan-runner', 'hint' => 'Kjør godkjente kommandoer'],
        'audit' => ['label' => 'Dev-revisjonslogg', 'hint' => 'Hver handling i dev-modus'],
        'logs' => ['label' => 'Loggviser', 'hint' => 'Direktestrøm av laravel-*.log'],
        'queue' => ['label' => 'Køinspektør', 'hint' => 'Ventende / feilede / batcher'],
        'doctor' => ['label' => 'Doctor', 'hint' => 'Systemprober'],
        'sql' => ['label' => 'SQL-panel', 'hint' => 'Leser med bare SELECT'],
        'system' => ['label' => 'Øyeblikksbilde av systemet', 'hint' => 'Miljø + stier + konfigurasjon'],
        'horizon' => ['label' => 'Horizon', 'hint' => 'Innebygd kø-dashbord'],
        'sync_health' => ['label' => 'Synkstatus', 'hint' => 'Fletteoperasjoner i karantene eller hoppet over'],
    ],
];
