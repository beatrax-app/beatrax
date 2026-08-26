<?php

declare(strict_types=1);

return [
    'heading' => 'Artisan-runner',
    'subtitle' => 'Kör SAFE-kommandon med ett klick; DESTRUCTIVE-kommandon ligger bakom triple-gate.',
    'run_a_command' => 'Kör ett kommando',
    'filter_aria' => 'Körningsfilter',
    'filter' => [
        'all' => 'Alla',
        'running' => 'Körs',
        'failed' => 'Misslyckade',
        'destructive' => 'Destruktiva',
    ],
    'worker_running' => 'Köarbetare: KÖRS',
    'worker_not_running' => 'Köarbetare: KÖRS INTE',
    'no_runs' => 'Inga körningar än. Klicka på "Kör ett kommando" eller använd kommandopaletten (⌘K).',
    'recent_runs_aria' => 'Senaste körningar',
    'modal_heading' => 'Kör ett SAFE-kommando',
    'modal_intro' => 'Välj ett kommando på SAFE-nivå som körs direkt. DESTRUCTIVE-kommandon listas inte här — använd Kör igen i tidslinjen eller ⌘K-paletten.',
    'args_badge' => 'args',
    'args_badge_title' => 'Öppnar ett argumentformulär',

    'spawning_unavailable' => 'Artisan-kommandon körs i en separat process, och den här plattformen låter inte appen starta någon. Kör dem från datorappen i stället.',

    'status' => [
        'running' => 'Körs',
        'done' => 'Klar',
        'failed' => 'Misslyckades',
        'cancelled' => 'Avbruten',
    ],
    'cancel' => 'Avbryt',
    'rerun' => 'Kör igen',
    'started' => 'Startade :when',
    'exit' => 'exit',

    'toast' => [
        'unknown_command' => 'Okänt kommando: :command',
        'missing_args' => 'Kan inte köra :command — kräver :noun: :list',
        'arg' => 'argument|argument',
        'started' => 'Startade :command (körning :runId)',
        'run_expired' => 'Körningsposten har upphört — kan inte köras igen.',
        'reran' => 'Körde :command igen (körning :runId)',
    ],
];
