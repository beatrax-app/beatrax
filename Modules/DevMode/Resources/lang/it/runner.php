<?php

declare(strict_types=1);

return [
    'heading' => 'Runner Artisan',
    'subtitle' => 'Esegui i comandi SAFE con un clic; i comandi DESTRUCTIVE passano dal triple-gate.',
    'run_a_command' => 'Esegui un comando',
    'filter_aria' => 'Filtro delle esecuzioni',
    'filter' => [
        'all' => 'Tutte',
        'running' => 'In esecuzione',
        'failed' => 'Fallite',
        'destructive' => 'Distruttive',
    ],
    'worker_running' => 'Worker della coda: IN ESECUZIONE',
    'worker_not_running' => 'Worker della coda: NON IN ESECUZIONE',
    'no_runs' => 'Ancora nessuna esecuzione. Fai clic su "Esegui un comando" oppure usa la palette dei comandi (⌘K).',
    'recent_runs_aria' => 'Esecuzioni recenti',
    'modal_heading' => 'Esegui un comando SAFE',
    'modal_intro' => "Scegli un comando di livello SAFE da eseguire subito. I comandi DESTRUCTIVE non sono elencati qui — usa l'opzione Riesegui nella timeline oppure la palette ⌘K.",
    'args_badge' => 'args',
    'args_badge_title' => 'Apre un modulo per gli argomenti',

    'status' => [
        'running' => 'In esecuzione',
        'done' => 'Completata',
        'failed' => 'Fallita',
        'cancelled' => 'Annullata',
    ],
    'cancel' => 'Annulla',
    'rerun' => 'Riesegui',
    'started' => 'Avviata :when',
    'exit' => 'exit',

    'toast' => [
        'unknown_command' => 'Comando sconosciuto: :command',
        'missing_args' => 'Impossibile eseguire :command — richiede :noun: :list',
        'arg_singular' => 'argomento',
        'arg_plural' => 'argomenti',
        'started' => 'Avviato :command (esecuzione :runId)',
        'run_expired' => 'Record di esecuzione scaduto — impossibile rieseguire.',
        'reran' => 'Rieseguito :command (esecuzione :runId)',
    ],
];
