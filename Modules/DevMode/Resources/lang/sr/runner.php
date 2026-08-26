<?php

declare(strict_types=1);

return [
    'heading' => 'Artisan pokretač',
    'subtitle' => 'SAFE komande pokreni jednim klikom; DESTRUCTIVE komande su iza trostrukih vrata.',
    'run_a_command' => 'Pokreni komandu',
    'filter_aria' => 'Filter pokretanja',
    'filter' => [
        'all' => 'Sve',
        'running' => 'U toku',
        'failed' => 'Neuspešno',
        'destructive' => 'Destruktivno',
    ],
    'worker_running' => 'Worker reda čekanja: RADI',
    'worker_not_running' => 'Worker reda čekanja: NE RADI',
    'no_runs' => 'Još nema pokretanja. Klikni "Pokreni komandu" ili iskoristi paletu komandi (⌘K).',
    'recent_runs_aria' => 'Nedavna pokretanja',
    'modal_heading' => 'Pokreni SAFE komandu',
    'modal_intro' => 'Izaberi komandu nivoa SAFE za trenutno pokretanje. DESTRUCTIVE komande ovde nisu navedene — iskoristi ponovno pokretanje na vremenskoj liniji ili paletu ⌘K.',
    'args_badge' => 'argumenti',
    'args_badge_title' => 'Otvara formu za argumente',

    'spawning_unavailable' => 'Artisan komande se izvršavaju u zasebnom procesu, a ova platforma ne dozvoljava aplikaciji da ga pokrene. Pokreni ih iz aplikacije za računar.',

    'status' => [
        'running' => 'U toku',
        'done' => 'Gotovo',
        'failed' => 'Neuspešno',
        'cancelled' => 'Otkazano',
    ],
    'cancel' => 'Otkaži',
    'rerun' => 'Pokreni ponovo',
    'started' => 'Pokrenuto :when',
    'exit' => 'izlaz',

    'toast' => [
        'unknown_command' => 'Nepoznata komanda: :command',
        'missing_args' => 'Nije moguće pokrenuti :command — potrebno je :noun: :list',
        'arg' => 'argument|argumenti|argumenti',
        'started' => 'Pokrenuto :command (pokretanje :runId)',
        'run_expired' => 'Zapis o pokretanju je istekao — ponovno pokretanje nije moguće.',
        'reran' => 'Ponovo pokrenuto :command (pokretanje :runId)',
    ],
];
