<?php

declare(strict_types=1);

return [
    'heading' => 'Runner Artisan',
    'subtitle' => 'Rulează comenzile SAFE cu un clic; comenzile DESTRUCTIVE trec prin tripla barieră.',
    'run_a_command' => 'Rulează o comandă',
    'filter_aria' => 'Filtru de rulări',
    'filter' => [
        'all' => 'Toate',
        'running' => 'În rulare',
        'failed' => 'Eșuate',
        'destructive' => 'Distructive',
    ],
    'worker_running' => 'Worker de coadă: RULEAZĂ',
    'worker_not_running' => 'Worker de coadă: NU RULEAZĂ',
    'no_runs' => 'Nicio rulare încă. Apasă pe "Rulează o comandă" sau folosește paleta de comenzi (⌘K).',
    'recent_runs_aria' => 'Rulări recente',
    'modal_heading' => 'Rulează o comandă SAFE',
    'modal_intro' => 'Alege o comandă de nivel SAFE ca să ruleze imediat. Comenzile DESTRUCTIVE nu apar aici — folosește opțiunea de rulare din nou din cronologie sau paleta ⌘K.',
    'args_badge' => 'args',
    'args_badge_title' => 'Deschide un formular de argumente',

    'spawning_unavailable' => 'Comenzile Artisan rulează într-un proces separat, iar această platformă nu lasă aplicația să pornească unul. Rulează-le din aplicația pentru computer.',

    'status' => [
        'running' => 'În rulare',
        'done' => 'Gata',
        'failed' => 'Eșuat',
        'cancelled' => 'Anulat',
    ],
    'cancel' => 'Anulează',
    'rerun' => 'Rulează din nou',
    'started' => 'Pornit :when',
    'exit' => 'cod de ieșire',

    'toast' => [
        'unknown_command' => 'Comandă necunoscută: :command',
        'missing_args' => 'Nu se poate rula :command — are nevoie de :noun: :list',
        'arg' => 'argument|argumente|argumente',
        'started' => 'Pornit :command (rulare :runId)',
        'run_expired' => 'Înregistrarea rulării a expirat — nu se poate rula din nou.',
        'reran' => 'Rulat din nou :command (rulare :runId)',
    ],
];
