<?php

declare(strict_types=1);

return [
    'heading' => 'Notifications',
    'page_title' => 'Notifications',
    'settings_link' => 'Notification settings →',
    'load_more' => 'Load more',

    'tablist_aria' => 'Notification lifecycle',
    'tabs' => [
        'unread' => 'Unread',
        'all' => 'All',
        'dismissed' => 'Dismissed',
    ],

    'empty' => [
        'unread' => [
            'heading' => "You're all caught up",
            'body' => 'New notifications land here — payment reminders, budget nudges, and your weekly position.',
        ],
        'all' => [
            'heading' => 'Nothing yet',
            'body' => 'Beatrax will let you know about upcoming payments and anything that needs a look.',
        ],
        'dismissed' => [
            'heading' => 'Nothing dismissed',
            'body' => 'Notifications you dismiss are kept here for a while.',
        ],
    ],

    'toast' => [
        'dismissed' => 'Dismissed — Undo',
        'restored' => 'Restored',
    ],
];
