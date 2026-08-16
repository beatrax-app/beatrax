<?php

declare(strict_types=1);

return [
    'heading' => 'Artisan-runner',
    'subtitle' => 'Kør SAFE-kommandoer med ét klik; DESTRUCTIVE-kommandoer ligger bag triple-gate.',
    'run_a_command' => 'Kør en kommando',
    'filter_aria' => 'Kørselsfilter',
    'filter' => [
        'all' => 'Alle',
        'running' => 'Kører',
        'failed' => 'Mislykkede',
        'destructive' => 'Destruktive',
    ],
    'worker_running' => 'Kø-worker: KØRER',
    'worker_not_running' => 'Kø-worker: KØRER IKKE',
    'no_runs' => 'Ingen kørsler endnu. Klik på "Kør en kommando", eller brug kommandopaletten (⌘K).',
    'recent_runs_aria' => 'Seneste kørsler',
    'modal_heading' => 'Kør en SAFE-kommando',
    'modal_intro' => 'Vælg en kommando på SAFE-niveau, der køres med det samme. DESTRUCTIVE-kommandoer står ikke her — brug Kør igen i tidslinjen eller ⌘K-paletten.',
    'args_badge' => 'args',
    'args_badge_title' => 'Åbner en argumentformular',

    'status' => [
        'running' => 'Kører',
        'done' => 'Færdig',
        'failed' => 'Mislykket',
        'cancelled' => 'Annulleret',
    ],
    'cancel' => 'Annullér',
    'rerun' => 'Kør igen',
    'started' => 'Startet :when',
    'exit' => 'exit',

    'toast' => [
        'unknown_command' => 'Ukendt kommando: :command',
        'missing_args' => 'Kan ikke køre :command — kræver :noun: :list',
        'arg_singular' => 'argument',
        'arg_plural' => 'argumenter',
        'started' => 'Startede :command (kørsel :runId)',
        'run_expired' => 'Kørselsposten er udløbet — kan ikke køres igen.',
        'reran' => 'Kørte :command igen (kørsel :runId)',
    ],
];
