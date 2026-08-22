<?php

declare(strict_types=1);

return [
    'title' => 'Aruanded',
    'page_title' => 'Aruanded · Beatrax',
    'saved_report' => ':count salvestatud aruanne|:count salvestatud aruannet',
    'pinned_count' => ':count/:max kinnitatud|:count/:max kinnitatud',
    'dismiss' => 'Peida',

    'build_new' => 'Koosta uus aruanne',
    'view_mode_aria' => 'Vaaterežiim',
    'cards' => 'Kaardid',
    'list' => 'Loend',

    'empty' => [
        'heading' => 'Salvestatud aruandeid veel pole',
        'body' => 'Koosta allpool üks ja salvesta see, et seda siin näha.',
        'cta' => 'Koosta oma esimene aruanne →',
    ],

    'pin' => [
        'pinned_aria' => 'Kinnitatud — eemalda ülevaatelt',
        'pin_aria' => 'Kinnita — kinnita ülevaatele',
        'pinned_title' => 'Kinnitatud',
        'pin_title' => 'Kinnita ülevaatele',
        'pinned_label' => 'Kinnitatud',
        'pin_label' => 'Kinnita',
    ],

    'open' => 'Ava',
    'edit' => 'Muuda',

    'delete_confirm' => 'Kas kustutada „:name“?',
    'delete_report' => 'Kustuta aruanne',
    'cancel' => 'Tühista',
    'delete' => 'Kustuta',
    'delete_aria' => 'Kustuta :name',

    'col' => [
        'name' => 'Nimi',
        'summary' => 'Kokkuvõte',
        'pinned' => 'Kinnitatud',
        'actions' => 'Toimingud',
    ],

    'flash' => [
        'not_found' => 'Aruannet ei leitud (see võidi kustutada teisel vahelehel).',
        'deleted' => 'Aruanne on kustutatud.',
    ],
    'pin_cap' => 'Kinnitada saab :max aruande. Selle lisamiseks eemalda see.|Kinnitada saab kuni :max aruannet. Selle lisamiseks eemalda mõni teine.',

    'summary' => [
        'metric' => [
            'spend' => 'Kulud',
            'income' => 'Tulud',
            'net' => 'Neto',
            'net_worth' => 'Netoväärtus',
            'fallback' => 'Summa',
        ],
        'dimension' => [
            'category' => 'kategooria',
            'time_bucket' => 'ajavahemik',
            'counterparty' => 'vastaspool',
            'account' => 'konto',
            'fallback' => 'kategooria',
        ],
        'period' => [
            'this_month' => 'See kuu',
            'last_3_months' => 'Viimased 3 kuud',
            'last_6_months' => 'Viimased 6 kuud',
            'last_12_months' => 'Viimased 12 kuud',
            'ytd' => 'Aasta algusest',
            'this_year' => 'See aasta',
            'custom' => 'Kohandatud vahemik',
        ],
        'with_dimension' => ':metric · :dimension kaupa · :period',
        'without_dimension' => ':metric · :period',
    ],
];
