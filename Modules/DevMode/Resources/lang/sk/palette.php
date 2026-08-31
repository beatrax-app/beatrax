<?php

declare(strict_types=1);

return [
    'search_placeholder' => 'Píš a hľadaj zobrazenia, príkazy a akcie. Zavrieš klávesom Esc.',
    'search_aria' => 'Píš a hľadaj zobrazenia, príkazy a akcie',
    'dialog_aria' => 'Paleta príkazov',
    'token_suggest_aria' => 'Návrhy tokenov',
    'rail_view' => 'Zobrazenie',
    'rail_dev' => 'Dev',
    'rail_action' => 'Akcia',
    'rail_recent' => 'Nedávne',
    'no_recent' => 'Zatiaľ žiadne nedávne voľby.',
    'section_transactions' => 'Transakcie',
    'section_counterparties' => 'Protistrany',
    'section_categories' => 'Kategórie',
    'section_goals_recurring' => 'Ciele a opakované platby',
    'no_name' => '(bez názvu)',
    // i18n-review: sk · see_all — the same wszystkie/wszystkich call as Polish,
    // in Slovak: všetkých :count výsledkov is written here against všetky. The
    // line this replaces had given up and moved the count into brackets.
    'see_all' => 'Zobraziť :count výsledok →|Zobraziť :count výsledky →|Zobraziť všetkých :count výsledkov →',
    'no_transactions' => 'Žiadnej transakcii nezodpovedá „:query“',
    'source_txn' => 'txn',
    'source_counterparty' => 'protistrana',
    'source_category' => 'kategória',
    'results_aria' => 'Výsledky',
    'no_results' => 'Žiadne výsledky.',
    'foot_navigate' => 'pohyb',
    'foot_select' => 'výber',
    'foot_close' => 'zavrieť',
    'close_aria' => 'Zavrieť hľadanie',
    'close_caption' => 'Zavrieť',
    'foot_try' => 'Skús',
    'results' => ':count výsledok|:count výsledky|:count výsledkov',

    'action' => [
        'run_import' => ['label' => 'Spustiť import', 'hint' => 'Otvoriť sprievodcu importom'],
        'scan_email' => ['label' => 'Prehľadať e-mail teraz', 'hint' => 'Spustiť synchronizáciu schránky ihneď'],
        'open_profile' => ['label' => 'Otvoriť profil', 'hint' => 'Nastavenia — účet a predvoľby'],
        'toggle_theme' => ['label' => 'Prepnúť motív', 'hint' => 'Prepínanie medzi svetlým a tmavým motívom'],
    ],

    'run_command' => 'Spustiť :command',

    'nav' => [
        'overview' => ['label' => 'Vývojársky prehľad', 'hint' => 'Systémové dlaždice + nedávne spustenia'],
        'artisan' => ['label' => 'Artisan runner', 'hint' => 'Spúšťanie príkazov zo zoznamu povolených'],
        'audit' => ['label' => 'Záznam auditu', 'hint' => 'Každá akcia vo vývojárskom režime'],
        // i18n-review: sk · nav.logs.label — Slovak has no noun for a "tailer"; the
        // locale's own phrase is «Živý výpis», a verb phrase, so the row keeps
        // «Výpis logov» and the live half moves down into the hint.
        'logs' => ['label' => 'Výpis logov', 'hint' => 'Živý stream laravel-*.log'],
        'queue' => ['label' => 'Inšpektor frontu', 'hint' => 'Čakajúce / zlyhané / dávky'],
        'doctor' => ['label' => 'Doctor', 'hint' => 'Systémové kontroly'],
        'sql' => ['label' => 'Panel SQL', 'hint' => 'Prehliadač iba s príkazmi SELECT'],
        'system' => ['label' => 'Snímka systému', 'hint' => 'Prostredie + cesty + konfigurácia'],
        'horizon' => ['label' => 'Horizon', 'hint' => 'Vstavaný panel frontu'],
        'sync_health' => ['label' => 'Stav synchronizácie', 'hint' => 'Operácie zlúčenia v karanténe / preskočené'],
    ],
];
