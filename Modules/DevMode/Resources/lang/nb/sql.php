<?php

declare(strict_types=1);

return [
    'tables' => 'Tabeller',
    'schema_viewer_aria' => 'Skjemavisning',
    'columns' => 'kolonner',
    'indexes' => 'indekser',
    'foreign_keys' => 'fremmednøkler',
    'browse' => 'Bla gjennom',
    'heading' => 'SQL',

    'subtitle_html' => 'Spørringspanel som bare tillater SELECT. Validatoren (ved parsing) og PRAGMA <code class="font-mono text-xs">query_only = 1</code> (i motoren) avviser alt som ikke er SELECT. Hard grense på 5 sekunders klokketid.',
    'advanced_off_strong' => 'Advanced-modus er AV.',
    'advanced_off_hint' => 'Aktiver Advanced (Dev Mode → Advanced) for å kjøre spørringer.',
    'statement_label' => 'SELECT-setning',
    'run' => 'Kjør',
    'rows_meta' => ':rows rad · :durationms|:rows rader · :durationms',
    'no_rows' => 'Spørringen returnerte ingen rader.',

    'errors' => [
        'advanced_off' => 'Aktiver Advanced (Dev Mode → Advanced) for å kjøre spørringer.',
        'only_select' => 'Bare SELECT-setninger er tillatt. Årsak til avvisning: :reason.',
        'timeout' => 'Spørringen overskred tidsgrensen på 5 sekunder. Forfin spørringen og prøv igjen.',
        'engine' => 'SQL-feil: :message',
        'unknown_table' => 'Ukjent tabell.',
    ],
];
