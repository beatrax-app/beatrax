<?php

declare(strict_types=1);

return [
    'heading' => 'Měna účtu',
    'intro' => 'Měna, ve které je každý účet denominován. Nový účet začíná v základní měně.',
    'no_accounts' => 'Zatím žádné účty.',
    'legend' => 'Měna účtu :name',
    'label' => 'Měna',
    'help' => 'Měna, ve které tento účet uvádí svůj zůstatek.',
    'save' => 'Uložit měnu',
    'saved' => 'Uloženo',

    'toast' => [
        'updated' => ':name nyní uvádí částky v :currency.',
    ],

    'errors' => [
        'unknown' => 'Tuto měnu tato instalace nezná.',
    ],

    'warning' => [
        'intro' => 'Změna účtu z :from na :to pouze změní označení. Nic z uložených dat se nepřepočítává ani nepřepisuje.',
        'baseline' => 'Počáteční zůstatek :amount zůstává přesně touto částkou a nadále se čte jako :to.',
        'lines' => 'Tento účet nyní obsahuje:',
        'reads' => 'Po změně účet uvádí svůj řádek :to — nulu, pokud v :to nic nedrží.',
        'confirm' => 'Přesto změnit',
        'keep' => 'Ponechat :currency',
    ],
];
