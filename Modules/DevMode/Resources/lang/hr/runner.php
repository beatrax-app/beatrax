<?php

declare(strict_types=1);

return [
    'heading' => 'Artisan pokretač',
    'subtitle' => 'SAFE naredbe pokreni jednim klikom; DESTRUCTIVE naredbe su iza trostrukih vrata.',
    'run_a_command' => 'Pokreni naredbu',
    'filter_aria' => 'Filtar pokretanja',
    'filter' => [
        'all' => 'Sve',
        'running' => 'U tijeku',
        'failed' => 'Neuspješno',
        'destructive' => 'Destruktivno',
    ],
    'worker_running' => 'Worker reda čekanja: RADI',
    'worker_not_running' => 'Worker reda čekanja: NE RADI',
    'no_runs' => 'Još nema pokretanja. Klikni "Pokreni naredbu" ili upotrijebi paletu naredbi (⌘K).',
    'recent_runs_aria' => 'Nedavna pokretanja',
    'modal_heading' => 'Pokreni SAFE naredbu',
    'modal_intro' => 'Odaberi naredbu razine SAFE za trenutačno pokretanje. DESTRUCTIVE naredbe ovdje nisu navedene — upotrijebi ponovno pokretanje na vremenskoj crti ili paletu ⌘K.',
    'args_badge' => 'argumenti',
    'args_badge_title' => 'Otvara obrazac za argumente',

    'status' => [
        'running' => 'U tijeku',
        'done' => 'Gotovo',
        'failed' => 'Neuspješno',
        'cancelled' => 'Otkazano',
    ],
    'cancel' => 'Odustani',
    'rerun' => 'Pokreni ponovno',
    'started' => 'Pokrenuto :when',
    'exit' => 'izlaz',

    'toast' => [
        'unknown_command' => 'Nepoznata naredba: :command',
        'missing_args' => 'Nije moguće pokrenuti :command — potrebno je :noun: :list',
        'arg' => 'argument|argumenti|argumenti',
        'started' => 'Pokrenuto :command (pokretanje :runId)',
        'run_expired' => 'Zapis o pokretanju je istekao — ponovno pokretanje nije moguće.',
        'reran' => 'Ponovno pokrenuto :command (pokretanje :runId)',
    ],
];
