<?php

declare(strict_types=1);

return [
    'heading' => 'Runner Artisan',
    'subtitle' => 'Polecenia SAFE uruchamiaj jednym kliknięciem; polecenia DESTRUCTIVE — za potrójną bramką.',
    'run_a_command' => 'Uruchom polecenie',
    'filter_aria' => 'Filtr uruchomień',
    'filter' => [
        'all' => 'Wszystkie',
        'running' => 'W trakcie',
        'failed' => 'Nieudane',
        'destructive' => 'Destrukcyjne',
    ],
    'worker_running' => 'Worker kolejki: DZIAŁA',
    'worker_not_running' => 'Worker kolejki: NIE DZIAŁA',
    'no_runs' => 'Brak uruchomień. Kliknij „Uruchom polecenie” lub użyj palety poleceń (⌘K).',
    // i18n-review: pl · no_runs_touch — the same line for a touch
    // screen; check the verb governs this case.
    'no_runs_touch' => 'Brak uruchomień. Dotknij „Uruchom polecenie” lub użyj palety poleceń (⌘K).',
    'recent_runs_aria' => 'Ostatnie uruchomienia',
    'modal_heading' => 'Uruchom polecenie SAFE',
    'modal_intro' => 'Wybierz polecenie poziomu SAFE, aby uruchomić je od razu. Poleceń DESTRUCTIVE tutaj nie ma — użyj opcji ponownego uruchomienia na osi czasu albo palety ⌘K.',
    'args_badge' => 'args',
    'args_badge_title' => 'Otwiera formularz argumentów',

    'spawning_unavailable' => 'Polecenia Artisan działają w osobnym procesie, a ta platforma nie pozwala aplikacji go uruchomić. Uruchom je w aplikacji na komputer.',

    'status' => [
        'running' => 'W trakcie',
        'done' => 'Gotowe',
        'failed' => 'Nieudane',
        'cancelled' => 'Anulowane',
    ],
    'cancel' => 'Anuluj',
    'rerun' => 'Uruchom ponownie',
    'started' => 'Rozpoczęto :when',
    'exit' => 'kod wyjścia',

    'toast' => [
        'unknown_command' => 'Nieznane polecenie: :command',
        'missing_args' => 'Nie można uruchomić polecenia :command — wymaga :noun: :list',
        'invalid_args' => 'Nie można uruchomić polecenia :command — :reason',
        'arg' => 'argumentu|argumentów|argumentów',
        'started' => 'Uruchomiono :command (uruchomienie :runId)',
        'run_expired' => 'Rekord uruchomienia wygasł — nie można uruchomić ponownie.',
        'reran' => 'Uruchomiono ponownie :command (uruchomienie :runId)',
        'rerun_forbidden' => 'To uruchomienie należy do innego programisty.',
    ],

    'command' => [
        'db_backup' => ['label' => 'Utwórz kopię zapasową bazy', 'description' => 'Zapisuje kopię SQLite ze znacznikiem czasu do katalogu kopii zapasowych.'],
        'doctor' => ['label' => 'Uruchom doctor', 'description' => 'Raportuje zainstalowane wersje PHP / Composer / SQLite i sprawdza wymagane minima.'],
        'failed_jobs' => ['label' => 'Wyczyść nieudane zadania', 'description' => 'Usuwa rozwiązane wpisy z tabeli failed_jobs zarządzanej przez Laravela.'],
        'cache_clear' => ['label' => 'Wyczyść pamięć podręczną', 'description' => 'Opróżnia pamięć podręczną aplikacji.'],
        'route_list' => ['label' => 'Wypisz trasy', 'description' => 'Wypisuje każdą zarejestrowaną trasę HTTP na stdout.'],
        'config_show' => ['label' => 'Pokaż konfigurację', 'description' => 'Wypisuje wartość spod podanego klucza konfiguracji zapisanego z kropkami.'],
        'view_clear' => ['label' => 'Wyczyść pamięć podręczną widoków', 'description' => 'Opróżnia pamięć podręczną skompilowanych widoków Blade.'],
        'queue_retry' => ['label' => 'Ponów nieudane zadania', 'description' => 'Ponawia jedno zadanie (po id) albo każde nieudane zadanie (puste id).'],
        'rederive_fingerprints' => ['label' => 'Przelicz odciski ponownie', 'description' => 'Przelicza odcisk każdej transakcji przy użyciu bieżącej wersji normalizacji.'],
        'db_restore' => ['label' => 'Przywróć bazę danych', 'description' => 'Zastępuje bieżącą bazę danych podanym plikiem kopii zapasowej.'],
        'regenerate_recovery_codes' => ['label' => 'Wygeneruj ponownie kody odzyskiwania', 'description' => 'Generuje od nowa 10 jednorazowych kodów odzyskiwania użytkownika.'],
        'grant_dev' => ['label' => 'Przyznaj dostęp deweloperski', 'description' => 'Ustawia is_developer=true dla podanego użytkownika.'],
        'install' => ['label' => 'Uruchom instalację', 'description' => 'Idempotentna konfiguracja przy pierwszym uruchomieniu. Ponowne uruchomienie na skonfigurowanej instalacji jest destrukcyjne.'],
    ],

    'arg' => [
        'action' => ['label' => 'Akcja'],
        'config' => ['label' => 'Klucz konfiguracji', 'help' => 'Plik konfiguracyjny lub klucz z kropkami do wypisania, np. `app` albo `database.connections.sqlite`.', 'placeholder' => 'app.name'],
        'id' => ['label' => 'Id zadania', 'help' => 'Zostaw puste, aby ponowić każde nieudane zadanie; podaj id, aby ponowić pojedynczy wpis.', 'placeholder' => 'wszystkie (lub konkretne id)'],
        'queue' => ['label' => 'Nazwa kolejki', 'help' => 'Opcjonalny filtr kolejki; domyślnie wszystkie kolejki.', 'placeholder' => 'default'],
        'path' => ['label' => 'Ścieżka pliku kopii zapasowej', 'help' => 'Zastępuje bieżącą bazę danych plikiem spod podanej ścieżki.', 'placeholder' => '/ścieżka/do/backup.sqlite'],
        'username' => ['label' => 'Nazwa użytkownika', 'placeholder' => 'alice'],
    ],
];
