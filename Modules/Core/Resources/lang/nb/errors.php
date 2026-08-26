<?php

declare(strict_types=1);

return [
    'back' => 'Tilbake til Beatrax',

    '404' => [
        'title' => 'Denne siden finnes ikke',
        'body' => 'Lenken kan være gammel, eller siden kan ha fått nytt navn. Det er ingenting galt med dataene dine.',
    ],
    '4xx' => [
        'title' => 'Denne forespørselen kan ikke håndteres',
        'body' => 'Siden ble åpnet på en måte den ikke forventer. Dataene dine er uendret.',
    ],

    '419' => [
        'title' => 'Økten din er utløpt',
        'body' => 'Du var borte lenge nok til at siden ble foreldet. Åpne Beatrax igjen og fortsett.',
    ],

    '500' => [
        'title' => 'Noe gikk galt',
        'body' => 'Problemet er skrevet i loggen på denne enheten. Dataene dine er ikke endret.',
    ],

    '503' => [
        'title' => 'Beatrax er kortvarig utilgjengelig',
        'body' => 'En oppdatering eller vedlikeholdsoppgave blir ferdig. Prøv igjen om et øyeblikk.',
    ],
];
