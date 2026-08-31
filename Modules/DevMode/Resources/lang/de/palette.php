<?php

declare(strict_types=1);

return [
    'search_placeholder' => 'Tippe, um Ansichten, Befehle und Aktionen zu suchen. Esc zum Schließen.',
    'search_aria' => 'Tippe, um Ansichten, Befehle und Aktionen zu suchen',
    'dialog_aria' => 'Befehlspalette',
    'token_suggest_aria' => 'Token-Vorschläge',
    'rail_view' => 'Ansicht',
    'rail_dev' => 'Dev',
    'rail_action' => 'Aktion',
    'rail_recent' => 'Zuletzt',
    'no_recent' => 'Noch keine zuletzt verwendeten Einträge.',
    'section_transactions' => 'Transaktionen',
    'section_counterparties' => 'Zahlungspartner',
    'section_categories' => 'Kategorien',
    'section_goals_recurring' => 'Ziele & Wiederkehrendes',
    'no_name' => '(kein Name)',
    'see_all' => ':count Ergebnis anzeigen →|Alle :count Ergebnisse anzeigen →',
    'no_transactions' => 'Keine Transaktionen passen zu ":query"',
    'source_txn' => 'txn',
    'source_counterparty' => 'Zahlungspartner',
    'source_category' => 'Kategorie',
    'results_aria' => 'Ergebnisse',
    'no_results' => 'Keine Ergebnisse.',
    'foot_navigate' => 'navigieren',
    'foot_select' => 'auswählen',
    'foot_close' => 'schließen',
    'close_aria' => 'Suche schließen',
    'close_caption' => 'Schließen',
    'foot_try' => 'Versuche',
    'results' => ':count Ergebnis|:count Ergebnisse',

    'action' => [
        'run_import' => ['label' => 'Import starten', 'hint' => 'Import-Assistenten öffnen'],
        'scan_email' => ['label' => 'E-Mail jetzt scannen', 'hint' => 'Postfach-Synchronisierung sofort ausführen'],
        'open_profile' => ['label' => 'Profil öffnen', 'hint' => 'Einstellungen — Konto und Voreinstellungen'],
        'toggle_theme' => ['label' => 'Design wechseln', 'hint' => 'Zwischen hellem und dunklem Design wechseln'],
    ],

    'run_command' => ':command ausführen',

    'nav' => [
        'overview' => ['label' => 'Dev-Übersicht', 'hint' => 'Systemkacheln + letzte Läufe'],
        'artisan' => ['label' => 'Artisan-Runner', 'hint' => 'Freigegebene Befehle ausführen'],
        'audit' => ['label' => 'Dev-Audit-Log', 'hint' => 'Jede Aktion im Dev-Modus'],
        'logs' => ['label' => 'Log-Tailer', 'hint' => 'Live-Stream von laravel-*.log'],
        'queue' => ['label' => 'Queue-Inspektor', 'hint' => 'Ausstehend / fehlgeschlagen / Batches'],
        'doctor' => ['label' => 'Doctor', 'hint' => 'Systemprüfungen'],
        'sql' => ['label' => 'SQL-Panel', 'hint' => 'Browser nur für SELECT'],
        'system' => ['label' => 'System-Momentaufnahme', 'hint' => 'Umgebung + Pfade + Konfiguration'],
        'horizon' => ['label' => 'Horizon', 'hint' => 'Eingebettetes Queue-Dashboard'],
        'sync_health' => ['label' => 'Sync-Status', 'hint' => 'Merge-Vorgänge in Quarantäne oder übersprungen'],
    ],
];
