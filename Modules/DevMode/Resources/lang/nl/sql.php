<?php

declare(strict_types=1);

return [
    'tables' => 'Tabellen',
    'schema_viewer_aria' => 'Schemaviewer',
    'columns' => 'kolommen',
    'indexes' => 'indexen',
    'foreign_keys' => 'foreign keys',
    'browse' => 'Bladeren',
    'heading' => 'SQL',

    'subtitle_html' => 'Query-paneel voor alleen SELECT. De validator (bij parsen) en PRAGMA <code class="font-mono text-xs">query_only = 1</code> (bij uitvoeren) weigeren elke niet-SELECT. Harde limiet van 5 seconden op de kloktijd.',
    'advanced_off_strong' => 'Advanced-modus staat UIT.',
    'advanced_off_hint' => 'Schakel Advanced in (Dev Mode → Advanced) om query’s uit te voeren.',
    'statement_label' => 'SELECT-statement',
    'run' => 'Uitvoeren',
    'rows_meta' => ':rows regels · :durationms',
    'no_rows' => 'Query gaf geen regels terug.',

    'errors' => [
        'advanced_off' => 'Schakel Advanced in (Dev Mode → Advanced) om query’s uit te voeren.',
        'only_select' => 'Alleen SELECT-statements zijn toegestaan. Weigeringsreden: :reason.',
        'timeout' => 'Query overschreed de time-out van 5 seconden. Verfijn je query en probeer opnieuw.',
        'engine' => 'SQL-fout: :message',
        'unknown_table' => 'Onbekende tabel.',
    ],
];
