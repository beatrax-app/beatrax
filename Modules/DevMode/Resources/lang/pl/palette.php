<?php

declare(strict_types=1);

return [
    'search_placeholder' => 'Wpisz, aby wyszukać widoki, polecenia i akcje. Naciśnij Esc, aby zamknąć.',
    'search_aria' => 'Wpisz, aby wyszukać widoki, polecenia i akcje',
    'dialog_aria' => 'Paleta poleceń',
    'token_suggest_aria' => 'Sugestie tokenów',
    'rail_view' => 'Widok',
    'rail_dev' => 'Dev',
    'rail_action' => 'Akcja',
    'rail_recent' => 'Ostatnie',
    'no_recent' => 'Brak ostatnich wyborów.',
    'section_transactions' => 'Transakcje',
    'section_counterparties' => 'Kontrahenci',
    'section_categories' => 'Kategorie',
    'section_goals_recurring' => 'Cele i płatności cykliczne',
    'no_name' => '(brak nazwy)',
    // i18n-review: pl · see_all — wszystkie against a genitive-plural count is
    // written both ways in the wild (wszystkie 5 wyników / wszystkich 5 wyników).
    // The nominative is used here; a native reader settles which this app says.
    'see_all' => 'Zobacz :count wynik →|Zobacz :count wyniki →|Zobacz wszystkie :count wyników →',
    'no_transactions' => 'Brak transakcji pasujących do „:query”',
    'source_txn' => 'txn',
    'source_counterparty' => 'kontrahent',
    'source_category' => 'kategoria',
    'results_aria' => 'Wyniki',
    'no_results' => 'Brak wyników.',
    'foot_navigate' => 'nawigacja',
    'foot_select' => 'wybór',
    'foot_close' => 'zamknij',
    'close_aria' => 'Zamknij wyszukiwanie',
    'close_caption' => 'Zamknij',
    'foot_try' => 'Spróbuj',
    'results' => ':count wynik|:count wyniki|:count wyników',

    'action' => [
        'run_import' => ['label' => 'Uruchom import', 'hint' => 'Otwórz kreator importu'],
        'scan_email' => ['label' => 'Skanuj pocztę teraz', 'hint' => 'Uruchom synchronizację skrzynki od razu'],
        'open_profile' => ['label' => 'Otwórz profil', 'hint' => 'Ustawienia — konto i preferencje'],
        'toggle_theme' => ['label' => 'Przełącz motyw', 'hint' => 'Przełączanie między jasnym a ciemnym motywem'],
    ],

    'run_command' => 'Uruchom :command',

    'nav' => [
        // i18n-review: pl · nav.overview.hint — the overview widgets are called
        // "kafelki" here; no other pl DevMode string names them, so there is no
        // in-app precedent to match.
        'overview' => ['label' => 'Przegląd dev', 'hint' => 'Kafelki systemowe + ostatnie uruchomienia'],
        // i18n-review: pl · nav.artisan.hint — "whitelisted" has no settled Polish
        // form in this app; the descriptive "z listy dozwolonych" is used. A native
        // reader settles whether the console keeps the English word instead.
        'artisan' => ['label' => 'Runner Artisan', 'hint' => 'Uruchamianie poleceń z listy dozwolonych'],
        'audit' => ['label' => 'Dziennik audytu dev', 'hint' => 'Każde działanie w Dev Mode'],
        'logs' => ['label' => 'Podgląd logów', 'hint' => 'Strumień na żywo z laravel-*.log'],
        'queue' => ['label' => 'Inspektor kolejki', 'hint' => 'Oczekujące / nieudane / partie'],
        'doctor' => ['label' => 'Doctor', 'hint' => 'Sondy systemowe'],
        'sql' => ['label' => 'Panel SQL', 'hint' => 'Przeglądarka wyłącznie typu SELECT'],
        'system' => ['label' => 'Migawka systemu', 'hint' => 'Środowisko + ścieżki + konfiguracja'],
        'horizon' => ['label' => 'Horizon', 'hint' => 'Wbudowany panel kolejki'],
        // i18n-review: pl · nav.sync_health.hint — no pl string anywhere names a
        // CRDT "merge op"; "operacje scalania" is coined here. The label itself
        // matches Sync's own "Stan synchronizacji".
        'sync_health' => ['label' => 'Stan synchronizacji', 'hint' => 'Operacje scalania w kwarantannie / pominięte'],
    ],
];
