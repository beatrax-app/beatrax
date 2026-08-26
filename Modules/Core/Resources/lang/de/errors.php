<?php

declare(strict_types=1);

return [
    'back' => 'Zurück zu Beatrax',

    '404' => [
        'title' => 'Diese Seite gibt es nicht',
        'body' => 'Der Link ist womöglich alt, oder die Seite wurde umbenannt. Mit deinen Daten ist alles in Ordnung.',
    ],
    '4xx' => [
        'title' => 'Diese Anfrage kann nicht verarbeitet werden',
        'body' => 'Die Seite wurde auf eine Weise geöffnet, die sie nicht erwartet. Deine Daten sind unverändert.',
    ],

    '419' => [
        'title' => 'Deine Sitzung ist abgelaufen',
        'body' => 'Du warst lange genug weg, dass die Seite veraltet ist. Öffne Beatrax erneut und mach weiter.',
    ],

    '500' => [
        'title' => 'Etwas ist schiefgelaufen',
        'body' => 'Das Problem wurde im Protokoll dieses Geräts festgehalten. Deine Daten wurden nicht verändert.',
    ],

    '503' => [
        'title' => 'Beatrax ist kurz nicht erreichbar',
        'body' => 'Ein Update oder eine Wartung wird gerade abgeschlossen. Versuch es gleich noch einmal.',
    ],
];
