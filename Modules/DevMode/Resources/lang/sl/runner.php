<?php

declare(strict_types=1);

return [
    'heading' => 'Zaganjalnik Artisan',
    'subtitle' => 'Ukaze SAFE zaženi z enim klikom; ukazi DESTRUCTIVE so za trojnimi vrati.',
    'run_a_command' => 'Zaženi ukaz',
    'filter_aria' => 'Filter zagonov',
    'filter' => [
        'all' => 'Vse',
        'running' => 'Se izvaja',
        'failed' => 'Neuspešno',
        'destructive' => 'Uničujoče',
    ],
    'worker_running' => 'Worker čakalne vrste: DELUJE',
    'worker_not_running' => 'Worker čakalne vrste: NE DELUJE',
    'no_runs' => 'Zagonov še ni. Klikni "Zaženi ukaz" ali uporabi ukazno paleto (⌘K).',
    'recent_runs_aria' => 'Nedavni zagoni',
    'modal_heading' => 'Zaženi ukaz SAFE',
    'modal_intro' => 'Izberi ukaz ravni SAFE za takojšen zagon. Ukazi DESTRUCTIVE tu niso navedeni — uporabi ponovni zagon na časovnici ali paleto ⌘K.',
    'args_badge' => 'argumenti',
    'args_badge_title' => 'Odpre obrazec za argumente',

    'spawning_unavailable' => 'Ukazi Artisan tečejo v ločenem procesu, ta platforma pa aplikaciji ne dovoli, da bi ga zagnala. Zaženi jih v namizni aplikaciji.',

    'status' => [
        'running' => 'Se izvaja',
        'done' => 'Končano',
        'failed' => 'Neuspešno',
        'cancelled' => 'Preklicano',
    ],
    'cancel' => 'Prekliči',
    'rerun' => 'Zaženi znova',
    'started' => 'Začeto :when',
    'exit' => 'izhod',

    'toast' => [
        'unknown_command' => 'Neznan ukaz: :command',
        'missing_args' => 'Ukaza :command ni mogoče zagnati — potrebuje :noun: :list',
        // i18n-review: sl · toast.arg — "potrebuje" in toast.missing_args puts this
        // noun in the accusative and no numeral reaches it, so the dual rests on the
        // noun alone. Whether the singular verb reads beside it is a native call.
        'arg' => 'argument|argumenta|argumente|argumente',
        'started' => 'Začeto :command (zagon :runId)',
        'run_expired' => 'Zapis o zagonu je potekel — ponovni zagon ni mogoč.',
        'reran' => 'Znova zagnano :command (zagon :runId)',
    ],
];
