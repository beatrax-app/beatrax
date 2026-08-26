<?php

declare(strict_types=1);

return [
    'heading' => 'Artisan runner',
    'subtitle' => 'Príkazy SAFE spúšťaj jedným kliknutím; príkazy DESTRUCTIVE sú za trojitou bránou.',
    'run_a_command' => 'Spustiť príkaz',
    'filter_aria' => 'Filter spustení',
    'filter' => [
        'all' => 'Všetky',
        'running' => 'Prebieha',
        'failed' => 'Zlyhané',
        'destructive' => 'Deštruktívne',
    ],
    'worker_running' => 'Queue worker: BEŽÍ',
    'worker_not_running' => 'Queue worker: NEBEŽÍ',
    'no_runs' => 'Zatiaľ žiadne spustenia. Klikni na „Spustiť príkaz“ alebo použi paletu príkazov (⌘K).',
    'recent_runs_aria' => 'Nedávne spustenia',
    'modal_heading' => 'Spustiť príkaz SAFE',
    'modal_intro' => 'Vyber príkaz úrovne SAFE a spusti ho hneď. Príkazy DESTRUCTIVE tu nie sú — použi možnosť opätovného spustenia na časovej osi alebo paletu ⌘K.',
    'args_badge' => 'args',
    'args_badge_title' => 'Otvorí formulár argumentov',

    'spawning_unavailable' => 'Príkazy Artisan bežia v samostatnom procese a táto platforma aplikácii nedovolí žiadny spustiť. Spusti ich z počítačovej aplikácie.',

    'status' => [
        'running' => 'Prebieha',
        'done' => 'Hotovo',
        'failed' => 'Zlyhalo',
        'cancelled' => 'Zrušené',
    ],
    'cancel' => 'Zrušiť',
    'rerun' => 'Spustiť znova',
    'started' => 'Spustené :when',
    'exit' => 'exit',

    'toast' => [
        'unknown_command' => 'Neznámy príkaz: :command',
        'missing_args' => 'Príkaz :command sa nedá spustiť — vyžaduje :noun: :list',
        'arg' => 'argument|argumenty|argumenty',
        'started' => 'Spustené :command (spustenie :runId)',
        'run_expired' => 'Záznam o spustení expiroval — nedá sa spustiť znova.',
        'reran' => 'Znova spustené :command (spustenie :runId)',
    ],
];
