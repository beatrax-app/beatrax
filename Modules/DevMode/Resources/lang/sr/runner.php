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
        'db_backup' => ['label' => 'Napravi rezervnu kopiju baze podataka', 'description' => 'Upisuje SQLite kopiju sa vremenskom oznakom u fasciklu sa rezervnim kopijama, osim ako se baza nije promenila od poslednje kopije. Zadržana kopija uklanja i starije rezervne kopije prema pravilu čuvanja.'],
        'doctor' => ['label' => 'Pokreni doctor', 'description' => 'Pokreće skup operativnih provera i prijavljuje pass / warn / fail za svaki red. Red warn ili fail daje izlazni kod različit od nule.'],
        'failed_jobs' => ['label' => 'Očisti neuspele zadatke', 'description' => 'Iz tabele failed_jobs kojom upravlja Laravel briše svaki red stariji od 30 dana, bez obzira na to da li je zadatak ikada ponovljen.'],
        'cache_clear' => ['label' => 'Očisti keš', 'description' => 'Prazni keš aplikacije.'],
        'route_list' => ['label' => 'Prikaži rute', 'description' => 'Ispisuje svaku registrovanu HTTP rutu na stdout.'],
        'config_show' => ['label' => 'Prikaži konfiguraciju', 'description' => 'Ispisuje celu konfiguracionu datoteku ili vrednost ključa sa tačkama u njoj.'],
        'view_clear' => ['label' => 'Očisti keš prikaza', 'description' => 'Prazni keš kompajliranih Blade prikaza.'],
        'queue_retry' => ['label' => 'Ponovi neuspele zadatke', 'description' => 'Ponavlja jedan neuspeli zadatak po id-u ili svaki neuspeli zadatak ako navedeš `all`.'],
        // i18n-review: sr · command.rederive_fingerprints — «otisak» is the word the Auth
        // files already use for a key and a biometric fingerprint; here it names the
        // transaction fingerprint. Confirm the same noun carries all three.
        'rederive_fingerprints' => ['label' => 'Ponovo izvedi otiske', 'description' => 'Ponovo računa otisak svake transakcije koja je i dalje ispod trenutne verzije normalizacije. Pokretanje odavde prijavljuje broj i ništa ne upisuje.'],
        'demo_seed' => ['label' => 'Učitaj probne podatke', 'description' => 'Dodaje probnu knjigu — račune, transakcije, budžete, ciljeve i upozorenja — izmišljenu da aplikaciju pogledaš sa nečim u njoj. Dodaje se na ono što već postoji umesto da ga zameni, i ništa od toga nisu podaci stvarne osobe.'],
        'db_restore' => ['label' => 'Vrati bazu podataka', 'description' => 'Zamenjuje trenutnu bazu podataka zadatom datotekom rezervne kopije.'],
        'regenerate_recovery_codes' => ['label' => 'Ponovo generiši kodove za oporavak', 'description' => 'Ponovo generiše 10 jednokratnih kodova za oporavak za korisnika.'],
        'grant_dev' => ['label' => 'Dodeli programerski pristup', 'description' => 'Postavlja is_developer=true za zadatog korisnika.'],
        'install' => ['label' => 'Pokreni instalaciju', 'description' => 'Idempotentno podešavanje pri prvom pokretanju: šema baze podataka, referentni podaci i jedini korisnički nalog. Ponovno pokretanje na već podešenoj instalaciji iznova potvrđuje postojeći nalog i ostavlja lozinku nepromenjenom.'],
    ],

    'arg' => [
        'action' => ['label' => 'Radnja'],
        'config' => ['label' => 'Konfiguracioni ključ', 'help' => 'Konfiguraciona datoteka ili ključ sa tačkama koji treba ispisati, npr. `app` ili `database.connections.sqlite`.', 'placeholder' => 'app.name'],
        'id' => ['label' => 'Id zadatka', 'help' => 'Upiši `all` da ponoviš svaki neuspeli zadatak ili id zadatka da ponoviš jedan unos. Prazno polje ne ponavlja ništa.', 'placeholder' => 'all (ili određeni id)'],
        'queue' => ['label' => 'Naziv reda čekanja', 'help' => 'Opcioni filter po redu čekanja; podrazumevano svi redovi.', 'placeholder' => 'default'],
        'path' => ['label' => 'Putanja do datoteke rezervne kopije', 'help' => 'Zamenjuje trenutnu bazu podataka datotekom na zadatoj putanji.', 'placeholder' => '/putanja/do/backup.sqlite'],
        'username' => ['label' => 'Korisničko ime', 'placeholder' => 'alice'],
    ],
];
