<?php

declare(strict_types=1);

return [
    'tables' => 'Tabeller',
    'schema_viewer_aria' => 'Skemavisning',
    'columns' => 'kolonner',
    'indexes' => 'indekser',
    'foreign_keys' => 'fremmednøgler',
    'browse' => 'Gennemse',
    'heading' => 'SQL',

    'subtitle_html' => 'Forespørgselspanel, der kun tillader SELECT. Validatoren (ved parsing) og PRAGMA <code class="font-mono text-xs">query_only = 1</code> (i motoren) afviser alt, der ikke er SELECT. Hård grænse på 5 sekunders realtid.',
    'advanced_off_strong' => 'Advanced-tilstand er FRA.',
    'advanced_off_hint' => 'Aktivér Advanced (Dev Mode → Advanced) for at køre forespørgsler.',
    'statement_label' => 'SELECT-sætning',
    'run' => 'Kør',
    'rows_meta' => ':rows række · :durationms|:rows rækker · :durationms',
    'no_rows' => 'Forespørgslen returnerede ingen rækker.',

    'errors' => [
        'advanced_off' => 'Aktivér Advanced (Dev Mode → Advanced) for at køre forespørgsler.',
        'only_select' => 'Kun SELECT-sætninger er tilladt. Årsag til afvisning: :reason.',
        'timeout' => 'Forespørgslen overskred tidsgrænsen på 5 sekunder. Forfin din forespørgsel, og prøv igen.',
        'engine' => 'SQL-fejl: :message',
        'unknown_table' => 'Ukendt tabel.',
    ],
];
