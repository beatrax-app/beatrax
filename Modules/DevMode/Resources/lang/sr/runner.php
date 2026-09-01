<?php

declare(strict_types=1);

return [
    'heading' => 'Artisan pokretač',
    'subtitle' => 'SAFE komande pokreni jednim klikom; DESTRUCTIVE komande su iza trostrukih vrata.',
    'run_a_command' => 'Pokreni komandu',
    'filter_aria' => 'Filter pokretanja',
    'filter' => [
        'all' => 'Sve',
        'running' => 'U toku',
        'failed' => 'Neuspešno',
        'destructive' => 'Destruktivno',
    ],
    'worker_running' => 'Worker reda čekanja: RADI',
    'worker_not_running' => 'Worker reda čekanja: NE RADI',
    'no_runs' => 'Još nema pokretanja. Klikni "Pokreni komandu" ili iskoristi paletu komandi (⌘K).',
    // i18n-review: sr · no_runs_touch — the same line for a touch
    // screen; check the verb governs this case.
    'no_runs_touch' => 'Još nema pokretanja. Dodirni "Pokreni komandu" ili iskoristi paletu komandi (⌘K).',
    'recent_runs_aria' => 'Nedavna pokretanja',
    'modal_heading' => 'Pokreni SAFE komandu',
    'modal_intro' => 'Izaberi komandu nivoa SAFE za trenutno pokretanje. DESTRUCTIVE komande ovde nisu navedene — iskoristi ponovno pokretanje na vremenskoj liniji ili paletu ⌘K.',
    'args_badge' => 'argumenti',
    'args_badge_title' => 'Otvara formu za argumente',

    'spawning_unavailable' => 'Artisan komande se izvršavaju u zasebnom procesu, a ova platforma ne dozvoljava aplikaciji da ga pokrene. Pokreni ih iz aplikacije za računar.',

    'status' => [
        'running' => 'U toku',
        'done' => 'Gotovo',
        'failed' => 'Neuspešno',
        'cancelled' => 'Otkazano',
    ],
    'cancel' => 'Otkaži',
    'rerun' => 'Pokreni ponovo',
    'started' => 'Pokrenuto :when',
    'exit' => 'izlaz',

    'toast' => [
        'unknown_command' => 'Nepoznata komanda: :command',
        'missing_args' => 'Nije moguće pokrenuti :command — potrebno je :noun: :list',
        'invalid_args' => 'Nije moguće pokrenuti :command — :reason',
        'arg' => 'argument|argumenti|argumenti',
        'started' => 'Pokrenuto :command (pokretanje :runId)',
        'run_expired' => 'Zapis o pokretanju je istekao — ponovno pokretanje nije moguće.',
        'reran' => 'Ponovo pokrenuto :command (pokretanje :runId)',
        'rerun_forbidden' => 'To pokretanje pripada drugom programeru.',
    ],

    'command' => [
        'db_backup' => ['label' => 'Napravi rezervnu kopiju baze podataka', 'description' => 'Upisuje SQLite kopiju sa vremenskom oznakom u fasciklu sa rezervnim kopijama (ili na zadatu putanju).'],
        'doctor' => ['label' => 'Pokreni doctor', 'description' => 'Prikazuje instalirane verzije PHP-a / Composera / SQLite-a i proverava minimalne zahteve.'],
        'failed_jobs' => ['label' => 'Očisti neuspele zadatke', 'description' => 'Uklanja razrešene unose iz tabele failed_jobs kojom upravlja Laravel.'],
        'cache_clear' => ['label' => 'Očisti keš', 'description' => 'Prazni keš aplikacije.'],
        'route_list' => ['label' => 'Prikaži rute', 'description' => 'Ispisuje svaku registrovanu HTTP rutu na stdout.'],
        'config_show' => ['label' => 'Prikaži konfiguraciju', 'description' => 'Ispisuje vrednost zadatog konfiguracionog ključa sa tačkama.'],
        'view_clear' => ['label' => 'Očisti keš prikaza', 'description' => 'Prazni keš kompajliranih Blade prikaza.'],
        'queue_retry' => ['label' => 'Ponovi neuspele zadatke', 'description' => 'Ponavlja jedan zadatak (po id-u) ili svaki neuspeli zadatak (prazan id).'],
        // i18n-review: sr · command.rederive_fingerprints — «otisak» is the word the Auth
        // files already use for a key and a biometric fingerprint; here it names the
        // transaction fingerprint. Confirm the same noun carries all three.
        'rederive_fingerprints' => ['label' => 'Ponovo izvedi otiske', 'description' => 'Ponovo računa otisak svake transakcije prema trenutnoj verziji normalizacije.'],
        'db_restore' => ['label' => 'Vrati bazu podataka', 'description' => 'Zamenjuje trenutnu bazu podataka zadatom datotekom rezervne kopije.'],
        'migrate_fresh' => ['label' => 'Obriši tabele i migriraj ponovo', 'description' => 'Briše svaku tabelu, pa ponovo pokreće svaku migraciju.'],
        'reset_password' => ['label' => 'Resetuj lozinku', 'description' => 'Interaktivno resetuje lozinku korisnika (odbija neinteraktivnu upotrebu).'],
        'regenerate_recovery_codes' => ['label' => 'Ponovo generiši kodove za oporavak', 'description' => 'Ponovo generiše 10 jednokratnih kodova za oporavak za korisnika.'],
        'grant_dev' => ['label' => 'Dodeli programerski pristup', 'description' => 'Postavlja is_developer=true za zadatog korisnika.'],
        'install' => ['label' => 'Pokreni instalaciju', 'description' => 'Idempotentno podešavanje pri prvom pokretanju. Ponovno pokretanje na već podešenoj instalaciji je destruktivno.'],
    ],

    'arg' => [
        'destination' => ['label' => 'Odredišna datoteka', 'help' => 'Ostavi prazno da se koristi podrazumevana fascikla sa rezervnim kopijama.', 'placeholder' => '/putanja/do/backup.sqlite (opciono)'],
        'action' => ['label' => 'Radnja'],
        'config' => ['label' => 'Konfiguracioni ključ', 'help' => 'Konfiguraciona datoteka ili ključ sa tačkama koji treba ispisati, npr. `app` ili `database.connections.sqlite`.', 'placeholder' => 'app.name'],
        'id' => ['label' => 'Id zadatka', 'help' => 'Ostavi prazno da ponoviš svaki neuspeli zadatak; navedi id da ponoviš jedan unos.', 'placeholder' => 'sve (ili određeni id)'],
        'queue' => ['label' => 'Naziv reda čekanja', 'help' => 'Opcioni filter po redu čekanja; podrazumevano svi redovi.', 'placeholder' => 'default'],
        'from' => ['label' => 'Putanja do datoteke rezervne kopije', 'help' => 'Zamenjuje trenutnu bazu podataka datotekom na zadatoj putanji.', 'placeholder' => '/putanja/do/backup.sqlite'],
        'username' => ['label' => 'Korisničko ime', 'placeholder' => 'alice'],
    ],
];
