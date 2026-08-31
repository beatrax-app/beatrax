<?php

declare(strict_types=1);

return [
    'heading' => 'Notifications',
    'page_title' => 'Notifications',
    'settings_link' => 'Paramètres de notification →',
    'load_more' => 'Afficher plus',

    'tablist_aria' => 'Cycle de vie des notifications',
    'tabs' => [
        'unread' => 'Non lues',
        'all' => 'Toutes',
        'dismissed' => 'Ignorées',
    ],

    'empty' => [
        'unread' => [
            'heading' => 'Tu es à jour',
            'body' => 'Les nouvelles notifications arrivent ici — rappels de paiement, alertes de budget et ta situation hebdomadaire.',
        ],
        'all' => [
            'heading' => 'Rien pour l\'instant',
            'body' => 'Beatrax te préviendra des paiements à venir et de tout ce qui mérite un coup d\'œil.',
        ],
        'dismissed' => [
            'heading' => 'Rien d\'ignoré',
            'body' => 'Les notifications que tu ignores sont conservées ici un certain temps.',
        ],
    ],

    'toast' => [
        'dismissed' => 'Ignorée',
        'restored' => 'Restaurée',
    ],
];
