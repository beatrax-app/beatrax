<?php

declare(strict_types=1);

return [
    'tables' => 'Tabulky',
    'schema_viewer_aria' => 'Prohlížeč schématu',
    'columns' => 'sloupce',
    'indexes' => 'indexy',
    'foreign_keys' => 'cizí klíče',
    'browse' => 'Procházet',
    'heading' => 'SQL',

    'subtitle_html' => 'Panel dotazů jen pro SELECT. Validátor (při parsování) i PRAGMA <code class="font-mono text-xs">query_only = 1</code> (na úrovni enginu) odmítnou každý dotaz, který není SELECT. Tvrdý limit 5 sekund reálného času.',
    'advanced_off_strong' => 'Pokročilý režim je VYPNUTÝ.',
    'advanced_off_hint' => 'Zapni Pokročilé (Dev Mode → Advanced) a můžeš spouštět dotazy.',
    'statement_label' => 'Příkaz SELECT',
    'run' => 'Spustit',
    'rows_meta' => ':rows řádků · :durationms',
    'no_rows' => 'Dotaz nevrátil žádné řádky.',

    'errors' => [
        'advanced_off' => 'Zapni Pokročilé (Dev Mode → Advanced) a můžeš spouštět dotazy.',
        'only_select' => 'Povolené jsou jen příkazy SELECT. Důvod odmítnutí: :reason.',
        'timeout' => 'Dotaz překročil limit 5 sekund. Uprav ho a zkus to znovu.',
        'engine' => 'Chyba SQL: :message',
        'unknown_table' => 'Neznámá tabulka.',
    ],
];
