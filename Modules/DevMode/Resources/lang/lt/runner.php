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
    // i18n-review: lt · no_runs_touch — the same line for a touch
    // screen; check the verb governs this case.
    'no_runs_touch' => 'Vykdymų dar nėra. Palieski „Vykdyti komandą“ arba naudok komandų paletę (⌘K).',
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
        'invalid_args' => 'Nepavyksta įvykdyti :command — :reason',
        'arg' => 'argumento|argumentų|argumentų',
        'started' => 'Pradėta :command (vykdymas :runId)',
        'run_expired' => 'Vykdymo įrašas nebegalioja — pakartoti negalima.',
        'reran' => 'Pakartota :command (vykdymas :runId)',
        'rerun_forbidden' => 'Šis vykdymas priklauso kitam kūrėjui.',
    ],

    'command' => [
        'db_backup' => ['label' => 'Sukurti duomenų bazės atsarginę kopiją', 'description' => 'Įrašo SQLite kopiją su laiko žyma į atsarginių kopijų katalogą (arba nurodytu keliu).'],
        'doctor' => ['label' => 'Paleisti doctor', 'description' => 'Praneša įdiegtas PHP / Composer / SQLite versijas ir patikrina minimalius reikalavimus.'],
        'failed_jobs' => ['label' => 'Išvalyti nepavykusias užduotis', 'description' => 'Pašalina išspręstus įrašus iš Laravel tvarkomos lentelės failed_jobs.'],
        'cache_clear' => ['label' => 'Išvalyti podėlį', 'description' => 'Ištuština programos podėlį.'],
        'route_list' => ['label' => 'Išvardyti maršrutus', 'description' => 'Išveda kiekvieną registruotą HTTP maršrutą į stdout.'],
        'config_show' => ['label' => 'Rodyti konfigūraciją', 'description' => 'Išveda nurodyto taškais atskirto konfigūracijos rakto reikšmę.'],
        'view_clear' => ['label' => 'Išvalyti šablonų podėlį', 'description' => 'Ištuština sukompiliuotų Blade šablonų podėlį.'],
        'queue_retry' => ['label' => 'Pakartoti nepavykusias užduotis', 'description' => 'Pakartoja vieną užduotį (pagal id) arba kiekvieną nepavykusią užduotį (tuščias id).'],
        'rederive_fingerprints' => ['label' => 'Iš naujo išvesti atspaudus', 'description' => 'Perskaičiuoja kiekvienos operacijos atspaudą pagal dabartinę normalizavimo versiją.'],
        'db_restore' => ['label' => 'Atkurti duomenų bazę', 'description' => 'Pakeičia dabartinę duomenų bazę nurodytu atsarginės kopijos failu.'],
        // i18n-review: lt · command.migrate_fresh — migracija is the schema sense,
        // while migration::index calls the app's own YNAB import perkėlimas. The
        // split keeps them apart; a native reader says whether it reads.
        'migrate_fresh' => ['label' => 'Pašalinti lenteles ir migruoti iš naujo', 'description' => 'Pašalina kiekvieną lentelę, tada iš naujo įvykdo kiekvieną migraciją.'],
        'reset_password' => ['label' => 'Atstatyti slaptažodį', 'description' => 'Interaktyviai atstato naudotojo slaptažodį (neinteraktyvaus naudojimo atsisako).'],
        'regenerate_recovery_codes' => ['label' => 'Sukurti naujus atkūrimo kodus', 'description' => 'Iš naujo sukuria 10 vienkartinių naudotojo atkūrimo kodų.'],
        'grant_dev' => ['label' => 'Suteikti kūrėjo prieigą', 'description' => 'Nurodytam naudotojui nustato is_developer=true.'],
        'install' => ['label' => 'Vykdyti diegimą', 'description' => 'Idempotentinė pirmoji sąranka. Pakartotinis vykdymas jau sukonfigūruotoje sistemoje yra ardomasis.'],
    ],

    'arg' => [
        'destination' => ['label' => 'Paskirties failas', 'help' => 'Palik tuščią, kad būtų naudojamas numatytasis atsarginių kopijų katalogas.', 'placeholder' => '/kelias/iki/backup.sqlite (nebūtina)'],
        'action' => ['label' => 'Veiksmas'],
        'config' => ['label' => 'Konfigūracijos raktas', 'help' => 'Konfigūracijos failas arba taškais atskirtas raktas, kurį išvesti, pvz., `app` arba `database.connections.sqlite`.', 'placeholder' => 'app.name'],
        'id' => ['label' => 'Užduoties id', 'help' => 'Palik tuščią, kad būtų pakartota kiekviena nepavykusi užduotis; nurodyk id, kad būtų pakartotas vienas įrašas.', 'placeholder' => 'visos (arba konkretus id)'],
        'queue' => ['label' => 'Eilės pavadinimas', 'help' => 'Nebūtinas eilės filtras; pagal numatymą visos eilės.', 'placeholder' => 'default'],
        'from' => ['label' => 'Kelias iki atsarginės kopijos failo', 'help' => 'Pakeičia dabartinę duomenų bazę failu nurodytu keliu.', 'placeholder' => '/kelias/iki/backup.sqlite'],
        'username' => ['label' => 'Naudotojo vardas', 'placeholder' => 'alice'],
    ],
];
