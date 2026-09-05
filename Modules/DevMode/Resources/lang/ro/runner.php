<?php

declare(strict_types=1);

return [
    'heading' => 'Runner Artisan',
    'subtitle' => 'Rulează comenzile SAFE cu un clic; comenzile DESTRUCTIVE trec prin tripla barieră.',
    'run_a_command' => 'Rulează o comandă',
    'filter_aria' => 'Filtru de rulări',
    'filter' => [
        'all' => 'Toate',
        'running' => 'În rulare',
        'failed' => 'Eșuate',
        'destructive' => 'Distructive',
    ],
    'worker_running' => 'Worker de coadă: RULEAZĂ',
    'worker_not_running' => 'Worker de coadă: NU RULEAZĂ',
    'no_runs' => 'Nicio rulare încă. Apasă pe "Rulează o comandă" sau folosește paleta de comenzi (⌘K).',
    // i18n-review: ro · no_runs_touch — the same line for a touch
    // screen; check the verb governs this case.
    'no_runs_touch' => 'Nicio rulare încă. Apasă pe "Rulează o comandă" sau folosește paleta de comenzi (⌘K).',
    'recent_runs_aria' => 'Rulări recente',
    'modal_heading' => 'Rulează o comandă SAFE',
    'modal_intro' => 'Alege o comandă de nivel SAFE ca să ruleze imediat. Comenzile DESTRUCTIVE nu apar aici — folosește opțiunea de rulare din nou din cronologie sau paleta ⌘K.',
    'args_badge' => 'args',
    'args_badge_title' => 'Deschide un formular de argumente',

    'spawning_unavailable' => 'Comenzile Artisan rulează într-un proces separat, iar această platformă nu lasă aplicația să pornească unul. Rulează-le din aplicația pentru computer.',

    'status' => [
        'running' => 'În rulare',
        'done' => 'Gata',
        'failed' => 'Eșuat',
        'cancelled' => 'Anulat',
    ],
    'cancel' => 'Anulează',
    'rerun' => 'Rulează din nou',
    'started' => 'Pornit :when',
    'exit' => 'cod de ieșire',

    'toast' => [
        'unknown_command' => 'Comandă necunoscută: :command',
        'missing_args' => 'Nu se poate rula :command — are nevoie de :noun: :list',
        'invalid_args' => 'Nu se poate rula :command — :reason',
        'arg' => 'argument|argumente|argumente',
        'started' => 'Pornit :command (rulare :runId)',
        'run_expired' => 'Înregistrarea rulării a expirat — nu se poate rula din nou.',
        'reran' => 'Rulat din nou :command (rulare :runId)',
        'rerun_forbidden' => 'Această rulare aparține altui dezvoltator.',
    ],

    'command' => [
        'db_backup' => ['label' => 'Fă o copie de rezervă a bazei de date', 'description' => 'Scrie o copie SQLite cu marcaj de timp în directorul de copii de rezervă, cu excepția cazului în care baza de date nu s-a schimbat de la ultima copie. O copie păstrată elimină și copiile mai vechi, conform politicii de retenție.'],
        'doctor' => ['label' => 'Rulează doctor', 'description' => 'Rulează suita de probe operaționale și raportează pass / warn / fail pentru fiecare rând. Un rând warn sau fail duce la un cod de ieșire diferit de zero.'],
        'failed_jobs' => ['label' => 'Curăță sarcinile eșuate', 'description' => 'Șterge din tabelul failed_jobs gestionat de Laravel fiecare rând mai vechi de 30 de zile, indiferent dacă sarcina a fost vreodată reîncercată.'],
        'cache_clear' => ['label' => 'Golește cache-ul', 'description' => 'Golește depozitul de cache al aplicației.'],
        'route_list' => ['label' => 'Listează rutele', 'description' => 'Afișează la stdout fiecare rută HTTP înregistrată.'],
        'config_show' => ['label' => 'Arată configurația', 'description' => 'Afișează un fișier de configurație întreg sau valoarea unei chei scrise cu puncte din el.'],
        'view_clear' => ['label' => 'Golește cache-ul de vizualizări', 'description' => 'Golește cache-ul vizualizărilor Blade compilate.'],
        'queue_retry' => ['label' => 'Reîncearcă sarcinile eșuate', 'description' => 'Reîncearcă o sarcină eșuată după id sau toate sarcinile eșuate dacă dai `all`.'],
        'rederive_fingerprints' => ['label' => 'Recalculează amprentele', 'description' => 'Recalculează amprenta fiecărei tranzacții care este încă sub versiunea curentă de normalizare. Rulată de aici, comanda raportează numărul și nu scrie nimic.'],
        'db_restore' => ['label' => 'Restaurează baza de date', 'description' => 'Înlocuiește baza de date curentă cu fișierul de copie de rezervă dat.'],
        'regenerate_recovery_codes' => ['label' => 'Regenerează codurile de recuperare', 'description' => 'Regenerează cele 10 coduri de recuperare de unică folosință ale unui utilizator.'],
        'grant_dev' => ['label' => 'Acordă acces de dezvoltator', 'description' => 'Setează is_developer=true pentru utilizatorul dat.'],
        'install' => ['label' => 'Rulează instalarea', 'description' => 'Configurare idempotentă la prima rulare: schema bazei de date, datele de referință și singurul cont de utilizator. Rularea din nou pe o instalare deja configurată reconfirmă contul existent și lasă parola neschimbată.'],
    ],

    'arg' => [
        'action' => ['label' => 'Acțiune'],
        'config' => ['label' => 'Cheie de configurație', 'help' => 'Fișierul de configurație sau cheia scrisă cu puncte care se afișează, de exemplu `app` sau `database.connections.sqlite`.', 'placeholder' => 'app.name'],
        'id' => ['label' => 'Id sarcină', 'help' => 'Scrie `all` ca să reîncerci fiecare sarcină eșuată sau un id ca să reîncerci o singură intrare. Lăsat gol, nu reîncearcă nimic.', 'placeholder' => 'all (sau un id anume)'],
        'queue' => ['label' => 'Nume de coadă', 'help' => 'Filtru opțional de coadă; implicit toate cozile.', 'placeholder' => 'default'],
        'path' => ['label' => 'Calea fișierului de copie de rezervă', 'help' => 'Înlocuiește baza de date curentă cu fișierul de la calea dată.', 'placeholder' => '/cale/către/backup.sqlite'],
        'username' => ['label' => 'Nume de utilizator', 'placeholder' => 'alice'],
    ],
];
