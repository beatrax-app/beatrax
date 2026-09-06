<?php

declare(strict_types=1);

return [
    'heading' => 'Navrhnúť priradenie',
    'intro' => 'Otvorí GitHub v prehliadači s vyplneným návrhom. Idú s ním len vzor, názov, kategória a región vyššie — a vzor je popis presne tak, ako ho zapísal tvoj výpis. Tvoje meno ani e-mail toto zariadenie nikdy neopustia.',

    'pattern' => 'Vzor',
    'name' => 'Zrozumiteľný názov',
    'name_placeholder' => 'napr. Albert Heijn',
    'category' => 'Kategória (nepovinné)',
    'category_placeholder' => 'napr. Potraviny',
    'region' => 'Región',

    'regions' => [
        'other' => 'Iný',
    ],

    'yaml_preview' => 'Náhľad YAML',

    'cancel' => 'Zrušiť',
    'submit' => 'Otvoriť na GitHube',

    'toast' => 'Návrh sa otvoril v prehliadači.',

    'errors' => [
        'pattern_required' => 'Vzor je povinný.',
        'name_required' => 'Názov je povinný.',
        'browser_refused' => 'Prehliadač sa nepodarilo otvoriť, takže sa nič neodoslalo a nič toto zariadenie neopustilo. Skús to znova, alebo náhľad YAML vyššie vlož do pull requestu sám.',
    ],
];
