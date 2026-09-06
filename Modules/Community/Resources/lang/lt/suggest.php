<?php

declare(strict_types=1);

return [
    'heading' => 'Siūlyti susiejimą',
    'intro' => 'Naršyklėje atveria GitHub su jau užpildytu pasiūlymu. Kartu keliauja tik viršuje esantis šablonas, pavadinimas, kategorija ir regionas — o šablonas yra tas aprašas, kaip jį užrašė tavo sąskaitos išrašas. Tavo vardas ir el. pašto adresas niekada neišeina iš šio įrenginio.',

    'pattern' => 'Šablonas',
    'name' => 'Aiškus pavadinimas',
    'name_placeholder' => 'pvz. Albert Heijn',
    'category' => 'Kategorija (neprivaloma)',
    'category_placeholder' => 'pvz. Maisto prekės',
    'region' => 'Regionas',

    'regions' => [
        'other' => 'Kita',
    ],

    'yaml_preview' => 'YAML peržiūra',

    'cancel' => 'Atšaukti',
    'submit' => 'Atverti GitHub',

    'toast' => 'Pasiūlymas atvertas tavo naršyklėje.',

    'errors' => [
        'pattern_required' => 'Šablonas privalomas.',
        'name_required' => 'Pavadinimas privalomas.',
        'browser_refused' => 'Naršyklės nepavyko atidaryti, todėl niekas nebuvo išsiųsta ir niekas nepaliko šio įrenginio. Bandyk dar kartą arba pats įklijuok viršuje esančią YAML peržiūrą į pull request.',
    ],
];
