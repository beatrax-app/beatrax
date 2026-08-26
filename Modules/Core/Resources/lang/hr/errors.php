<?php

declare(strict_types=1);

return [
    'back' => 'Natrag u Beatrax',

    '404' => [
        'title' => 'Ova stranica ne postoji',
        'body' => 'Poveznica je možda stara ili je stranica preimenovana. S tvojim podacima je sve u redu.',
    ],
    '4xx' => [
        'title' => 'Ovaj zahtjev nije moguće obraditi',
        'body' => 'Stranica je otvorena na način koji ne očekuje. Tvoji podaci su nepromijenjeni.',
    ],

    '419' => [
        'title' => 'Tvoja sesija je istekla',
        'body' => 'Bio si odsutan dovoljno dugo da stranica zastari. Otvori Beatrax ponovno i nastavi.',
    ],

    '500' => [
        'title' => 'Nešto je pošlo po zlu',
        'body' => 'Problem je zapisan u zapisnik na ovom uređaju. Tvoji podaci nisu promijenjeni.',
    ],

    '503' => [
        'title' => 'Beatrax nakratko nije dostupan',
        'body' => 'Dovršava se ažuriranje ili održavanje. Pokušaj ponovno za trenutak.',
    ],
];
