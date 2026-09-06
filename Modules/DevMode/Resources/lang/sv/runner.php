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
    // i18n-review: sv · no_runs_touch — the same line for a touch
    // screen; check the verb governs this case.
    'no_runs_touch' => 'Inga körningar än. Tryck på "Kör ett kommando" eller använd kommandopaletten (⌘K).',
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
        'db_backup' => ['label' => 'Säkerhetskopiera databasen', 'description' => 'Skriver en tidsstämplad SQLite-kopia till mappen för säkerhetskopior, om inte databasen är oförändrad sedan den förra. En kopia som behålls rensar också bort äldre säkerhetskopior enligt lagringsregeln.'],
        'doctor' => ['label' => 'Kör doctor', 'description' => 'Kör sviten med driftprober och rapporterar pass / warn / fail för varje rad. En warn- eller fail-rad ger en avslutningskod skild från noll.'],
        'failed_jobs' => ['label' => 'Rensa misslyckade jobb', 'description' => 'Raderar varje rad som är äldre än 30 dagar ur tabellen failed_jobs som Laravel hanterar, oavsett om jobbet någonsin försöktes igen.'],
        'cache_clear' => ['label' => 'Töm cachen', 'description' => 'Tömmer applikationens cachelager.'],
        'route_list' => ['label' => 'Lista rutterna', 'description' => 'Skriver ut varje registrerad HTTP-rutt till stdout.'],
        'config_show' => ['label' => 'Visa konfigurationen', 'description' => 'Skriver ut en hel konfigurationsfil eller värdet för en punktad nyckel i den.'],
        'view_clear' => ['label' => 'Töm vycachen', 'description' => 'Tömmer cachen med kompilerade Blade-vyer.'],
        'queue_retry' => ['label' => 'Försök med misslyckade jobb igen', 'description' => 'Försöker med ett misslyckat jobb igen via id, eller med alla misslyckade jobb om du anger `all`.'],
        'rederive_fingerprints' => ['label' => 'Räkna om fingeravtrycken', 'description' => 'Räknar om fingeravtrycket för varje transaktion som fortfarande ligger under den nuvarande normaliseringsversionen. En körning härifrån rapporterar antalet och skriver ingenting.'],
        'demo_seed' => ['label' => 'Läs in exempeldata', 'description' => 'Lägger till en exempelbok — konton, transaktioner, budgetar, mål och varningar — påhittad för att du ska se appen med något i. Den läggs till det som redan finns i stället för att ersätta det, och inget av det är en verklig persons uppgifter.'],
        'db_restore' => ['label' => 'Återställ databasen', 'description' => 'Ersätter den nuvarande databasen med den angivna säkerhetskopian.'],
        'regenerate_recovery_codes' => ['label' => 'Skapa nya återställningskoder', 'description' => 'Skapar en användares 10 engångskoder för återställning på nytt.'],
        'grant_dev' => ['label' => 'Ge utvecklaråtkomst', 'description' => 'Sätter is_developer=true för den angivna användaren.'],
        'install' => ['label' => 'Kör installationen', 'description' => 'Idempotent förstagångsinstallation: databasschemat, referensdata och det enda användarkontot. Körs den om på en färdig installation bekräftas det befintliga kontot på nytt och lösenordet lämnas oförändrat.'],
    ],

    'arg' => [
        'action' => ['label' => 'Åtgärd'],
        'config' => ['label' => 'Konfigurationsnyckel', 'help' => 'Konfigurationsfilen eller den punktade nyckel som ska skrivas ut, till exempel `app` eller `database.connections.sqlite`.', 'placeholder' => 'app.name'],
        'id' => ['label' => 'Jobb-id', 'help' => 'Skriv `all` för att försöka med alla misslyckade jobb igen, eller ett jobb-id för att bara försöka med ett. Lämnas fältet tomt görs inget om.', 'placeholder' => 'all (eller ett visst id)'],
        'queue' => ['label' => 'Könamn', 'help' => 'Valfritt köfilter; som standard alla köer.', 'placeholder' => 'default'],
        'path' => ['label' => 'Sökväg till säkerhetskopian', 'help' => 'Ersätter den nuvarande databasen med filen på den angivna sökvägen.', 'placeholder' => '/sökväg/till/backup.sqlite'],
        'username' => ['label' => 'Användarnamn', 'placeholder' => 'alice'],
    ],
];
