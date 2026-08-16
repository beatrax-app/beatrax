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
    'recent_runs_aria' => 'Ostatnie uruchomienia',
    'modal_heading' => 'Uruchom polecenie SAFE',
    'modal_intro' => 'Wybierz polecenie poziomu SAFE, aby uruchomić je od razu. Poleceń DESTRUCTIVE tutaj nie ma — użyj opcji ponownego uruchomienia na osi czasu albo palety ⌘K.',
    'args_badge' => 'args',
    'args_badge_title' => 'Otwiera formularz argumentów',

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
        'arg_singular' => 'argumentu',
        'arg_plural' => 'argumentów',
        'started' => 'Uruchomiono :command (uruchomienie :runId)',
        'run_expired' => 'Rekord uruchomienia wygasł — nie można uruchomić ponownie.',
        'reran' => 'Uruchomiono ponownie :command (uruchomienie :runId)',
    ],
];
