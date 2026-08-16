<?php

declare(strict_types=1);

return [
    'tables' => 'Táblák',
    'schema_viewer_aria' => 'Sémanézegető',
    'columns' => 'oszlop',
    'indexes' => 'index',
    'foreign_keys' => 'idegen kulcs',
    'browse' => 'Böngészés',
    'heading' => 'SQL',

    'subtitle_html' => 'Csak SELECT lekérdezéseket futtató panel. A validátor (elemzéskor) és a PRAGMA <code class="font-mono text-xs">query_only = 1</code> (motorszinten) minden nem SELECT utasítást elutasít. Kemény 5 másodperces időkorlát.',
    'advanced_off_strong' => 'Az Advanced mód KI van kapcsolva.',
    'advanced_off_hint' => 'A lekérdezések futtatásához kapcsold be az Advanced módot (Dev Mode → Advanced).',
    'statement_label' => 'SELECT utasítás',
    'run' => 'Futtatás',
    'rows_meta' => ':rows sor · :durationms',
    'no_rows' => 'A lekérdezés nem adott vissza sort.',

    'errors' => [
        'advanced_off' => 'A lekérdezések futtatásához kapcsold be az Advanced módot (Dev Mode → Advanced).',
        'only_select' => 'Csak SELECT utasítások engedélyezettek. Az elutasítás oka: :reason.',
        'timeout' => 'A lekérdezés túllépte az 5 másodperces időkorlátot. Pontosítsd a lekérdezést, és próbáld újra.',
        'engine' => 'SQL-hiba: :message',
        'unknown_table' => 'Ismeretlen tábla.',
    ],
];
