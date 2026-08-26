<?php

declare(strict_types=1);

return [
    'back' => 'Tagasi Beatraxi',

    '404' => [
        'title' => 'Seda lehte ei ole',
        'body' => 'Link võib olla vana või leht on ümber nimetatud. Sinu andmetega on kõik korras.',
    ],
    '4xx' => [
        'title' => 'Seda päringut ei saa täita',
        'body' => 'Leht avati viisil, mida see ei oota. Sinu andmed on muutumatud.',
    ],

    '419' => [
        'title' => 'Sinu sessioon aegus',
        'body' => 'Olid ära piisavalt kaua, et leht aeguks. Ava Beatrax uuesti ja jätka.',
    ],

    '500' => [
        'title' => 'Midagi läks valesti',
        'body' => 'Probleem on kirjutatud selle seadme logisse. Sinu andmeid ei muudetud.',
    ],

    '503' => [
        'title' => 'Beatrax pole hetkeks saadaval',
        'body' => 'Uuendus või hooldustöö on lõppemas. Proovi hetke pärast uuesti.',
    ],
];
