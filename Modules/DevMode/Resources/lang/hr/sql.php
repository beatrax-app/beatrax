<?php

declare(strict_types=1);

return [
    'tables' => 'Tablice',
    'schema_viewer_aria' => 'Preglednik sheme',
    'columns' => 'stupaca',
    'indexes' => 'indeksa',
    'foreign_keys' => 'stranih ključeva',
    'browse' => 'Pregledaj',
    'heading' => 'SQL',

    'subtitle_html' => 'Ploča za upite isključivo tipa SELECT. Validator (pri parsiranju) i PRAGMA <code class="font-mono text-xs">query_only = 1</code> (na razini stroja) odbacuju svaki upit koji nije SELECT. Tvrdo ograničenje od 5 sekundi stvarnog vremena.',
    'advanced_off_strong' => 'Napredni način je ISKLJUČEN.',
    'advanced_off_hint' => 'Omogući Advanced (Dev Mode → Advanced) za pokretanje upita.',
    'statement_label' => 'SELECT naredba',
    'run' => 'Pokreni',
    'rows_meta' => ':rows redaka · :durationms',
    'no_rows' => 'Upit nije vratio nijedan redak.',

    'errors' => [
        'advanced_off' => 'Omogući Advanced (Dev Mode → Advanced) za pokretanje upita.',
        'only_select' => 'Dopušteni su samo SELECT upiti. Razlog odbijanja: :reason.',
        'timeout' => 'Upit je premašio ograničenje od 5 sekundi. Preciziraj upit i pokušaj ponovno.',
        'engine' => 'SQL pogreška: :message',
        'unknown_table' => 'Nepoznata tablica.',
    ],
];
