<?php

declare(strict_types=1);

return [
    'heading' => 'Artisan-runner',
    'subtitle' => 'Voer SAFE-commando’s met één klik uit; DESTRUCTIVE-commando’s achter de triple-gate.',
    'run_a_command' => 'Voer een commando uit',
    'filter_aria' => 'Runfilter',
    'filter' => [
        'all' => 'Alle',
        'running' => 'Bezig',
        'failed' => 'Mislukt',
        'destructive' => 'Destructief',
    ],
    'worker_running' => 'Wachtrij-worker: DRAAIT',
    'worker_not_running' => 'Wachtrij-worker: DRAAIT NIET',
    'no_runs' => 'Nog geen runs. Klik op "Voer een commando uit" of gebruik het commandopalet (⌘K).',
    'recent_runs_aria' => 'Recente runs',
    'modal_heading' => 'Voer een SAFE-commando uit',
    'modal_intro' => 'Kies een SAFE-commando om direct uit te voeren. DESTRUCTIVE-commando’s staan hier niet — gebruik de Re-run-knop in de tijdlijn of het ⌘K-palet.',
    'args_badge' => 'args',
    'args_badge_title' => 'Opent een argumentformulier',

    'spawning_unavailable' => 'Artisan-commando\'s draaien in een apart proces, en dit platform laat de app er geen starten. Voer ze uit vanaf de desktop-app.',

    'status' => [
        'running' => 'Bezig',
        'done' => 'Klaar',
        'failed' => 'Mislukt',
        'cancelled' => 'Geannuleerd',
    ],
    'cancel' => 'Annuleren',
    'rerun' => 'Opnieuw uitvoeren',
    'started' => 'Gestart :when',
    'exit' => 'exit',

    'toast' => [
        'unknown_command' => 'Onbekend commando: :command',
        'missing_args' => 'Kan :command niet uitvoeren — vereist :noun: :list',
        'invalid_args' => 'Kan :command niet uitvoeren — :reason',
        'arg' => 'argument|argumenten',
        'started' => 'Gestart :command (run :runId)',
        'run_expired' => 'Runrecord verlopen — opnieuw uitvoeren niet mogelijk.',
        'reran' => 'Opnieuw uitgevoerd :command (run :runId)',
        'rerun_forbidden' => 'Die run hoort bij een andere ontwikkelaar.',
    ],

    'command' => [
        'db_backup' => ['label' => 'Database back-uppen', 'description' => 'Schrijft een SQLite-kopie met tijdstempel naar de back-upmap (of naar het opgegeven pad).'],
        'doctor' => ['label' => 'Doctor uitvoeren', 'description' => 'Rapporteert de geïnstalleerde versies van PHP / Composer / SQLite en controleert de minimumeisen.'],
        'failed_jobs' => ['label' => 'Mislukte jobs opschonen', 'description' => 'Verwijdert afgehandelde regels uit de door Laravel beheerde tabel failed_jobs.'],
        'cache_clear' => ['label' => 'Cache leegmaken', 'description' => 'Leegt de cache van de applicatie.'],
        'route_list' => ['label' => 'Routes tonen', 'description' => 'Print elke geregistreerde HTTP-route naar stdout.'],
        'config_show' => ['label' => 'Configuratie tonen', 'description' => 'Print de waarde van de opgegeven configuratiesleutel.'],
        'view_clear' => ['label' => 'View-cache leegmaken', 'description' => 'Leegt de cache met gecompileerde Blade-views.'],
        'queue_retry' => ['label' => 'Mislukte jobs opnieuw proberen', 'description' => 'Probeert één job (op id) of elke mislukte job (leeg id) opnieuw.'],
        'rederive_fingerprints' => ['label' => 'Fingerprints opnieuw afleiden', 'description' => 'Berekent elke transactie-fingerprint opnieuw met de huidige normalisatieversie.'],
        'db_restore' => ['label' => 'Database terugzetten', 'description' => 'Vervangt de huidige database door het opgegeven back-upbestand.'],
        'migrate_fresh' => ['label' => 'Tabellen verwijderen en opnieuw migreren', 'description' => 'Verwijdert elke tabel en voert daarna elke migratie opnieuw uit.'],
        'reset_password' => ['label' => 'Wachtwoord opnieuw instellen', 'description' => 'Stelt interactief een gebruikerswachtwoord opnieuw in (weigert niet-interactief gebruik).'],
        'regenerate_recovery_codes' => ['label' => 'Herstelcodes opnieuw genereren', 'description' => 'Genereert de 10 eenmalige herstelcodes van een gebruiker opnieuw.'],
        'grant_dev' => ['label' => 'Ontwikkelaarstoegang verlenen', 'description' => 'Zet is_developer=true voor de opgegeven gebruiker.'],
        'install' => ['label' => 'Installatie uitvoeren', 'description' => 'Idempotente eerste installatie. Opnieuw uitvoeren op een ingerichte installatie is destructief.'],
    ],

    'arg' => [
        'destination' => ['label' => 'Doelbestand', 'help' => 'Laat leeg om de standaard back-upmap te gebruiken.', 'placeholder' => '/pad/naar/backup.sqlite (optioneel)'],
        'action' => ['label' => 'Actie'],
        'config' => ['label' => 'Configuratiesleutel', 'help' => 'Het configuratiebestand of de sleutel met punten die je wilt printen, bijv. `app` of `database.connections.sqlite`.', 'placeholder' => 'app.name'],
        'id' => ['label' => 'Job-id', 'help' => 'Laat leeg om elke mislukte job opnieuw te proberen; geef een id op om één regel opnieuw te proberen.', 'placeholder' => 'alles (of een specifiek id)'],
        'queue' => ['label' => 'Naam van de wachtrij', 'help' => 'Optioneel filter op wachtrij; standaard alle wachtrijen.', 'placeholder' => 'default'],
        'from' => ['label' => 'Pad naar het back-upbestand', 'help' => 'Vervangt de huidige database door het bestand op het opgegeven pad.', 'placeholder' => '/pad/naar/backup.sqlite'],
        'username' => ['label' => 'Gebruikersnaam', 'placeholder' => 'alice'],
    ],
];
