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
    // i18n-review: sl · no_runs_touch — the same line for a touch
    // screen; check the verb governs this case.
    'no_runs_touch' => 'Zagonov še ni. Tapni "Zaženi ukaz" ali uporabi ukazno paleto (⌘K).',
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
        'invalid_args' => 'Ukaza :command ni mogoče zagnati — :reason',
        // i18n-review: sl · toast.arg — "potrebuje" in toast.missing_args puts this
        // noun in the accusative and no numeral reaches it, so the dual rests on the
        // noun alone. Whether the singular verb reads beside it is a native call.
        'arg' => 'argument|argumenta|argumente|argumente',
        'started' => 'Začeto :command (zagon :runId)',
        'run_expired' => 'Zapis o zagonu je potekel — ponovni zagon ni mogoč.',
        'reran' => 'Znova zagnano :command (zagon :runId)',
        'rerun_forbidden' => 'Ta zagon pripada drugemu razvijalcu.',
    ],

    'command' => [
        'db_backup' => ['label' => 'Varnostno kopiraj zbirko podatkov', 'description' => 'Zapiše kopijo SQLite s časovnim žigom v mapo z varnostnimi kopijami.'],
        'doctor' => ['label' => 'Zaženi doctor', 'description' => 'Sporoči nameščene različice PHP / Composer / SQLite in preveri najnižje zahteve.'],
        'failed_jobs' => ['label' => 'Počisti neuspela opravila', 'description' => 'Odstrani razrešene vnose iz tabele failed_jobs, ki jo upravlja Laravel.'],
        'cache_clear' => ['label' => 'Počisti predpomnilnik', 'description' => 'Izprazni predpomnilnik aplikacije.'],
        // i18n-review: sl · command.route_list — «pot» is already this locale's word
        // for a filesystem path in system.php, so an HTTP route lands on the same
        // noun. A native should say whether «poti HTTP» keeps the two apart.
        'route_list' => ['label' => 'Izpiši poti HTTP', 'description' => 'Izpiše vsako registrirano pot HTTP na stdout.'],
        'config_show' => ['label' => 'Prikaži konfiguracijo', 'description' => 'Izpiše vrednost pri navedenem konfiguracijskem ključu s pikami.'],
        // i18n-review: sl · command.view_clear — «pogled» is taken by the palette's
        // own views, and the Blade template cache reuses it here. Confirm that the
        // two readings do not collide on this row.
        'view_clear' => ['label' => 'Počisti predpomnilnik pogledov', 'description' => 'Izprazni predpomnilnik prevedenih pogledov Blade.'],
        'queue_retry' => ['label' => 'Znova poskusi neuspela opravila', 'description' => 'Znova poskusi eno opravilo (po id) ali vsako neuspelo opravilo (prazen id).'],
        'rederive_fingerprints' => ['label' => 'Znova izpelji prstne odtise', 'description' => 'Znova izračuna prstni odtis vsake transakcije z veljavno različico normalizacije.'],
        'db_restore' => ['label' => 'Obnovi zbirko podatkov', 'description' => 'Trenutno zbirko podatkov zamenja z navedeno datoteko varnostne kopije.'],
        'regenerate_recovery_codes' => ['label' => 'Znova ustvari kode za obnovitev', 'description' => 'Znova ustvari 10 enkratnih kod za obnovitev za uporabnika.'],
        'grant_dev' => ['label' => 'Dodeli razvijalski dostop', 'description' => 'Za navedenega uporabnika nastavi is_developer=true.'],
        'install' => ['label' => 'Zaženi namestitev', 'description' => 'Idempotentna prva nastavitev. Ponovni zagon na že nastavljeni namestitvi je uničujoč.'],
    ],

    'arg' => [
        'action' => ['label' => 'Dejanje'],
        'config' => ['label' => 'Konfiguracijski ključ', 'help' => 'Konfiguracijska datoteka ali ključ s pikami za izpis, npr. `app` ali `database.connections.sqlite`.', 'placeholder' => 'app.name'],
        'id' => ['label' => 'Id opravila', 'help' => 'Pusti prazno, da znova poskusiš vsako neuspelo opravilo; z navedenim id-jem znova poskusiš en sam vnos.', 'placeholder' => 'vse (ali določen id)'],
        'queue' => ['label' => 'Ime čakalne vrste', 'help' => 'Izbirni filter čakalne vrste; privzeto vse vrste.', 'placeholder' => 'default'],
        'path' => ['label' => 'Pot do datoteke varnostne kopije', 'help' => 'Trenutno zbirko podatkov zamenja z datoteko na navedeni poti.', 'placeholder' => '/pot/do/backup.sqlite'],
        'username' => ['label' => 'Uporabniško ime', 'placeholder' => 'alice'],
    ],
];
