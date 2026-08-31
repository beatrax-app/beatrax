<?php

declare(strict_types=1);

return [
    'heading' => 'Artisan-runner',
    'subtitle' => 'Kjør SAFE-kommandoer med ett klikk; DESTRUCTIVE-kommandoer ligger bak triple-gate.',
    'run_a_command' => 'Kjør en kommando',
    'filter_aria' => 'Kjøringsfilter',
    'filter' => [
        'all' => 'Alle',
        'running' => 'Kjører',
        'failed' => 'Feilet',
        'destructive' => 'Destruktive',
    ],
    'worker_running' => 'Køarbeider: KJØRER',
    'worker_not_running' => 'Køarbeider: KJØRER IKKE',
    'no_runs' => 'Ingen kjøringer ennå. Klikk på "Kjør en kommando" eller bruk kommandopaletten (⌘K).',
    'recent_runs_aria' => 'Siste kjøringer',
    'modal_heading' => 'Kjør en SAFE-kommando',
    'modal_intro' => 'Velg en kommando på SAFE-nivå som kjøres umiddelbart. DESTRUCTIVE-kommandoer er ikke listet her — bruk Kjør på nytt i tidslinjen eller ⌘K-paletten.',
    'args_badge' => 'args',
    'args_badge_title' => 'Åpner et argumentskjema',

    'spawning_unavailable' => 'Artisan-kommandoer kjører i en egen prosess, og denne plattformen lar ikke appen starte en. Kjør dem fra skrivebordsappen i stedet.',

    'status' => [
        'running' => 'Kjører',
        'done' => 'Ferdig',
        'failed' => 'Feilet',
        'cancelled' => 'Avbrutt',
    ],
    'cancel' => 'Avbryt',
    'rerun' => 'Kjør på nytt',
    'started' => 'Startet :when',
    'exit' => 'exit',

    'toast' => [
        'unknown_command' => 'Ukjent kommando: :command',
        'missing_args' => 'Kan ikke kjøre :command — krever :noun: :list',
        'invalid_args' => 'Kan ikke kjøre :command — :reason',
        'arg' => 'argument|argumenter',
        'started' => 'Startet :command (kjøring :runId)',
        'run_expired' => 'Kjøringsoppføringen er utløpt — kan ikke kjøres på nytt.',
        'reran' => 'Kjørte :command på nytt (kjøring :runId)',
        'rerun_forbidden' => 'Den kjøringen tilhører en annen utvikler.',
    ],

    'command' => [
        'db_backup' => ['label' => 'Sikkerhetskopier databasen', 'description' => 'Skriver en SQLite-kopi med tidsstempel til sikkerhetskopimappen (eller til den oppgitte stien).'],
        'doctor' => ['label' => 'Kjør doctor', 'description' => 'Rapporterer de installerte versjonene av PHP / Composer / SQLite og sjekker minstekravene.'],
        'failed_jobs' => ['label' => 'Rydd opp i feilede jobber', 'description' => 'Rydder ferdigbehandlede rader fra tabellen failed_jobs som Laravel styrer.'],
        'cache_clear' => ['label' => 'Tøm hurtiglageret', 'description' => 'Tømmer applikasjonens hurtiglager.'],
        'route_list' => ['label' => 'List opp rutene', 'description' => 'Skriver hver registrerte HTTP-rute til stdout.'],
        'config_show' => ['label' => 'Vis konfigurasjonen', 'description' => 'Skriver ut verdien for den oppgitte konfigurasjonsnøkkelen.'],
        'view_clear' => ['label' => 'Tøm view-hurtiglageret', 'description' => 'Tømmer hurtiglageret med kompilerte Blade-visninger.'],
        'queue_retry' => ['label' => 'Prøv feilede jobber på nytt', 'description' => 'Prøver én jobb (etter id) eller alle feilede jobber (tom id) på nytt.'],
        'rederive_fingerprints' => ['label' => 'Beregn fingeravtrykk på nytt', 'description' => 'Beregner hvert transaksjonsfingeravtrykk på nytt med den gjeldende normaliseringsversjonen.'],
        'db_restore' => ['label' => 'Gjenopprett databasen', 'description' => 'Erstatter den gjeldende databasen med den oppgitte sikkerhetskopien.'],
        'migrate_fresh' => ['label' => 'Slett tabeller og migrer på nytt', 'description' => 'Sletter alle tabeller og kjører deretter alle migreringene på nytt.'],
        'reset_password' => ['label' => 'Tilbakestill passord', 'description' => 'Tilbakestiller en brukers passord interaktivt (nekter ikke-interaktiv bruk).'],
        'regenerate_recovery_codes' => ['label' => 'Lag nye gjenopprettingskoder', 'description' => 'Lager en brukers 10 engangskoder for gjenoppretting på nytt.'],
        'grant_dev' => ['label' => 'Gi utviklertilgang', 'description' => 'Setter is_developer=true for den oppgitte brukeren.'],
        'install' => ['label' => 'Kjør installasjonen', 'description' => 'Idempotent førstegangsoppsett. Å kjøre den på nytt på en ferdig installasjon er destruktivt.'],
    ],

    'arg' => [
        'destination' => ['label' => 'Målfil', 'help' => 'La feltet stå tomt for å bruke standardmappen for sikkerhetskopier.', 'placeholder' => '/sti/til/backup.sqlite (valgfritt)'],
        'action' => ['label' => 'Handling'],
        'config' => ['label' => 'Konfigurasjonsnøkkel', 'help' => 'Konfigurasjonsfilen eller den punktdelte nøkkelen som skal skrives ut, for eksempel `app` eller `database.connections.sqlite`.', 'placeholder' => 'app.name'],
        'id' => ['label' => 'Jobb-id', 'help' => 'La feltet stå tomt for å prøve alle feilede jobber på nytt; oppgi en id for å prøve bare én.', 'placeholder' => 'alle (eller en bestemt id)'],
        'queue' => ['label' => 'Kønavn', 'help' => 'Valgfritt køfilter; som standard alle køer.', 'placeholder' => 'default'],
        'from' => ['label' => 'Sti til sikkerhetskopien', 'help' => 'Erstatter den gjeldende databasen med filen på den oppgitte stien.', 'placeholder' => '/sti/til/backup.sqlite'],
        'username' => ['label' => 'Brukernavn', 'placeholder' => 'alice'],
    ],
];
