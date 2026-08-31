<?php

declare(strict_types=1);

return [
    'heading' => 'Artisan-runner',
    'subtitle' => 'Kör SAFE-kommandon med ett klick; DESTRUCTIVE-kommandon ligger bakom triple-gate.',
    'run_a_command' => 'Kör ett kommando',
    'filter_aria' => 'Körningsfilter',
    'filter' => [
        'all' => 'Alla',
        'running' => 'Körs',
        'failed' => 'Misslyckade',
        'destructive' => 'Destruktiva',
    ],
    'worker_running' => 'Köarbetare: KÖRS',
    'worker_not_running' => 'Köarbetare: KÖRS INTE',
    'no_runs' => 'Inga körningar än. Klicka på "Kör ett kommando" eller använd kommandopaletten (⌘K).',
    'recent_runs_aria' => 'Senaste körningar',
    'modal_heading' => 'Kör ett SAFE-kommando',
    'modal_intro' => 'Välj ett kommando på SAFE-nivå som körs direkt. DESTRUCTIVE-kommandon listas inte här — använd Kör igen i tidslinjen eller ⌘K-paletten.',
    'args_badge' => 'args',
    'args_badge_title' => 'Öppnar ett argumentformulär',

    'spawning_unavailable' => 'Artisan-kommandon körs i en separat process, och den här plattformen låter inte appen starta någon. Kör dem från datorappen i stället.',

    'status' => [
        'running' => 'Körs',
        'done' => 'Klar',
        'failed' => 'Misslyckades',
        'cancelled' => 'Avbruten',
    ],
    'cancel' => 'Avbryt',
    'rerun' => 'Kör igen',
    'started' => 'Startade :when',
    'exit' => 'exit',

    'toast' => [
        'unknown_command' => 'Okänt kommando: :command',
        'missing_args' => 'Kan inte köra :command — kräver :noun: :list',
        'invalid_args' => 'Kan inte köra :command — :reason',
        'arg' => 'argument|argument',
        'started' => 'Startade :command (körning :runId)',
        'run_expired' => 'Körningsposten har upphört — kan inte köras igen.',
        'reran' => 'Körde :command igen (körning :runId)',
        'rerun_forbidden' => 'Den körningen tillhör en annan utvecklare.',
    ],

    'command' => [
        'db_backup' => ['label' => 'Säkerhetskopiera databasen', 'description' => 'Skriver en tidsstämplad SQLite-kopia till mappen för säkerhetskopior (eller till den angivna sökvägen).'],
        'doctor' => ['label' => 'Kör doctor', 'description' => 'Rapporterar de installerade versionerna av PHP / Composer / SQLite och kontrollerar minimikraven.'],
        'failed_jobs' => ['label' => 'Rensa misslyckade jobb', 'description' => 'Rensar avklarade rader ur tabellen failed_jobs som Laravel hanterar.'],
        'cache_clear' => ['label' => 'Töm cachen', 'description' => 'Tömmer applikationens cachelager.'],
        'route_list' => ['label' => 'Lista rutterna', 'description' => 'Skriver ut varje registrerad HTTP-rutt till stdout.'],
        'config_show' => ['label' => 'Visa konfigurationen', 'description' => 'Skriver ut värdet för den angivna konfigurationsnyckeln.'],
        'view_clear' => ['label' => 'Töm vycachen', 'description' => 'Tömmer cachen med kompilerade Blade-vyer.'],
        'queue_retry' => ['label' => 'Försök med misslyckade jobb igen', 'description' => 'Försöker med ett jobb (via id) eller med alla misslyckade jobb (tomt id) igen.'],
        'rederive_fingerprints' => ['label' => 'Räkna om fingeravtrycken', 'description' => 'Räknar om varje transaktions fingeravtryck med den nuvarande normaliseringsversionen.'],
        'db_restore' => ['label' => 'Återställ databasen', 'description' => 'Ersätter den nuvarande databasen med den angivna säkerhetskopian.'],
        'migrate_fresh' => ['label' => 'Ta bort tabellerna och migrera om', 'description' => 'Tar bort alla tabeller och kör sedan alla migreringar igen.'],
        'reset_password' => ['label' => 'Återställ lösenordet', 'description' => 'Återställer en användares lösenord interaktivt (vägrar icke-interaktiv användning).'],
        'regenerate_recovery_codes' => ['label' => 'Skapa nya återställningskoder', 'description' => 'Skapar en användares 10 engångskoder för återställning på nytt.'],
        'grant_dev' => ['label' => 'Ge utvecklaråtkomst', 'description' => 'Sätter is_developer=true för den angivna användaren.'],
        'install' => ['label' => 'Kör installationen', 'description' => 'Idempotent förstagångsinstallation. Att köra om den på en färdig installation är destruktivt.'],
    ],

    'arg' => [
        'destination' => ['label' => 'Målfil', 'help' => 'Lämna tomt för att använda standardmappen för säkerhetskopior.', 'placeholder' => '/sökväg/till/backup.sqlite (valfritt)'],
        'action' => ['label' => 'Åtgärd'],
        'config' => ['label' => 'Konfigurationsnyckel', 'help' => 'Konfigurationsfilen eller den punktade nyckel som ska skrivas ut, till exempel `app` eller `database.connections.sqlite`.', 'placeholder' => 'app.name'],
        'id' => ['label' => 'Jobb-id', 'help' => 'Lämna tomt för att försöka med alla misslyckade jobb igen; ange ett id för att bara försöka med ett.', 'placeholder' => 'alla (eller ett visst id)'],
        'queue' => ['label' => 'Könamn', 'help' => 'Valfritt köfilter; som standard alla köer.', 'placeholder' => 'default'],
        'from' => ['label' => 'Sökväg till säkerhetskopian', 'help' => 'Ersätter den nuvarande databasen med filen på den angivna sökvägen.', 'placeholder' => '/sökväg/till/backup.sqlite'],
        'username' => ['label' => 'Användarnamn', 'placeholder' => 'alice'],
    ],
];
