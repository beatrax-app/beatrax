<?php

declare(strict_types=1);

return [
    'back' => 'Tillbaka till Beatrax',

    '404' => [
        'title' => 'Den här sidan finns inte',
        'body' => 'Länken kan vara gammal, eller så har sidan bytt namn. Det är inget fel på dina uppgifter.',
    ],

    '419' => [
        'title' => 'Din session har gått ut',
        'body' => 'Du var borta tillräckligt länge för att sidan skulle bli inaktuell. Öppna Beatrax igen och fortsätt.',
    ],

    '500' => [
        'title' => 'Något gick fel',
        'body' => 'Problemet har skrivits till loggen på den här enheten. Dina uppgifter har inte ändrats.',
    ],

    '503' => [
        'title' => 'Beatrax är otillgängligt en kort stund',
        'body' => 'En uppdatering eller ett underhåll håller på att bli klart. Försök igen om en stund.',
    ],
];
