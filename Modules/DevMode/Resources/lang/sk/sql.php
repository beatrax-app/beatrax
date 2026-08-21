<?php

declare(strict_types=1);

return [
    'tables' => 'Tabuľky',
    'schema_viewer_aria' => 'Prehliadač schémy',
    'columns' => 'stĺpcov',
    'indexes' => 'indexov',
    'foreign_keys' => 'cudzích kľúčov',
    'browse' => 'Prehliadať',
    'heading' => 'SQL',

    'subtitle_html' => 'Panel na dopyty výhradne typu SELECT. Validátor (pri parsovaní) a PRAGMA <code class="font-mono text-xs">query_only = 1</code> (na úrovni enginu) odmietnu každý dopyt iný než SELECT. Tvrdý limit 5 sekúnd reálneho času.',
    'advanced_off_strong' => 'Pokročilý režim je VYPNUTÝ.',
    'advanced_off_hint' => 'Dopyty spustíš po zapnutí Pokročilého režimu (Dev Mode → Advanced).',
    'statement_label' => 'Príkaz SELECT',
    'run' => 'Spustiť',
    'rows_meta' => ':rows riadok · :durationms|:rows riadky · :durationms|:rows riadkov · :durationms',
    'no_rows' => 'Dopyt nevrátil žiadne riadky.',

    'errors' => [
        'advanced_off' => 'Dopyty spustíš po zapnutí Pokročilého režimu (Dev Mode → Advanced).',
        'only_select' => 'Povolené sú iba príkazy SELECT. Dôvod odmietnutia: :reason.',
        'timeout' => 'Dopyt prekročil 5-sekundový limit. Uprav ho a skús to znova.',
        'engine' => 'Chyba SQL: :message',
        'unknown_table' => 'Neznáma tabuľka.',
    ],
];
