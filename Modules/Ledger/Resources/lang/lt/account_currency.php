<?php

declare(strict_types=1);

return [
    'heading' => 'Sąskaitos valiuta',
    'intro' => 'Valiuta, kuria išreikšta kiekviena sąskaita. Nauja sąskaita pradedama bazine valiuta.',
    'no_accounts' => 'Sąskaitų dar nėra.',
    'legend' => 'Sąskaitos :name valiuta',
    'label' => 'Valiuta',
    'help' => 'Valiuta, kuria ši sąskaita rodo savo likutį.',
    'save' => 'Įrašyti valiutą',
    'saved' => 'Įrašyta',

    'toast' => [
        'updated' => ':name dabar rodo sumas valiuta :currency.',
    ],

    'errors' => [
        'unknown' => 'Ši diegtis tokios valiutos nežino.',
    ],

    'warning' => [
        'intro' => 'Sąskaitos keitimas iš :from į :to tik pakeičia žymą. Niekas iš to, kas įrašyta, nekonvertuojama ir neperrašoma.',
        'baseline' => 'Pradinis likutis :amount lieka lygiai tokia pat suma ir nuo šiol skaitomas kaip :to.',
        'lines' => 'Šioje sąskaitoje šiuo metu yra:',
        'reads' => 'Po pakeitimo sąskaita rodo savo :to eilutę — nulį, jei valiuta :to nieko neturi.',
        'confirm' => 'Vis tiek keisti',
        'keep' => 'Palikti :currency',
    ],
];
