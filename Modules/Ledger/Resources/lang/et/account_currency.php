<?php

declare(strict_types=1);

return [
    'heading' => 'Konto valuuta',
    'intro' => 'Valuuta, milles iga konto on nomineeritud. Uus konto algab baasvaluutas.',
    'no_accounts' => 'Kontosid veel pole.',
    'legend' => 'Konto :name valuuta',
    'label' => 'Valuuta',
    'help' => 'Valuuta, milles see konto oma saldot näitab.',
    'save' => 'Salvesta valuuta',
    'saved' => 'Salvestatud',

    'toast' => [
        'updated' => ':name näitab nüüd summasid valuutas :currency.',
    ],

    'errors' => [
        'unknown' => 'See paigaldus ei tunne sellist valuutat.',
    ],

    'warning' => [
        'intro' => 'Konto muutmine valuutalt :from valuutale :to üksnes muudab märgistust. Midagi salvestatut ei teisendata ega kirjutata üle.',
        'baseline' => 'Algsaldo :amount jääb täpselt samaks arvuks ja loetakse edaspidi valuutana :to.',
        'lines' => 'Sellel kontol on praegu:',
        'reads' => 'Pärast muudatust näitab see konto oma :to rida — null, kui tal pole valuutas :to midagi.',
        'confirm' => 'Muuda siiski',
        'keep' => 'Jää :currency juurde',
    ],
];
