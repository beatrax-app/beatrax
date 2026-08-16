<?php

declare(strict_types=1);

return [
    'tables' => 'Tabellen',
    'schema_viewer_aria' => 'Schema-Viewer',
    'columns' => 'Spalten',
    'indexes' => 'Indizes',
    'foreign_keys' => 'Fremdschlüssel',
    'browse' => 'Durchsuchen',
    'heading' => 'SQL',

    'subtitle_html' => 'Abfragefeld nur für SELECT. Der Validator (beim Parsen) und PRAGMA <code class="font-mono text-xs">query_only = 1</code> (in der Engine) weisen jedes Nicht-SELECT ab. Hartes Limit von 5 Sekunden Laufzeit.',
    'advanced_off_strong' => 'Advanced-Modus ist AUS.',
    'advanced_off_hint' => 'Aktiviere Advanced (Dev Mode → Advanced), um Abfragen auszuführen.',
    'statement_label' => 'SELECT-Statement',
    'run' => 'Ausführen',
    'rows_meta' => ':rows Zeilen · :durationms',
    'no_rows' => 'Die Abfrage lieferte keine Zeilen.',

    'errors' => [
        'advanced_off' => 'Aktiviere Advanced (Dev Mode → Advanced), um Abfragen auszuführen.',
        'only_select' => 'Nur SELECT-Statements sind erlaubt. Ablehnungsgrund: :reason.',
        'timeout' => 'Die Abfrage hat das Zeitlimit von 5 Sekunden überschritten. Verfeinere deine Abfrage und versuche es erneut.',
        'engine' => 'SQL-Fehler: :message',
        'unknown_table' => 'Unbekannte Tabelle.',
    ],
];
