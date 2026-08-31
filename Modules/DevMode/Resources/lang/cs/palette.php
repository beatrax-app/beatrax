<?php

declare(strict_types=1);

return [
    'search_placeholder' => 'Piš a hledej pohledy, příkazy a akce. Zavřeš klávesou Esc.',
    'search_aria' => 'Piš a hledej pohledy, příkazy a akce',
    'dialog_aria' => 'Paleta příkazů',
    'token_suggest_aria' => 'Návrhy tokenů',
    'rail_view' => 'Pohled',
    'rail_dev' => 'Dev',
    'rail_action' => 'Akce',
    'rail_recent' => 'Nedávné',
    'no_recent' => 'Zatím žádné nedávné volby.',
    'section_transactions' => 'Transakce',
    'section_counterparties' => 'Protistrany',
    'section_categories' => 'Kategorie',
    'section_goals_recurring' => 'Cíle a opakované platby',
    'no_name' => '(bez názvu)',
    'see_all' => 'Zobrazit :count výsledek →|Zobrazit :count výsledky →|Zobrazit všech :count výsledků →',
    'no_transactions' => 'Žádné transakce neodpovídají „:query“',
    'source_txn' => 'txn',
    'source_counterparty' => 'protistrana',
    'source_category' => 'kategorie',
    'results_aria' => 'Výsledky',
    'no_results' => 'Žádné výsledky.',
    'foot_navigate' => 'pohyb',
    'foot_select' => 'výběr',
    'foot_close' => 'zavřít',
    'close_aria' => 'Zavřít hledání',
    'close_caption' => 'Zavřít',
    'foot_try' => 'Zkus',
    'results' => ':count výsledek|:count výsledky|:count výsledků',

    'action' => [
        'run_import' => ['label' => 'Spustit import', 'hint' => 'Otevřít průvodce importem'],
        'scan_email' => ['label' => 'Naskenovat e-maily', 'hint' => 'Spustit synchronizaci schránky ihned'],
        'open_profile' => ['label' => 'Otevřít profil', 'hint' => 'Nastavení — účet a předvolby'],
        'toggle_theme' => ['label' => 'Přepnout motiv', 'hint' => 'Přepínání mezi světlým a tmavým motivem'],
    ],

    'run_command' => 'Spustit :command',

    'nav' => [
        'overview' => ['label' => 'Vývojářský přehled', 'hint' => 'Systémové dlaždice + nedávná spuštění'],
        'artisan' => ['label' => 'Artisan runner', 'hint' => 'Spouštění povolených příkazů'],
        'audit' => ['label' => 'Vývojářský protokol auditu', 'hint' => 'Každá akce ve vývojářském režimu'],
        'logs' => ['label' => 'Sledování logů', 'hint' => 'Živý stream souboru laravel-*.log'],
        'queue' => ['label' => 'Inspektor fronty', 'hint' => 'Čekající / neúspěšné / dávky'],
        'doctor' => ['label' => 'Doctor', 'hint' => 'Systémové sondy'],
        'sql' => ['label' => 'Panel SQL', 'hint' => 'Prohlížeč jen pro SELECT'],
        'system' => ['label' => 'Snímek systému', 'hint' => 'Prostředí + cesty + konfigurace'],
        'horizon' => ['label' => 'Horizon', 'hint' => 'Vestavěný panel fronty'],
        'sync_health' => ['label' => 'Stav synchronizace', 'hint' => 'Operace sloučení v karanténě nebo přeskočené'],
    ],
];
