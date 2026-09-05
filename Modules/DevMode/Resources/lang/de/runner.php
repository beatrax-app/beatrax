<?php

declare(strict_types=1);

return [
    'heading' => 'Artisan-Runner',
    'subtitle' => 'SAFE-Befehle mit einem Klick ausführen; DESTRUCTIVE-Befehle hinter dem Triple-Gate.',
    'run_a_command' => 'Befehl ausführen',
    'filter_aria' => 'Lauf-Filter',
    'filter' => [
        'all' => 'Alle',
        'running' => 'Läuft',
        'failed' => 'Fehlgeschlagen',
        'destructive' => 'Destruktiv',
    ],
    'worker_running' => 'Queue-Worker: LÄUFT',
    'worker_not_running' => 'Queue-Worker: LÄUFT NICHT',
    'no_runs' => 'Noch keine Läufe. Klicke auf "Befehl ausführen" oder nutze die Befehlspalette (⌘K).',
    // i18n-review: de · no_runs_touch — the same line for a touch
    // screen; check the verb governs this case.
    'no_runs_touch' => 'Noch keine Läufe. Tippe auf "Befehl ausführen" oder nutze die Befehlspalette (⌘K).',
    'recent_runs_aria' => 'Letzte Läufe',
    'modal_heading' => 'SAFE-Befehl ausführen',
    'modal_intro' => 'Wähle einen SAFE-Befehl, der sofort ausgeführt wird. DESTRUCTIVE-Befehle stehen hier nicht — nutze die Erneut-ausführen-Option in der Timeline oder die ⌘K-Palette.',
    'args_badge' => 'args',
    'args_badge_title' => 'Öffnet ein Argumentformular',

    'spawning_unavailable' => 'Artisan-Befehle laufen in einem eigenen Prozess, und diese Plattform lässt die App keinen starten. Führe sie stattdessen in der Desktop-App aus.',

    'status' => [
        'running' => 'Läuft',
        'done' => 'Fertig',
        'failed' => 'Fehlgeschlagen',
        'cancelled' => 'Abgebrochen',
    ],
    'cancel' => 'Abbrechen',
    'rerun' => 'Erneut ausführen',
    'started' => 'Gestartet :when',
    'exit' => 'exit',

    'toast' => [
        'unknown_command' => 'Unbekannter Befehl: :command',
        'missing_args' => ':command kann nicht ausgeführt werden — benötigt :noun: :list',
        'invalid_args' => ':command kann nicht ausgeführt werden — :reason',
        'arg' => 'Argument|Argumente',
        'started' => 'Gestartet :command (Lauf :runId)',
        'run_expired' => 'Lauf-Datensatz abgelaufen — erneutes Ausführen nicht möglich.',
        'reran' => 'Erneut ausgeführt :command (Lauf :runId)',
        'rerun_forbidden' => 'Dieser Lauf gehört einem anderen Entwickler.',
    ],

    'command' => [
        'db_backup' => ['label' => 'Datenbank sichern', 'description' => 'Schreibt eine SQLite-Kopie mit Zeitstempel in das Backup-Verzeichnis, sofern sich die Datenbank seit dem letzten Backup geändert hat. Eine behaltene Kopie entfernt zudem ältere Backups gemäß der Aufbewahrungsregel.'],
        'doctor' => ['label' => 'Doctor ausführen', 'description' => 'Führt die Betriebs-Prüfsuite aus und meldet pass / warn / fail je Zeile. Eine warn- oder fail-Zeile führt zu einem Exit-Code ungleich null.'],
        'failed_jobs' => ['label' => 'Fehlgeschlagene Jobs aufräumen', 'description' => 'Löscht jede Zeile, die älter als 30 Tage ist, aus der von Laravel verwalteten Tabelle failed_jobs — unabhängig davon, ob der Job je wiederholt wurde.'],
        'cache_clear' => ['label' => 'Cache leeren', 'description' => 'Leert den Cache-Speicher der Anwendung.'],
        'route_list' => ['label' => 'Routen auflisten', 'description' => 'Gibt jede registrierte HTTP-Route auf stdout aus.'],
        'config_show' => ['label' => 'Konfiguration anzeigen', 'description' => 'Gibt eine ganze Konfigurationsdatei aus oder den Wert eines Schlüssels darin in Punktschreibweise.'],
        'view_clear' => ['label' => 'View-Cache leeren', 'description' => 'Leert den Cache der kompilierten Blade-Views.'],
        'queue_retry' => ['label' => 'Fehlgeschlagene Jobs wiederholen', 'description' => 'Wiederholt einen fehlgeschlagenen Job per ID oder jeden fehlgeschlagenen Job, wenn `all` übergeben wird.'],
        'rederive_fingerprints' => ['label' => 'Fingerprints neu berechnen', 'description' => 'Berechnet den Fingerprint jeder Transaktion neu, die noch unter der aktuellen Normalisierungsversion liegt. Ein Lauf von hier meldet die Anzahl und schreibt nichts.'],
        'db_restore' => ['label' => 'Datenbank wiederherstellen', 'description' => 'Ersetzt die aktuelle Datenbank durch die angegebene Backup-Datei.'],
        'regenerate_recovery_codes' => ['label' => 'Wiederherstellungscodes neu erzeugen', 'description' => 'Erzeugt die 10 einmalig nutzbaren Wiederherstellungscodes eines Benutzers neu.'],
        'grant_dev' => ['label' => 'Entwicklerzugriff gewähren', 'description' => 'Setzt is_developer=true für den angegebenen Benutzer.'],
        'install' => ['label' => 'Installation ausführen', 'description' => 'Idempotente Ersteinrichtung: Datenbankschema, Referenzdaten und das eine Benutzerkonto. Ein erneuter Lauf auf einer eingerichteten Installation bestätigt das vorhandene Konto erneut und lässt das Passwort unverändert.'],
    ],

    'arg' => [
        'action' => ['label' => 'Aktion'],
        'config' => ['label' => 'Konfigurationsschlüssel', 'help' => 'Die Konfigurationsdatei oder der Schlüssel in Punktschreibweise, z. B. `app` oder `database.connections.sqlite`.', 'placeholder' => 'app.name'],
        'id' => ['label' => 'Job-ID', 'help' => 'Gib `all` ein, um jeden fehlgeschlagenen Job zu wiederholen, oder eine Job-ID für einen einzelnen Eintrag. Ein leeres Feld wiederholt nichts.', 'placeholder' => 'all (oder eine bestimmte ID)'],
        'queue' => ['label' => 'Queue-Name', 'help' => 'Optionaler Queue-Filter; standardmäßig alle Queues.', 'placeholder' => 'default'],
        'path' => ['label' => 'Pfad zur Backup-Datei', 'help' => 'Ersetzt die aktuelle Datenbank durch die Datei am angegebenen Pfad.', 'placeholder' => '/pfad/zu/backup.sqlite'],
        'username' => ['label' => 'Benutzername', 'placeholder' => 'alice'],
    ],
];
