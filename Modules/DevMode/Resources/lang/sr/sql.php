<?php

declare(strict_types=1);

return [
    'tables' => 'Tabele',
    'schema_viewer_aria' => 'Pregled šeme',
    'columns' => 'kolona',
    'indexes' => 'indeksa',
    'foreign_keys' => 'stranih ključeva',
    'browse' => 'Pregledaj',
    'heading' => 'SQL',

    'subtitle_html' => 'Panel za upite isključivo tipa SELECT. Validator (pri parsiranju) i PRAGMA <code class="font-mono text-xs">query_only = 1</code> (na nivou mašine) odbacuju svaki upit koji nije SELECT. Tvrdo ograničenje od 5 sekundi stvarnog vremena.',
    'advanced_off_strong' => 'Napredni režim je ISKLJUČEN.',
    'advanced_off_hint' => 'Omogući Advanced (Dev Mode → Advanced) da pokrećeš upite.',
    'statement_label' => 'SELECT naredba',
    'run' => 'Pokreni',
    'rows_meta' => ':rows red · :durationms|:rows reda · :durationms|:rows redova · :durationms',
    'no_rows' => 'Upit nije vratio nijedan red.',

    'errors' => [
        'advanced_off' => 'Omogući Advanced (Dev Mode → Advanced) da pokrećeš upite.',
        'only_select' => 'Dozvoljeni su samo SELECT upiti. Razlog odbijanja: :reason.',
        'timeout' => 'Upit je premašio ograničenje od 5 sekundi. Preciziraj upit i pokušaj ponovo.',
        'engine' => 'SQL greška: :message',
        'unknown_table' => 'Nepoznata tabela.',
    ],
];
