<?php

declare(strict_types=1);

return [
    'heading' => 'Artisan runner',
    'subtitle' => 'Příkazy SAFE spouštěj jedním kliknutím; příkazy DESTRUCTIVE jsou za trojitou pojistkou.',
    'run_a_command' => 'Spustit příkaz',
    'filter_aria' => 'Filtr spuštění',
    'filter' => [
        'all' => 'Vše',
        'running' => 'Běží',
        'failed' => 'Selhalo',
        'destructive' => 'Destruktivní',
    ],
    'worker_running' => 'Worker fronty: BĚŽÍ',
    'worker_not_running' => 'Worker fronty: NEBĚŽÍ',
    'no_runs' => 'Zatím žádná spuštění. Klikni na „Spustit příkaz“ nebo použij paletu příkazů (⌘K).',
    'recent_runs_aria' => 'Nedávná spuštění',
    'modal_heading' => 'Spustit příkaz SAFE',
    'modal_intro' => 'Vyber příkaz úrovně SAFE a spusť ho hned. Příkazy DESTRUCTIVE tady nejsou — použij opětovné spuštění v časové ose nebo paletu ⌘K.',
    'args_badge' => 'args',
    'args_badge_title' => 'Otevře formulář argumentů',

    'spawning_unavailable' => 'Příkazy Artisan běží v samostatném procesu a tato platforma aplikaci nedovolí žádný spustit. Spusť je z desktopové aplikace.',

    'status' => [
        'running' => 'Běží',
        'done' => 'Hotovo',
        'failed' => 'Selhalo',
        'cancelled' => 'Zrušeno',
    ],
    'cancel' => 'Zrušit',
    'rerun' => 'Spustit znovu',
    'started' => 'Spuštěno :when',
    'exit' => 'exit',

    'toast' => [
        'unknown_command' => 'Neznámý příkaz: :command',
        'missing_args' => 'Nelze spustit :command — chybí :noun: :list',
        'arg' => 'argument|argumenty|argumenty',
        'started' => 'Spuštěno :command (běh :runId)',
        'run_expired' => 'Záznam o spuštění vypršel — nelze spustit znovu.',
        'reran' => 'Znovu spuštěno :command (běh :runId)',
    ],
];
