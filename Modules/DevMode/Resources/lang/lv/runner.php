<?php

declare(strict_types=1);

return [
    'heading' => 'Artisan izpildītājs',
    'subtitle' => 'Izpildiet DROŠĀS komandas ar vienu klikšķi; DESTRUKTĪVĀS komandas ir aiz trīskāršās aizsardzības.',
    'run_a_command' => 'Izpildīt komandu',
    'filter_aria' => 'Izpilžu filtrs',
    'filter' => [
        'all' => 'Visas',
        'running' => 'Izpildās',
        'failed' => 'Neizdevās',
        'destructive' => 'Destruktīvās',
    ],
    'worker_running' => 'Rindas darbinieks: DARBOJAS',
    'worker_not_running' => 'Rindas darbinieks: NEDARBOJAS',
    'no_runs' => 'Vēl nav nevienas izpildes. Noklikšķiniet „Izpildīt komandu” vai izmantojiet komandu paleti (⌘K).',
    'recent_runs_aria' => 'Nesenās izpildes',
    'modal_heading' => 'Izpildīt DROŠU komandu',
    'modal_intro' => 'Izvēlieties DROŠĀ līmeņa komandu, ko izpildīt uzreiz. DESTRUKTĪVĀS komandas šeit nav uzskaitītas — izmantojiet laika joslas atkārtotās izpildes pogu vai ⌘K paleti.',
    'args_badge' => 'argumenti',
    'args_badge_title' => 'Atver argumentu formu',

    'status' => [
        'running' => 'Izpildās',
        'done' => 'Pabeigts',
        'failed' => 'Neizdevās',
        'cancelled' => 'Atcelts',
    ],
    'cancel' => 'Atcelt',
    'rerun' => 'Palaist vēlreiz',
    'started' => 'Sākts :when',
    'exit' => 'izeja',

    'toast' => [
        'unknown_command' => 'Nezināma komanda: :command',
        'missing_args' => 'Nevar izpildīt :command — trūkst :noun: :list',
        'arg_singular' => 'argumenta',
        'arg_plural' => 'argumentu',
        'started' => 'Sākts :command (izpilde :runId)',
        'run_expired' => 'Izpildes ieraksts ir novecojis — nevar palaist vēlreiz.',
        'reran' => 'Atkārtoti izpildīts :command (izpilde :runId)',
    ],
];
