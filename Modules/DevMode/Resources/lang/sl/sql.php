<?php

declare(strict_types=1);

return [
    'tables' => 'Tabele',
    'schema_viewer_aria' => 'Pregledovalnik sheme',
    'columns' => 'stolpcev',
    'indexes' => 'indeksov',
    'foreign_keys' => 'tujih ključev',
    'browse' => 'Prebrskaj',
    'heading' => 'SQL',

    'subtitle_html' => 'Plošča za poizvedbe samo vrste SELECT. Validator (ob razčlenjevanju) in PRAGMA <code class="font-mono text-xs">query_only = 1</code> (na ravni pogona) zavrneta vsako poizvedbo, ki ni SELECT. Trda omejitev 5 sekund dejanskega časa.',
    'advanced_off_strong' => 'Napredni način je IZKLOPLJEN.',
    'advanced_off_hint' => 'Omogoči Advanced (Dev Mode → Advanced) za izvajanje poizvedb.',
    'statement_label' => 'Stavek SELECT',
    'run' => 'Zaženi',
    'rows_meta' => ':rows vrstica · :durationms|:rows vrstici · :durationms|:rows vrstice · :durationms|:rows vrstic · :durationms',
    'no_rows' => 'Poizvedba ni vrnila nobene vrstice.',

    'errors' => [
        'advanced_off' => 'Omogoči Advanced (Dev Mode → Advanced) za izvajanje poizvedb.',
        'only_select' => 'Dovoljeni so samo stavki SELECT. Razlog zavrnitve: :reason.',
        'timeout' => 'Poizvedba je presegla omejitev 5 sekund. Natančneje določi poizvedbo in poskusi znova.',
        'engine' => 'Napaka SQL: :message',
        'unknown_table' => 'Neznana tabela.',
    ],
];
