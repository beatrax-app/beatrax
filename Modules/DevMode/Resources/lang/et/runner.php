<?php

declare(strict_types=1);

return [
    'heading' => 'Artisani käivitaja',
    'subtitle' => 'Käivita OHUTUD käsud ühe klõpsuga; HÄVITAVAD käsud on kolmekordse kaitse taga.',
    'run_a_command' => 'Käivita käsk',
    'filter_aria' => 'Käivituste filter',
    'filter' => [
        'all' => 'Kõik',
        'running' => 'Töötab',
        'failed' => 'Ebaõnnestunud',
        'destructive' => 'Hävitav',
    ],
    'worker_running' => 'Järjekorra töötaja: TÖÖTAB',
    'worker_not_running' => 'Järjekorra töötaja: EI TÖÖTA',
    'no_runs' => 'Käivitusi veel pole. Klõpsa „Käivita käsk“ või kasuta käsupaletti (⌘K).',
    'recent_runs_aria' => 'Hiljutised käivitused',
    'modal_heading' => 'Käivita OHUTU käsk',
    'modal_intro' => 'Vali OHUTU taseme käsk, mis käivitub kohe. HÄVITAVAID käske siin ei loetleta — kasuta ajajoone uuesti käivitamise nuppu või ⌘K paletti.',
    'args_badge' => 'argumendid',
    'args_badge_title' => 'Avab argumentide vormi',

    'spawning_unavailable' => 'Artisani käsud töötavad eraldi protsessis ja see platvorm ei lase rakendusel ühtegi käivitada. Käivita need arvutirakendusest.',

    'status' => [
        'running' => 'Töötab',
        'done' => 'Valmis',
        'failed' => 'Ebaõnnestus',
        'cancelled' => 'Tühistatud',
    ],
    'cancel' => 'Tühista',
    'rerun' => 'Käivita uuesti',
    'started' => 'Alustatud :when',
    'exit' => 'väljumine',

    'toast' => [
        'unknown_command' => 'Tundmatu käsk: :command',
        'missing_args' => 'Käsku :command ei saa käivitada — vaja on :noun: :list',
        'arg' => 'argument|argumendid',
        'started' => 'Käivitatud :command (käivitus :runId)',
        'run_expired' => 'Käivituse kirje on aegunud — uuesti käivitada ei saa.',
        'reran' => 'Käivitatud uuesti :command (käivitus :runId)',
    ],
];
