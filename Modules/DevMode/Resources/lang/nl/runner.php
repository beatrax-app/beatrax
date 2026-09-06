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
    'no_runs_touch' => 'Nog geen runs. Tik op "Voer een commando uit" of gebruik het commandopalet (⌘K).',
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
        'db_backup' => ['label' => 'Database back-uppen', 'description' => 'Schrijft een SQLite-kopie met tijdstempel naar de back-upmap, tenzij de database sinds de vorige back-up niet is gewijzigd. Een kopie die bewaard blijft, ruimt ook oudere back-ups op volgens het bewaarbeleid.'],
        'doctor' => ['label' => 'Doctor uitvoeren', 'description' => 'Voert de operationele probesuite uit en meldt pass / warn / fail per regel. Een warn- of fail-regel levert een exitcode op die niet nul is.'],
        'failed_jobs' => ['label' => 'Mislukte jobs opschonen', 'description' => 'Verwijdert elke regel ouder dan 30 dagen uit de door Laravel beheerde tabel failed_jobs, of de job nu ooit opnieuw is geprobeerd of niet.'],
        'cache_clear' => ['label' => 'Cache leegmaken', 'description' => 'Leegt de cache van de applicatie.'],
        'route_list' => ['label' => 'Routes tonen', 'description' => 'Print elke geregistreerde HTTP-route naar stdout.'],
        'config_show' => ['label' => 'Configuratie tonen', 'description' => 'Print een heel configuratiebestand, of de waarde van een sleutel met punten daarin.'],
        'view_clear' => ['label' => 'View-cache leegmaken', 'description' => 'Leegt de cache met gecompileerde Blade-views.'],
        'queue_retry' => ['label' => 'Mislukte jobs opnieuw proberen', 'description' => 'Probeert één mislukte job op id opnieuw, of elke mislukte job als je `all` opgeeft.'],
        'rederive_fingerprints' => ['label' => 'Fingerprints opnieuw afleiden', 'description' => 'Berekent de fingerprint opnieuw van elke transactie die nog onder de huidige normalisatieversie zit. Een run vanaf hier meldt het aantal en schrijft niets.'],
        'demo_seed' => ['label' => 'Voorbeeldgegevens laden', 'description' => 'Voegt een voorbeeldadministratie toe — rekeningen, transacties, budgetten, doelen en meldingen — verzonnen om de app met iets erin te bekijken. Het komt bij wat er al staat in plaats van het te vervangen, en niets ervan zijn gegevens van een echt persoon.'],
        'db_restore' => ['label' => 'Database terugzetten', 'description' => 'Vervangt de huidige database door het opgegeven back-upbestand.'],
        'regenerate_recovery_codes' => ['label' => 'Herstelcodes opnieuw genereren', 'description' => 'Genereert de 10 eenmalige herstelcodes van een gebruiker opnieuw.'],
        'grant_dev' => ['label' => 'Ontwikkelaarstoegang verlenen', 'description' => 'Zet is_developer=true voor de opgegeven gebruiker.'],
        'install' => ['label' => 'Installatie uitvoeren', 'description' => 'Idempotente eerste installatie: het databaseschema, referentiegegevens en het enige gebruikersaccount. Opnieuw uitvoeren op een ingerichte installatie bevestigt het bestaande account opnieuw en laat het wachtwoord ongewijzigd.'],
    ],

    'arg' => [
        'action' => ['label' => 'Actie'],
        'config' => ['label' => 'Configuratiesleutel', 'help' => 'Het configuratiebestand of de sleutel met punten die je wilt printen, bijv. `app` of `database.connections.sqlite`.', 'placeholder' => 'app.name'],
        'id' => ['label' => 'Job-id', 'help' => 'Typ `all` om elke mislukte job opnieuw te proberen, of een job-id om één regel opnieuw te proberen. Leeg gelaten wordt er niets opnieuw geprobeerd.', 'placeholder' => 'all (of een specifiek id)'],
        'queue' => ['label' => 'Naam van de wachtrij', 'help' => 'Optioneel filter op wachtrij; standaard alle wachtrijen.', 'placeholder' => 'default'],
        'path' => ['label' => 'Pad naar het back-upbestand', 'help' => 'Vervangt de huidige database door het bestand op het opgegeven pad.', 'placeholder' => '/pad/naar/backup.sqlite'],
        'username' => ['label' => 'Gebruikersnaam', 'placeholder' => 'alice'],
    ],
];
