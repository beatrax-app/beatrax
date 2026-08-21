<?php

declare(strict_types=1);

return [
    'heading' => 'Artisan-runner',
    'subtitle' => 'Kjør SAFE-kommandoer med ett klikk; DESTRUCTIVE-kommandoer ligger bak triple-gate.',
    'run_a_command' => 'Kjør en kommando',
    'filter_aria' => 'Kjøringsfilter',
    'filter' => [
        'all' => 'Alle',
        'running' => 'Kjører',
        'failed' => 'Feilet',
        'destructive' => 'Destruktive',
    ],
    'worker_running' => 'Køarbeider: KJØRER',
    'worker_not_running' => 'Køarbeider: KJØRER IKKE',
    'no_runs' => 'Ingen kjøringer ennå. Klikk på "Kjør en kommando" eller bruk kommandopaletten (⌘K).',
    'recent_runs_aria' => 'Siste kjøringer',
    'modal_heading' => 'Kjør en SAFE-kommando',
    'modal_intro' => 'Velg en kommando på SAFE-nivå som kjøres umiddelbart. DESTRUCTIVE-kommandoer er ikke listet her — bruk Kjør på nytt i tidslinjen eller ⌘K-paletten.',
    'args_badge' => 'args',
    'args_badge_title' => 'Åpner et argumentskjema',

    'status' => [
        'running' => 'Kjører',
        'done' => 'Ferdig',
        'failed' => 'Feilet',
        'cancelled' => 'Avbrutt',
    ],
    'cancel' => 'Avbryt',
    'rerun' => 'Kjør på nytt',
    'started' => 'Startet :when',
    'exit' => 'exit',

    'toast' => [
        'unknown_command' => 'Ukjent kommando: :command',
        'missing_args' => 'Kan ikke kjøre :command — krever :noun: :list',
        'arg' => 'argument|argumenter',
        'started' => 'Startet :command (kjøring :runId)',
        'run_expired' => 'Kjøringsoppføringen er utløpt — kan ikke kjøres på nytt.',
        'reran' => 'Kjørte :command på nytt (kjøring :runId)',
    ],
];
