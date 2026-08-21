<?php

declare(strict_types=1);

return [
    'tables' => 'Lentelės',
    'schema_viewer_aria' => 'Schemos peržiūra',
    'columns' => 'stulpeliai',
    'indexes' => 'indeksai',
    'foreign_keys' => 'išoriniai raktai',
    'browse' => 'Naršyti',
    'heading' => 'SQL',

    'subtitle_html' => 'Tik SELECT užklausų skydelis. Tikrintuvas (analizės metu) ir PRAGMA <code class="font-mono text-xs">query_only = 1</code> (variklio metu) atmeta viską, kas nėra SELECT. Griežta 5 sekundžių trukmės riba.',
    'advanced_off_strong' => 'Išplėstinis režimas IŠJUNGTAS.',
    'advanced_off_hint' => 'Įjunk išplėstinį režimą (Kūrėjo režimas → Išplėstinis), kad galėtum vykdyti užklausas.',
    'statement_label' => 'SELECT sakinys',
    'run' => 'Vykdyti',
    'rows_meta' => ':rows eilutė · :durationms|:rows eilutės · :durationms|:rows eilučių · :durationms',
    'no_rows' => 'Užklausa negrąžino nė vienos eilutės.',

    'errors' => [
        'advanced_off' => 'Įjunk išplėstinį režimą (Kūrėjo režimas → Išplėstinis), kad galėtum vykdyti užklausas.',
        'only_select' => 'Leidžiami tik SELECT sakiniai. Atmetimo priežastis: :reason.',
        'timeout' => 'Užklausa viršijo 5 sekundžių ribą. Patikslink užklausą ir bandyk dar kartą.',
        'engine' => 'SQL klaida: :message',
        'unknown_table' => 'Nežinoma lentelė.',
    ],
];
