<?php

declare(strict_types=1);

return [
    'heading' => 'Artisan-runner',
    'subtitle' => 'Kør SAFE-kommandoer med ét klik; DESTRUCTIVE-kommandoer ligger bag triple-gate.',
    'run_a_command' => 'Kør en kommando',
    'filter_aria' => 'Kørselsfilter',
    'filter' => [
        'all' => 'Alle',
        'running' => 'Kører',
        'failed' => 'Mislykkede',
        'destructive' => 'Destruktive',
    ],
    'worker_running' => 'Kø-worker: KØRER',
    'worker_not_running' => 'Kø-worker: KØRER IKKE',
    'no_runs' => 'Ingen kørsler endnu. Klik på "Kør en kommando", eller brug kommandopaletten (⌘K).',
    // i18n-review: da · no_runs_touch — the same line for a touch
    // screen; check the verb governs this case.
    'no_runs_touch' => 'Ingen kørsler endnu. Tryk på "Kør en kommando", eller brug kommandopaletten (⌘K).',
    'recent_runs_aria' => 'Seneste kørsler',
    'modal_heading' => 'Kør en SAFE-kommando',
    'modal_intro' => 'Vælg en kommando på SAFE-niveau, der køres med det samme. DESTRUCTIVE-kommandoer står ikke her — brug Kør igen i tidslinjen eller ⌘K-paletten.',
    'args_badge' => 'args',
    'args_badge_title' => 'Åbner en argumentformular',

    'spawning_unavailable' => 'Artisan-kommandoer kører i en separat proces, og denne platform lader ikke appen starte en. Kør dem fra computer-appen i stedet.',

    'status' => [
        'running' => 'Kører',
        'done' => 'Færdig',
        'failed' => 'Mislykket',
        'cancelled' => 'Annulleret',
    ],
    'cancel' => 'Annullér',
    'rerun' => 'Kør igen',
    'started' => 'Startet :when',
    'exit' => 'exit',

    'toast' => [
        'unknown_command' => 'Ukendt kommando: :command',
        'missing_args' => 'Kan ikke køre :command — kræver :noun: :list',
        'invalid_args' => 'Kan ikke køre :command — :reason',
        'arg' => 'argument|argumenter',
        'started' => 'Startede :command (kørsel :runId)',
        'run_expired' => 'Kørselsposten er udløbet — kan ikke køres igen.',
        'reran' => 'Kørte :command igen (kørsel :runId)',
        'rerun_forbidden' => 'Den kørsel tilhører en anden udvikler.',
    ],

    'command' => [
        'db_backup' => ['label' => 'Sikkerhedskopiér databasen', 'description' => 'Skriver en SQLite-kopi med tidsstempel til backupmappen, medmindre databasen er uændret siden den sidste. En kopi, der beholdes, rydder også ældre sikkerhedskopier væk efter opbevaringspolitikken.'],
        'doctor' => ['label' => 'Kør doctor', 'description' => 'Kører pakken med driftsprober og rapporterer pass / warn / fail for hver række. En warn- eller fail-række giver en afslutningskode forskellig fra nul.'],
        'failed_jobs' => ['label' => 'Ryd op i mislykkede jobs', 'description' => 'Sletter hver række, der er ældre end 30 dage, fra tabellen failed_jobs, som Laravel styrer — uanset om jobbet nogensinde blev prøvet igen.'],
        'cache_clear' => ['label' => 'Ryd cachen', 'description' => 'Tømmer applikationens cachelager.'],
        'route_list' => ['label' => 'Vis ruterne', 'description' => 'Skriver hver registreret HTTP-rute til stdout.'],
        'config_show' => ['label' => 'Vis konfigurationen', 'description' => 'Viser en hel konfigurationsfil eller værdien for en punktopdelt nøgle i den.'],
        'view_clear' => ['label' => 'Ryd view-cachen', 'description' => 'Tømmer cachen med kompilerede Blade-views.'],
        'queue_retry' => ['label' => 'Prøv mislykkede jobs igen', 'description' => 'Prøver ét mislykket job igen ud fra id, eller alle mislykkede jobs hvis du angiver `all`.'],
        'rederive_fingerprints' => ['label' => 'Genberegn fingeraftryk', 'description' => 'Genberegner fingeraftrykket for hver transaktion, der stadig ligger under den nuværende normaliseringsversion. En kørsel herfra rapporterer antallet og skriver ingenting.'],
        'demo_seed' => ['label' => 'Indlæs eksempeldata', 'description' => 'Tilføjer et eksempelregnskab — konti, posteringer, budgetter, mål og varsler — opfundet, så du kan se appen med noget i. Det lægges oveni det, der allerede er, i stedet for at erstatte det, og intet af det er en virkelig persons data.'],
        'db_restore' => ['label' => 'Gendan databasen', 'description' => 'Erstatter den nuværende database med den angivne backupfil.'],
        'regenerate_recovery_codes' => ['label' => 'Generér nye gendannelseskoder', 'description' => 'Genererer en brugers 10 gendannelseskoder til engangsbrug på ny.'],
        'grant_dev' => ['label' => 'Giv udvikleradgang', 'description' => 'Sætter is_developer=true for den angivne bruger.'],
        'install' => ['label' => 'Kør installationen', 'description' => 'Idempotent førstegangsopsætning: databaseskemaet, referencedata og den ene brugerkonto. Køres den igen på en konfigureret installation, bekræftes den eksisterende konto på ny, og adgangskoden forbliver uændret.'],
    ],

    'arg' => [
        'action' => ['label' => 'Handling'],
        'config' => ['label' => 'Konfigurationsnøgle', 'help' => 'Konfigurationsfilen eller den punktopdelte nøgle, der skal vises, f.eks. `app` eller `database.connections.sqlite`.', 'placeholder' => 'app.name'],
        'id' => ['label' => 'Job-id', 'help' => 'Skriv `all` for at prøve alle mislykkede jobs igen, eller et job-id for kun at prøve ét. Et tomt felt prøver ingenting igen.', 'placeholder' => 'all (eller et bestemt id)'],
        'queue' => ['label' => 'Kønavn', 'help' => 'Valgfrit køfilter; som standard alle køer.', 'placeholder' => 'default'],
        'path' => ['label' => 'Sti til backupfilen', 'help' => 'Erstatter den nuværende database med filen på den angivne sti.', 'placeholder' => '/sti/til/backup.sqlite'],
        'username' => ['label' => 'Brugernavn', 'placeholder' => 'alice'],
    ],
];
