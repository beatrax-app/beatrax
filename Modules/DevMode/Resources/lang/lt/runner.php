<?php

declare(strict_types=1);

return [
    'heading' => 'Artisan vykdyklė',
    'subtitle' => 'SAUGIAS komandas vykdyk vienu spustelėjimu; ARDOMOSIOS komandos apsaugotos trigubu užraktu.',
    'run_a_command' => 'Vykdyti komandą',
    'filter_aria' => 'Vykdymų filtras',
    'filter' => [
        'all' => 'Visi',
        'running' => 'Vykdoma',
        'failed' => 'Nepavyko',
        'destructive' => 'Ardomosios',
    ],
    'worker_running' => 'Eilės vykdytojas: VEIKIA',
    'worker_not_running' => 'Eilės vykdytojas: NEVEIKIA',
    'no_runs' => 'Vykdymų dar nėra. Spustelėk „Vykdyti komandą“ arba naudok komandų paletę (⌘K).',
    'recent_runs_aria' => 'Naujausi vykdymai',
    'modal_heading' => 'Vykdyti SAUGIĄ komandą',
    'modal_intro' => 'Pasirink SAUGAUS lygio komandą, kuri bus įvykdyta iš karto. ARDOMOSIOS komandos čia nerodomos — naudok laiko juostos mygtuką „Paleisti iš naujo“ arba ⌘K paletę.',
    'args_badge' => 'arg.',
    'args_badge_title' => 'Atveria argumentų formą',

    'spawning_unavailable' => 'Artisan komandos veikia atskirame procese, o ši platforma neleidžia programai jo paleisti. Paleisk jas kompiuterio programoje.',

    'status' => [
        'running' => 'Vykdoma',
        'done' => 'Atlikta',
        'failed' => 'Nepavyko',
        'cancelled' => 'Atšaukta',
    ],
    'cancel' => 'Atšaukti',
    'rerun' => 'Paleisti iš naujo',
    'started' => 'Pradėta :when',
    'exit' => 'išėjimo kodas',

    'toast' => [
        'unknown_command' => 'Nežinoma komanda: :command',
        'missing_args' => 'Nepavyksta įvykdyti :command — reikia :noun: :list',
        'arg' => 'argumento|argumentų|argumentų',
        'started' => 'Pradėta :command (vykdymas :runId)',
        'run_expired' => 'Vykdymo įrašas nebegalioja — pakartoti negalima.',
        'reran' => 'Pakartota :command (vykdymas :runId)',
    ],
];
