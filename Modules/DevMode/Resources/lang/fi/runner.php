<?php

declare(strict_types=1);

return [
    'heading' => 'Artisan-suoritin',
    'subtitle' => 'Suorita SAFE-komennot yhdellä napsautuksella; DESTRUCTIVE-komennot ovat kolminkertaisen portin takana.',
    'run_a_command' => 'Suorita komento',
    'filter_aria' => 'Suoritussuodatin',
    'filter' => [
        'all' => 'Kaikki',
        'running' => 'Käynnissä',
        'failed' => 'Epäonnistuneet',
        'destructive' => 'Tuhoavat',
    ],
    'worker_running' => 'Jonon työntekijä: KÄYNNISSÄ',
    'worker_not_running' => 'Jonon työntekijä: EI KÄYNNISSÄ',
    'no_runs' => 'Ei vielä suorituksia. Napsauta ”Suorita komento” tai käytä komentopalettia (⌘K).',
    'recent_runs_aria' => 'Viimeisimmät suoritukset',
    'modal_heading' => 'Suorita SAFE-komento',
    'modal_intro' => 'Valitse SAFE-tason komento suoritettavaksi heti. DESTRUCTIVE-komentoja ei ole listattu tässä — käytä aikajanan Suorita uudelleen -toimintoa tai ⌘K-palettia.',
    'args_badge' => 'args',
    'args_badge_title' => 'Avaa argumenttilomakkeen',

    'spawning_unavailable' => 'Artisan-komennot ajetaan erillisessä prosessissa, eikä tämä alusta anna sovelluksen käynnistää sellaista. Aja ne tietokonesovelluksessa.',

    'status' => [
        'running' => 'Käynnissä',
        'done' => 'Valmis',
        'failed' => 'Epäonnistui',
        'cancelled' => 'Peruutettu',
    ],
    'cancel' => 'Peruuta',
    'rerun' => 'Suorita uudelleen',
    'started' => 'Aloitettu :when',
    'exit' => 'exit',

    'toast' => [
        'unknown_command' => 'Tuntematon komento: :command',
        'missing_args' => 'Komentoa :command ei voi suorittaa — tarvitaan :noun: :list',
        'arg' => 'argumentti|argumentit',
        'started' => 'Aloitettiin :command (suoritus :runId)',
        'run_expired' => 'Suoritustietue vanhentui — uudelleensuoritus ei onnistu.',
        'reran' => 'Suoritettiin uudelleen :command (suoritus :runId)',
    ],
];
