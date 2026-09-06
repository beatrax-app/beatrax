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
    // i18n-review: hr · no_runs_touch — the same line for a touch
    // screen; check the verb governs this case.
    'no_runs_touch' => 'Još nema pokretanja. Dodirni "Pokreni naredbu" ili upotrijebi paletu naredbi (⌘K).',
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
        'db_backup' => ['label' => 'Izradi sigurnosnu kopiju baze', 'description' => 'Zapisuje SQLite kopiju s vremenskom oznakom u mapu sigurnosnih kopija, osim ako se baza nije promijenila od zadnje kopije. Zadržana kopija ujedno uklanja starije sigurnosne kopije prema pravilu čuvanja.'],
        'doctor' => ['label' => 'Pokreni doctor', 'description' => 'Pokreće skup operativnih provjera i prijavljuje pass / warn / fail za svaki redak. Redak warn ili fail daje izlazni kod različit od nule.'],
        'failed_jobs' => ['label' => 'Očisti neuspjele zadatke', 'description' => 'Briše iz tablice failed_jobs kojom upravlja Laravel svaki redak stariji od 30 dana, bez obzira na to je li zadatak ikad ponovljen.'],
        'cache_clear' => ['label' => 'Očisti predmemoriju', 'description' => 'Prazni predmemoriju aplikacije.'],
        'route_list' => ['label' => 'Popiši rute', 'description' => 'Ispisuje svaku registriranu HTTP rutu na stdout.'],
        'config_show' => ['label' => 'Prikaži konfiguraciju', 'description' => 'Ispisuje cijelu konfiguracijsku datoteku ili vrijednost ključa s točkama u njoj.'],
        'view_clear' => ['label' => 'Očisti predmemoriju predložaka', 'description' => 'Prazni predmemoriju prevedenih Blade predložaka.'],
        'queue_retry' => ['label' => 'Ponovi neuspjele zadatke', 'description' => 'Ponavlja jedan neuspjeli zadatak po id-u ili svaki neuspjeli zadatak ako navedeš `all`.'],
        'rederive_fingerprints' => ['label' => 'Ponovno izvedi otiske', 'description' => 'Ponovno računa otisak svake transakcije koja je još ispod trenutne verzije normalizacije. Pokretanje odavde prijavljuje broj i ništa ne zapisuje.'],
        'demo_seed' => ['label' => 'Učitaj ogledne podatke', 'description' => 'Dodaje ogledni dnevnik — račune, transakcije, proračune, ciljeve i upozorenja — izmišljen da aplikaciju pogledaš s nečim u njoj. Dodaje se onome što već postoji umjesto da ga zamijeni, i ništa od toga nisu podaci stvarne osobe.'],
        'db_restore' => ['label' => 'Vrati bazu podataka', 'description' => 'Zamjenjuje trenutnu bazu podataka zadanom datotekom sigurnosne kopije.'],
        'regenerate_recovery_codes' => ['label' => 'Izradi nove kodove za oporavak', 'description' => 'Ponovno izrađuje 10 jednokratnih kodova za oporavak jednog korisnika.'],
        'grant_dev' => ['label' => 'Dodijeli razvojni pristup', 'description' => 'Postavlja is_developer=true za zadanog korisnika.'],
        'install' => ['label' => 'Pokreni instalaciju', 'description' => 'Idempotentno prvo postavljanje: shema baze, referentni podaci i jedini korisnički račun. Ponovno pokretanje na već postavljenoj instalaciji iznova potvrđuje postojeći račun i ostavlja lozinku nepromijenjenom.'],
    ],

    'arg' => [
        'action' => ['label' => 'Radnja'],
        'config' => ['label' => 'Konfiguracijski ključ', 'help' => 'Konfiguracijska datoteka ili ključ s točkama koji se ispisuje, npr. `app` ili `database.connections.sqlite`.', 'placeholder' => 'app.name'],
        'id' => ['label' => 'Id zadatka', 'help' => 'Upiši `all` da se ponovi svaki neuspjeli zadatak, ili id zadatka da se ponovi jedan zapis. Prazno polje ne ponavlja ništa.', 'placeholder' => 'all (ili određeni id)'],
        'queue' => ['label' => 'Naziv reda čekanja', 'help' => 'Neobavezni filtar reda čekanja; zadano su svi redovi.', 'placeholder' => 'default'],
        'path' => ['label' => 'Putanja do datoteke sigurnosne kopije', 'help' => 'Zamjenjuje trenutnu bazu podataka datotekom na zadanoj putanji.', 'placeholder' => '/putanja/do/backup.sqlite'],
        'username' => ['label' => 'Korisničko ime', 'placeholder' => 'alice'],
    ],
];
