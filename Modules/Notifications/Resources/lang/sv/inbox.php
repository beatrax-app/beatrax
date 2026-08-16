<?php

declare(strict_types=1);

return [
    'heading' => 'Notiser',
    'page_title' => 'Notiser',
    'settings_link' => 'Notisinställningar →',
    'load_more' => 'Ladda mer',

    'tablist_aria' => 'Notisernas livscykel',
    'tabs' => [
        'unread' => 'Olästa',
        'all' => 'Alla',
        'dismissed' => 'Stängda',
    ],

    'empty' => [
        'unread' => [
            'heading' => 'Du är ikapp',
            'body' => 'Nya notiser hamnar här — betalningspåminnelser, budgetvarningar och din veckovisa ställning.',
        ],
        'all' => [
            'heading' => 'Inget än',
            'body' => 'Beatrax hör av sig om kommande betalningar och allt annat som behöver ses över.',
        ],
        'dismissed' => [
            'heading' => 'Inget stängt',
            'body' => 'Notiser som du stänger sparas här en tid.',
        ],
    ],

    'toast' => [
        'dismissed' => 'Stängd — Ångra',
        'restored' => 'Återställd',
    ],
];
