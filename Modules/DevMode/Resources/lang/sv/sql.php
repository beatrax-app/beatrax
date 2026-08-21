<?php

declare(strict_types=1);

return [
    'tables' => 'Tabeller',
    'schema_viewer_aria' => 'Schemavisare',
    'columns' => 'kolumner',
    'indexes' => 'index',
    'foreign_keys' => 'främmande nycklar',
    'browse' => 'Bläddra',
    'heading' => 'SQL',

    'subtitle_html' => 'Frågepanel som bara tillåter SELECT. Validatorn (vid parsning) och PRAGMA <code class="font-mono text-xs">query_only = 1</code> (i motorn) avvisar allt som inte är SELECT. Hård gräns på 5 sekunders väggklockstid.',
    'advanced_off_strong' => 'Advanced-läget är AV.',
    'advanced_off_hint' => 'Aktivera Advanced (Dev Mode → Advanced) för att köra frågor.',
    'statement_label' => 'SELECT-sats',
    'run' => 'Kör',
    'rows_meta' => ':rows rad · :durationms|:rows rader · :durationms',
    'no_rows' => 'Frågan returnerade inga rader.',

    'errors' => [
        'advanced_off' => 'Aktivera Advanced (Dev Mode → Advanced) för att köra frågor.',
        'only_select' => 'Bara SELECT-satser är tillåtna. Orsak till avvisning: :reason.',
        'timeout' => 'Frågan överskred tidsgränsen på 5 sekunder. Förfina frågan och försök igen.',
        'engine' => 'SQL-fel: :message',
        'unknown_table' => 'Okänd tabell.',
    ],
];
