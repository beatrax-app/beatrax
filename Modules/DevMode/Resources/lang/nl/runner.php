<?php

declare(strict_types=1);

return [
    'heading' => 'Artisan-runner',
    'subtitle' => 'Voer SAFE-commando’s met één klik uit; DESTRUCTIVE-commando’s achter de triple-gate.',
    'run_a_command' => 'Voer een commando uit',
    'filter_aria' => 'Runfilter',
    'filter' => [
        'all' => 'Alle',
        'running' => 'Bezig',
        'failed' => 'Mislukt',
        'destructive' => 'Destructief',
    ],
    'worker_running' => 'Wachtrij-worker: DRAAIT',
    'worker_not_running' => 'Wachtrij-worker: DRAAIT NIET',
    'no_runs' => 'Nog geen runs. Klik op "Voer een commando uit" of gebruik het commandopalet (⌘K).',
    'recent_runs_aria' => 'Recente runs',
    'modal_heading' => 'Voer een SAFE-commando uit',
    'modal_intro' => 'Kies een SAFE-commando om direct uit te voeren. DESTRUCTIVE-commando’s staan hier niet — gebruik de Re-run-knop in de tijdlijn of het ⌘K-palet.',
    'args_badge' => 'args',
    'args_badge_title' => 'Opent een argumentformulier',

    'spawning_unavailable' => 'Artisan-commando\'s draaien in een apart proces, en dit platform laat de app er geen starten. Voer ze uit vanaf de desktop-app.',

    'status' => [
        'running' => 'Bezig',
        'done' => 'Klaar',
        'failed' => 'Mislukt',
        'cancelled' => 'Geannuleerd',
    ],
    'cancel' => 'Annuleren',
    'rerun' => 'Opnieuw uitvoeren',
    'started' => 'Gestart :when',
    'exit' => 'exit',

    'toast' => [
        'unknown_command' => 'Onbekend commando: :command',
        'missing_args' => 'Kan :command niet uitvoeren — vereist :noun: :list',
        'arg' => 'argument|argumenten',
        'started' => 'Gestart :command (run :runId)',
        'run_expired' => 'Runrecord verlopen — opnieuw uitvoeren niet mogelijk.',
        'reran' => 'Opnieuw uitgevoerd :command (run :runId)',
    ],
];
