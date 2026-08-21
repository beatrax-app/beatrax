<?php

declare(strict_types=1);

return [
    'tables' => 'Tabulas',
    'schema_viewer_aria' => 'Shēmas skatītājs',
    'columns' => 'kolonnas',
    'indexes' => 'indeksi',
    'foreign_keys' => 'ārējās atslēgas',
    'browse' => 'Pārlūkot',
    'heading' => 'SQL',

    'subtitle_html' => 'Vaicājumu panelis tikai SELECT priekšrakstiem. Validators (parsēšanas laikā) un PRAGMA <code class="font-mono text-xs">query_only = 1</code> (dzinēja laikā) noraida visu, kas nav SELECT. Stingrs 5 sekunžu izpildes laika ierobežojums.',
    'advanced_off_strong' => 'Paplašinātais režīms ir IZSLĒGTS.',
    'advanced_off_hint' => 'Lai izpildītu vaicājumus, ieslēdziet paplašināto režīmu (Izstrādes režīms → Paplašināts).',
    'statement_label' => 'SELECT priekšraksts',
    'run' => 'Izpildīt',
    'rows_meta' => ':rows rindu · :durationms|:rows rinda · :durationms|:rows rindas · :durationms',
    'no_rows' => 'Vaicājums neatgrieza nevienu rindu.',

    'errors' => [
        'advanced_off' => 'Lai izpildītu vaicājumus, ieslēdziet paplašināto režīmu (Izstrādes režīms → Paplašināts).',
        'only_select' => 'Atļauti tikai SELECT priekšraksti. Noraidīšanas iemesls: :reason.',
        'timeout' => 'Vaicājums pārsniedza 5 sekunžu ierobežojumu. Precizējiet vaicājumu un mēģiniet vēlreiz.',
        'engine' => 'SQL kļūda: :message',
        'unknown_table' => 'Nezināma tabula.',
    ],
];
