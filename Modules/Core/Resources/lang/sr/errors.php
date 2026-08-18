<?php

declare(strict_types=1);

return [
    'back' => 'Nazad u Beatrax',

    '404' => [
        'title' => 'Ova stranica ne postoji',
        'body' => 'Link je možda star ili je stranica preimenovana. Sa tvojim podacima je sve u redu.',
    ],

    '419' => [
        'title' => 'Tvoja sesija je istekla',
        'body' => 'Bio si odsutan dovoljno dugo da stranica zastari. Otvori Beatrax ponovo i nastavi.',
    ],

    '500' => [
        'title' => 'Nešto je pošlo naopako',
        'body' => 'Problem je zapisan u dnevnik na ovom uređaju. Tvoji podaci nisu promenjeni.',
    ],

    '503' => [
        'title' => 'Beatrax nakratko nije dostupan',
        'body' => 'Završava se ažuriranje ili održavanje. Pokušaj ponovo za trenutak.',
    ],
];
