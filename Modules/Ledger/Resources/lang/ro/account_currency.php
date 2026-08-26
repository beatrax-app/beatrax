<?php

declare(strict_types=1);

return [
    'heading' => 'Moneda contului',
    'intro' => 'Moneda în care este denominat fiecare cont. Un cont nou începe în moneda de bază.',
    'no_accounts' => 'Încă nu există conturi.',
    'legend' => 'Moneda contului :name',
    'label' => 'Monedă',
    'help' => 'Moneda în care acest cont își raportează soldul.',
    'save' => 'Salvează moneda',
    'saved' => 'Salvat',

    'toast' => [
        'updated' => ':name raportează acum în :currency.',
    ],

    'errors' => [
        'unknown' => 'Această instalare nu cunoaște moneda respectivă.',
    ],

    'warning' => [
        'intro' => 'Schimbarea contului din :from în :to doar îl reetichetează. Nimic din ce este stocat nu este convertit sau rescris.',
        'baseline' => 'Soldul inițial de :amount rămâne exact această sumă și de acum este citit ca :to.',
        'lines' => 'Acest cont conține în prezent:',
        'reads' => 'După schimbare, contul raportează linia sa :to — zero dacă nu deține nimic în :to.',
        'confirm' => 'Schimbă oricum',
        'keep' => 'Păstrează :currency',
    ],
];
