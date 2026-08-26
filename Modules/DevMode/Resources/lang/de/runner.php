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
        'arg' => 'Argument|Argumente',
        'started' => 'Gestartet :command (Lauf :runId)',
        'run_expired' => 'Lauf-Datensatz abgelaufen — erneutes Ausführen nicht möglich.',
        'reran' => 'Erneut ausgeführt :command (Lauf :runId)',
    ],
];
