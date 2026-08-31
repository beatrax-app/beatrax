<?php

declare(strict_types=1);

return [
    'heading' => 'Artisan pokretač',
    'subtitle' => 'SAFE naredbe pokreni jednim klikom; DESTRUCTIVE naredbe su iza trostrukih vrata.',
    'run_a_command' => 'Pokreni naredbu',
    'filter_aria' => 'Filtar pokretanja',
    'filter' => [
        'all' => 'Sve',
        'running' => 'U tijeku',
        'failed' => 'Neuspješno',
        'destructive' => 'Destruktivno',
    ],
    'worker_running' => 'Worker reda čekanja: RADI',
    'worker_not_running' => 'Worker reda čekanja: NE RADI',
    'no_runs' => 'Još nema pokretanja. Klikni "Pokreni naredbu" ili upotrijebi paletu naredbi (⌘K).',
    'recent_runs_aria' => 'Nedavna pokretanja',
    'modal_heading' => 'Pokreni SAFE naredbu',
    'modal_intro' => 'Odaberi naredbu razine SAFE za trenutačno pokretanje. DESTRUCTIVE naredbe ovdje nisu navedene — upotrijebi ponovno pokretanje na vremenskoj crti ili paletu ⌘K.',
    'args_badge' => 'argumenti',
    'args_badge_title' => 'Otvara obrazac za argumente',

    'spawning_unavailable' => 'Artisan naredbe izvode se u zasebnom procesu, a ova platforma ne dopušta aplikaciji da ga pokrene. Pokreni ih iz aplikacije za računalo.',

    'status' => [
        'running' => 'U tijeku',
        'done' => 'Gotovo',
        'failed' => 'Neuspješno',
        'cancelled' => 'Otkazano',
    ],
    'cancel' => 'Odustani',
    'rerun' => 'Pokreni ponovno',
    'started' => 'Pokrenuto :when',
    'exit' => 'izlaz',

    'toast' => [
        'unknown_command' => 'Nepoznata naredba: :command',
        'missing_args' => 'Nije moguće pokrenuti :command — potrebno je :noun: :list',
        'invalid_args' => 'Nije moguće pokrenuti :command — :reason',
        'arg' => 'argument|argumenti|argumenti',
        'started' => 'Pokrenuto :command (pokretanje :runId)',
        'run_expired' => 'Zapis o pokretanju je istekao — ponovno pokretanje nije moguće.',
        'reran' => 'Ponovno pokrenuto :command (pokretanje :runId)',
        'rerun_forbidden' => 'To pokretanje pripada drugom programeru.',
    ],

    'command' => [
        'db_backup' => ['label' => 'Izradi sigurnosnu kopiju baze', 'description' => 'Zapisuje SQLite kopiju s vremenskom oznakom u mapu sigurnosnih kopija (ili na zadanu putanju).'],
        'doctor' => ['label' => 'Pokreni doctor', 'description' => 'Prijavljuje instalirane verzije PHP-a / Composera / SQLitea i provjerava minimume.'],
        'failed_jobs' => ['label' => 'Očisti neuspjele zadatke', 'description' => 'Uklanja riješene zapise iz tablice failed_jobs kojom upravlja Laravel.'],
        'cache_clear' => ['label' => 'Očisti predmemoriju', 'description' => 'Prazni predmemoriju aplikacije.'],
        'route_list' => ['label' => 'Popiši rute', 'description' => 'Ispisuje svaku registriranu HTTP rutu na stdout.'],
        'config_show' => ['label' => 'Prikaži konfiguraciju', 'description' => 'Ispisuje vrijednost na zadanom konfiguracijskom ključu s točkama.'],
        'view_clear' => ['label' => 'Očisti predmemoriju predložaka', 'description' => 'Prazni predmemoriju prevedenih Blade predložaka.'],
        'queue_retry' => ['label' => 'Ponovi neuspjele zadatke', 'description' => 'Ponavlja jedan zadatak (po id-u) ili svaki neuspjeli zadatak (prazan id).'],
        'rederive_fingerprints' => ['label' => 'Ponovno izvedi otiske', 'description' => 'Ponovno računa otisak svake transakcije prema trenutnoj verziji normalizacije.'],
        'db_restore' => ['label' => 'Vrati bazu podataka', 'description' => 'Zamjenjuje trenutnu bazu podataka zadanom datotekom sigurnosne kopije.'],
        'migrate_fresh' => ['label' => 'Obriši tablice i ponovno migriraj', 'description' => 'Briše svaku tablicu, a zatim ponovno pokreće svaku migraciju.'],
        'reset_password' => ['label' => 'Poništi lozinku', 'description' => 'Interaktivno poništava lozinku korisnika (odbija neinteraktivnu upotrebu).'],
        'regenerate_recovery_codes' => ['label' => 'Izradi nove kodove za oporavak', 'description' => 'Ponovno izrađuje 10 jednokratnih kodova za oporavak jednog korisnika.'],
        'grant_dev' => ['label' => 'Dodijeli razvojni pristup', 'description' => 'Postavlja is_developer=true za zadanog korisnika.'],
        'install' => ['label' => 'Pokreni instalaciju', 'description' => 'Idempotentno prvo postavljanje. Ponovno pokretanje na već postavljenoj instalaciji je destruktivno.'],
    ],

    'arg' => [
        'destination' => ['label' => 'Odredišna datoteka', 'help' => 'Ostavi prazno da se upotrijebi zadana mapa sigurnosnih kopija.', 'placeholder' => '/putanja/do/backup.sqlite (nije obavezno)'],
        'action' => ['label' => 'Radnja'],
        'config' => ['label' => 'Konfiguracijski ključ', 'help' => 'Konfiguracijska datoteka ili ključ s točkama koji se ispisuje, npr. `app` ili `database.connections.sqlite`.', 'placeholder' => 'app.name'],
        'id' => ['label' => 'Id zadatka', 'help' => 'Ostavi prazno da se ponovi svaki neuspjeli zadatak; navedi id da se ponovi jedan zapis.', 'placeholder' => 'sve (ili određeni id)'],
        'queue' => ['label' => 'Naziv reda čekanja', 'help' => 'Neobavezni filtar reda čekanja; zadano su svi redovi.', 'placeholder' => 'default'],
        'from' => ['label' => 'Putanja do datoteke sigurnosne kopije', 'help' => 'Zamjenjuje trenutnu bazu podataka datotekom na zadanoj putanji.', 'placeholder' => '/putanja/do/backup.sqlite'],
        'username' => ['label' => 'Korisničko ime', 'placeholder' => 'alice'],
    ],
];
