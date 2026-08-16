<?php

declare(strict_types=1);

return [
    'tables' => 'Tabelid',
    'schema_viewer_aria' => 'Skeemivaatur',
    'columns' => 'veergu',
    'indexes' => 'indeksit',
    'foreign_keys' => 'välisvõtit',
    'browse' => 'Sirvi',
    'heading' => 'SQL',

    'subtitle_html' => 'Ainult SELECT-päringute paneel. Valideerija (parsimise ajal) ja PRAGMA <code class="font-mono text-xs">query_only = 1</code> (mootori tasemel) lükkavad iga mitte-SELECT-päringu tagasi. Range 5-sekundiline ajapiirang.',
    'advanced_off_strong' => 'Täpsem režiim on VÄLJAS.',
    'advanced_off_hint' => 'Päringute käivitamiseks lülita sisse täpsem režiim (Arendusrežiim → Täpsem).',
    'statement_label' => 'SELECT-lause',
    'run' => 'Käivita',
    'rows_meta' => ':rows rida · :durationms',
    'no_rows' => 'Päring ei tagastanud ühtegi rida.',

    'errors' => [
        'advanced_off' => 'Päringute käivitamiseks lülita sisse täpsem režiim (Arendusrežiim → Täpsem).',
        'only_select' => 'Lubatud on ainult SELECT-laused. Tagasilükkamise põhjus: :reason.',
        'timeout' => 'Päring ületas 5-sekundilise ajapiirangu. Täpsusta päringut ja proovi uuesti.',
        'engine' => 'SQL-i viga: :message',
        'unknown_table' => 'Tundmatu tabel.',
    ],
];
