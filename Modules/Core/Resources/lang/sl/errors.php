<?php

declare(strict_types=1);

return [
    'back' => 'Nazaj v Beatrax',

    '404' => [
        'title' => 'Ta stran ne obstaja',
        'body' => 'Povezava je morda stara ali pa se je stran preimenovala. S tvojimi podatki je vse v redu.',
    ],
    '4xx' => [
        'title' => 'Te zahteve ni mogoče obdelati',
        'body' => 'Stran se je odprla na način, ki ga ne pričakuje. Tvoji podatki so nespremenjeni.',
    ],

    '419' => [
        'title' => 'Tvoja seja je potekla',
        'body' => 'Bil si odsoten dovolj dolgo, da je stran zastarala. Znova odpri Beatrax in nadaljuj.',
    ],

    '500' => [
        'title' => 'Nekaj je šlo narobe',
        'body' => 'Težava je zapisana v dnevnik te naprave. Tvoji podatki se niso spremenili.',
    ],

    '503' => [
        'title' => 'Beatrax za trenutek ni dosegljiv',
        'body' => 'Zaključuje se posodobitev ali vzdrževanje. Poskusi čez trenutek.',
    ],
];
