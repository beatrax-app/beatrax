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
        'db_backup' => ['label' => 'Sikkerhedskopiér databasen', 'description' => 'Skriver en SQLite-kopi med tidsstempel til backupmappen (eller til den angivne sti).'],
        'doctor' => ['label' => 'Kør doctor', 'description' => 'Rapporterer de installerede versioner af PHP / Composer / SQLite og kontrollerer minimumskravene.'],
        'failed_jobs' => ['label' => 'Ryd op i mislykkede jobs', 'description' => 'Rydder afsluttede rækker fra tabellen failed_jobs, som Laravel styrer.'],
        'cache_clear' => ['label' => 'Ryd cachen', 'description' => 'Tømmer applikationens cachelager.'],
        'route_list' => ['label' => 'Vis ruterne', 'description' => 'Skriver hver registreret HTTP-rute til stdout.'],
        'config_show' => ['label' => 'Vis konfigurationen', 'description' => 'Skriver værdien for den angivne konfigurationsnøgle.'],
        'view_clear' => ['label' => 'Ryd view-cachen', 'description' => 'Tømmer cachen med kompilerede Blade-views.'],
        'queue_retry' => ['label' => 'Prøv mislykkede jobs igen', 'description' => 'Prøver ét job (efter id) eller alle mislykkede jobs (tomt id) igen.'],
        'rederive_fingerprints' => ['label' => 'Genberegn fingeraftryk', 'description' => 'Genberegner hvert transaktionsfingeraftryk med den nuværende normaliseringsversion.'],
        'db_restore' => ['label' => 'Gendan databasen', 'description' => 'Erstatter den nuværende database med den angivne backupfil.'],
        'migrate_fresh' => ['label' => 'Slet tabeller og migrér igen', 'description' => 'Sletter alle tabeller og kører derefter alle migreringer igen.'],
        'reset_password' => ['label' => 'Nulstil adgangskode', 'description' => 'Nulstiller en brugers adgangskode interaktivt (afviser ikke-interaktiv brug).'],
        'regenerate_recovery_codes' => ['label' => 'Generér nye gendannelseskoder', 'description' => 'Genererer en brugers 10 gendannelseskoder til engangsbrug på ny.'],
        'grant_dev' => ['label' => 'Giv udvikleradgang', 'description' => 'Sætter is_developer=true for den angivne bruger.'],
        'install' => ['label' => 'Kør installationen', 'description' => 'Idempotent førstegangsopsætning. At køre den igen på en konfigureret installation er destruktivt.'],
    ],

    'arg' => [
        'destination' => ['label' => 'Destinationsfil', 'help' => 'Lad feltet stå tomt for at bruge standardbackupmappen.', 'placeholder' => '/sti/til/backup.sqlite (valgfri)'],
        'action' => ['label' => 'Handling'],
        'config' => ['label' => 'Konfigurationsnøgle', 'help' => 'Konfigurationsfilen eller den punktopdelte nøgle, der skal vises, f.eks. `app` eller `database.connections.sqlite`.', 'placeholder' => 'app.name'],
        'id' => ['label' => 'Job-id', 'help' => 'Lad feltet stå tomt for at prøve alle mislykkede jobs igen; angiv et id for kun at prøve ét igen.', 'placeholder' => 'alle (eller et bestemt id)'],
        'queue' => ['label' => 'Kønavn', 'help' => 'Valgfrit køfilter; som standard alle køer.', 'placeholder' => 'default'],
        'from' => ['label' => 'Sti til backupfilen', 'help' => 'Erstatter den nuværende database med filen på den angivne sti.', 'placeholder' => '/sti/til/backup.sqlite'],
        'username' => ['label' => 'Brugernavn', 'placeholder' => 'alice'],
    ],
];
