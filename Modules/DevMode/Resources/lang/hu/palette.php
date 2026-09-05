<?php

declare(strict_types=1);

return [
    'search_placeholder' => 'Írj a nézetek, parancsok és műveletek kereséséhez. Az Esc bezárja.',
    'search_aria' => 'Írj a nézetek, parancsok és műveletek kereséséhez',
    'dialog_aria' => 'Parancspaletta',
    'token_suggest_aria' => 'Tokenjavaslatok',
    'rail_view' => 'Nézet',
    'rail_dev' => 'Dev',
    'rail_action' => 'Művelet',
    'rail_recent' => 'Legutóbbi',
    'no_recent' => 'Még nincs legutóbbi választás.',
    'section_transactions' => 'Tranzakciók',
    'section_counterparties' => 'Partnerek',
    'section_categories' => 'Kategóriák',
    'section_goals_recurring' => 'Célok és ismétlődők',
    'no_name' => '(nincs név)',
    'see_all' => ':count találat megtekintése →|Mind a :count találat megtekintése →',
    'no_transactions' => 'Nincs tranzakció erre: „:query”',
    'source_txn' => 'txn',
    'source_counterparty' => 'partner',
    'source_category' => 'kategória',
    'results_aria' => 'Találatok',
    'no_results' => 'Nincs találat.',
    'foot_navigate' => 'navigálás',
    'foot_select' => 'kiválasztás',
    'foot_close' => 'bezárás',
    'close_aria' => 'Keresés bezárása',
    'close_caption' => 'Bezárása',
    'foot_try' => 'Próbáld',
    'results' => ':count találat|:count találat',

    'action' => [
        'run_import' => ['label' => 'Import futtatása', 'hint' => 'Az importvarázsló megnyitása'],
        'scan_email' => ['label' => 'Postafiókok megnyitása', 'hint' => 'A csatlakoztatott postafiókjaid'],
        // i18n-review: hu · action.open_profile.hint — «beállítások» covers both Settings and
        // preferences here, so the hint repeats it as the compound «alkalmazásbeállítások».
        // A native reader decides whether that reads.
        'open_profile' => ['label' => 'Profil megnyitása', 'hint' => 'Beállítások — fiók és alkalmazásbeállítások'],
        'toggle_theme' => ['label' => 'Megjelenés beállításainak megnyitása', 'hint' => 'Világos, sötét vagy rendszer'],
    ],

    'run_command' => ':command futtatása',

    'nav' => [
        'overview' => ['label' => 'Fejlesztői áttekintés', 'hint' => 'Rendszercsempék + legutóbbi futtatások'],
        'artisan' => ['label' => 'Artisan runner', 'hint' => 'Engedélyezett parancsok futtatása'],
        'audit' => ['label' => 'Fejlesztői auditnapló', 'hint' => 'A fejlesztői módban végzett műveleteid'],
        'logs' => ['label' => 'Naplókövető', 'hint' => 'A laravel-*.log élő folyama'],
        'queue' => ['label' => 'Várólista-vizsgáló', 'hint' => 'Függőben / sikertelen / kötegek'],
        'doctor' => ['label' => 'Doctor', 'hint' => 'Rendszerpróbák'],
        'sql' => ['label' => 'SQL-panel', 'hint' => 'Csak SELECT böngésző'],
        'system' => ['label' => 'Rendszer-pillanatkép', 'hint' => 'Környezet + útvonalak + konfiguráció'],
        'horizon' => ['label' => 'Horizon', 'hint' => 'Beágyazott várólista-irányítópult'],
        'sync_health' => ['label' => 'Szinkron állapota', 'hint' => 'Karanténba tett vagy kihagyott összefésülések'],
    ],
];
