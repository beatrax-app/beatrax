<?php

declare(strict_types=1);

return [
    'tables' => 'Taulut',
    'schema_viewer_aria' => 'Skeemanäkymä',
    'columns' => 'saraketta',
    'indexes' => 'indeksiä',
    'foreign_keys' => 'viiteavainta',
    'browse' => 'Selaa',
    'heading' => 'SQL',

    'subtitle_html' => 'Vain SELECT-kyselyille tarkoitettu paneeli. Validaattori (jäsennysvaiheessa) ja PRAGMA <code class="font-mono text-xs">query_only = 1</code> (moottoritasolla) hylkäävät kaiken muun kuin SELECT-kyselyn. Kova 5 sekunnin aikakatto.',
    'advanced_off_strong' => 'Lisäasetukset-tila on POIS PÄÄLTÄ.',
    'advanced_off_hint' => 'Ota Lisäasetukset käyttöön (Kehitystila → Lisäasetukset), niin voit suorittaa kyselyitä.',
    'statement_label' => 'SELECT-lause',
    'run' => 'Suorita',
    'rows_meta' => ':rows rivi · :durationms|:rows riviä · :durationms',
    'no_rows' => 'Kysely ei palauttanut rivejä.',

    'errors' => [
        'advanced_off' => 'Ota Lisäasetukset käyttöön (Kehitystila → Lisäasetukset), niin voit suorittaa kyselyitä.',
        'only_select' => 'Vain SELECT-lauseet ovat sallittuja. Hylkäyksen syy: :reason.',
        'timeout' => 'Kysely ylitti 5 sekunnin aikakaton. Tarkenna kyselyä ja yritä uudelleen.',
        'engine' => 'SQL-virhe: :message',
        'unknown_table' => 'Tuntematon taulu.',
    ],
];
