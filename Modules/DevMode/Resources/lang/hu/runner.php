<?php

declare(strict_types=1);

return [
    'heading' => 'Artisan runner',
    'subtitle' => 'A SAFE parancsokat egy kattintással futtathatod; a DESTRUCTIVE parancsok a hármas zár mögött vannak.',
    'run_a_command' => 'Parancs futtatása',
    'filter_aria' => 'Futtatásszűrő',
    'filter' => [
        'all' => 'Összes',
        'running' => 'Fut',
        'failed' => 'Sikertelen',
        'destructive' => 'Destruktív',
    ],
    'worker_running' => 'Várólista-worker: FUT',
    'worker_not_running' => 'Várólista-worker: NEM FUT',
    'no_runs' => 'Még nincs futtatás. Kattints a "Parancs futtatása" gombra, vagy használd a parancspalettát (⌘K).',
    'recent_runs_aria' => 'Legutóbbi futtatások',
    'modal_heading' => 'SAFE parancs futtatása',
    'modal_intro' => 'Válassz egy SAFE szintű parancsot az azonnali futtatáshoz. A DESTRUCTIVE parancsok itt nem szerepelnek — használd az idővonal újrafuttatás lehetőségét vagy a ⌘K palettát.',
    'args_badge' => 'args',
    'args_badge_title' => 'Argumentum-űrlapot nyit meg',

    'spawning_unavailable' => 'Az Artisan-parancsok külön folyamatban futnak, és ez a platform nem engedi az alkalmazásnak, hogy elindítson egyet. Futtasd őket az asztali alkalmazásból.',

    'status' => [
        'running' => 'Fut',
        'done' => 'Kész',
        'failed' => 'Sikertelen',
        'cancelled' => 'Megszakítva',
    ],
    'cancel' => 'Mégse',
    'rerun' => 'Újrafuttatás',
    'started' => 'Elindítva :when',
    'exit' => 'kilépési kód',

    'toast' => [
        'unknown_command' => 'Ismeretlen parancs: :command',
        'missing_args' => 'A(z) :command nem futtatható — hiányzó :noun: :list',
        'arg' => 'argumentum|argumentumok',
        'started' => 'Elindítva: :command (futtatás: :runId)',
        'run_expired' => 'A futtatási bejegyzés lejárt — nem lehet újrafuttatni.',
        'reran' => 'Újrafuttatva: :command (futtatás: :runId)',
    ],
];
