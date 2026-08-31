<?php

declare(strict_types=1);

return [
    'heading' => 'Notifikationer',
    'page_title' => 'Notifikationer',
    'settings_link' => 'Notifikationsindstillinger →',
    'load_more' => 'Indlæs mere',

    'tablist_aria' => 'Notifikationens livscyklus',
    'tabs' => [
        'unread' => 'Ulæste',
        'all' => 'Alle',
        'dismissed' => 'Lukkede',
    ],

    'empty' => [
        'unread' => [
            'heading' => 'Du er helt opdateret',
            'body' => 'Nye notifikationer havner her — betalingspåmindelser, budgetvarsler og din ugentlige status.',
        ],
        'all' => [
            'heading' => 'Intet endnu',
            'body' => 'Beatrax giver dig besked om kommende betalinger og alt andet, der skal ses efter.',
        ],
        'dismissed' => [
            'heading' => 'Intet lukket',
            'body' => 'Notifikationer, du lukker, gemmes her et stykke tid.',
        ],
    ],

    'toast' => [
        'dismissed' => 'Lukket',
        'restored' => 'Gendannet',
    ],
];
