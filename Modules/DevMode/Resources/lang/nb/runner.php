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
    // i18n-review: nb · no_runs_touch — the same line for a touch
    // screen; check the verb governs this case.
    'no_runs_touch' => 'Ingen kjøringer ennå. Trykk på "Kjør en kommando" eller bruk kommandopaletten (⌘K).',
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
        'db_backup' => ['label' => 'Sikkerhetskopier databasen', 'description' => 'Skriver en SQLite-kopi med tidsstempel til sikkerhetskopimappen, med mindre databasen er uendret siden forrige. En kopi som beholdes, rydder også bort eldre sikkerhetskopier etter oppbevaringsregelen.'],
        'doctor' => ['label' => 'Kjør doctor', 'description' => 'Kjører settet med driftsprober og rapporterer pass / warn / fail for hver rad. En warn- eller fail-rad gir en avslutningskode ulik null.'],
        'failed_jobs' => ['label' => 'Rydd opp i feilede jobber', 'description' => 'Sletter hver rad som er eldre enn 30 dager, fra tabellen failed_jobs som Laravel styrer — uansett om jobben noen gang ble prøvd på nytt.'],
        'cache_clear' => ['label' => 'Tøm hurtiglageret', 'description' => 'Tømmer applikasjonens hurtiglager.'],
        'route_list' => ['label' => 'List opp rutene', 'description' => 'Skriver hver registrerte HTTP-rute til stdout.'],
        'config_show' => ['label' => 'Vis konfigurasjonen', 'description' => 'Skriver ut en hel konfigurasjonsfil eller verdien for en punktdelt nøkkel i den.'],
        'view_clear' => ['label' => 'Tøm view-hurtiglageret', 'description' => 'Tømmer hurtiglageret med kompilerte Blade-visninger.'],
        'queue_retry' => ['label' => 'Prøv feilede jobber på nytt', 'description' => 'Prøver én feilet jobb på nytt ut fra id, eller alle feilede jobber hvis du oppgir `all`.'],
        'rederive_fingerprints' => ['label' => 'Beregn fingeravtrykk på nytt', 'description' => 'Beregner fingeravtrykket på nytt for hver transaksjon som fortsatt ligger under den gjeldende normaliseringsversjonen. En kjøring herfra rapporterer antallet og skriver ingenting.'],
        'demo_seed' => ['label' => 'Last inn eksempeldata', 'description' => 'Legger til en eksempelbok — kontoer, transaksjoner, budsjetter, mål og varsler — funnet på for at du skal se appen med noe i. Det legges til det som allerede er der i stedet for å erstatte det, og ingenting av det er en virkelig persons data.'],
        'db_restore' => ['label' => 'Gjenopprett databasen', 'description' => 'Erstatter den gjeldende databasen med den oppgitte sikkerhetskopien.'],
        'regenerate_recovery_codes' => ['label' => 'Lag nye gjenopprettingskoder', 'description' => 'Lager en brukers 10 engangskoder for gjenoppretting på nytt.'],
        'grant_dev' => ['label' => 'Gi utviklertilgang', 'description' => 'Setter is_developer=true for den oppgitte brukeren.'],
        'install' => ['label' => 'Kjør installasjonen', 'description' => 'Idempotent førstegangsoppsett: databaseskjemaet, referansedata og den ene brukerkontoen. Kjøres den på nytt på en ferdig installasjon, bekreftes den eksisterende kontoen på nytt, og passordet står uendret.'],
    ],

    'arg' => [
        'action' => ['label' => 'Handling'],
        'config' => ['label' => 'Konfigurasjonsnøkkel', 'help' => 'Konfigurasjonsfilen eller den punktdelte nøkkelen som skal skrives ut, for eksempel `app` eller `database.connections.sqlite`.', 'placeholder' => 'app.name'],
        'id' => ['label' => 'Jobb-id', 'help' => 'Skriv `all` for å prøve alle feilede jobber på nytt, eller en jobb-id for å prøve bare én. Et tomt felt prøver ingenting på nytt.', 'placeholder' => 'all (eller en bestemt id)'],
        'queue' => ['label' => 'Kønavn', 'help' => 'Valgfritt køfilter; som standard alle køer.', 'placeholder' => 'default'],
        'path' => ['label' => 'Sti til sikkerhetskopien', 'help' => 'Erstatter den gjeldende databasen med filen på den oppgitte stien.', 'placeholder' => '/sti/til/backup.sqlite'],
        'username' => ['label' => 'Brukernavn', 'placeholder' => 'alice'],
    ],
];
