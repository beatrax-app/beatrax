<?php

declare(strict_types=1);

return [
    'back' => 'Tilbage til Beatrax',

    '404' => [
        'title' => 'Denne side findes ikke',
        'body' => 'Linket er måske gammelt, eller siden har fået et nyt navn. Der er ikke noget galt med dine data.',
    ],

    '419' => [
        'title' => 'Din session er udløbet',
        'body' => 'Du var væk længe nok til, at siden blev forældet. Åbn Beatrax igen, og fortsæt.',
    ],

    '500' => [
        'title' => 'Noget gik galt',
        'body' => 'Problemet er skrevet i loggen på denne enhed. Dine data er ikke ændret.',
    ],

    '503' => [
        'title' => 'Beatrax er kortvarigt utilgængelig',
        'body' => 'En opdatering eller vedligeholdelse er ved at være færdig. Prøv igen om et øjeblik.',
    ],
];
