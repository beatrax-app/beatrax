<?php

declare(strict_types=1);

return [
    'title' => 'Rapporter',
    'page_title' => 'Rapporter · Beatrax',
    'saved_report' => ':count gemt rapport|:count gemte rapporter',
    'pinned_count' => 'fastgjort',
    'dismiss' => 'Luk',

    'build_new' => 'Byg en ny rapport',
    'view_mode_aria' => 'Visningstilstand',
    'cards' => 'Kort',
    'list' => 'Liste',

    'empty' => [
        'heading' => 'Ingen gemte rapporter endnu',
        'body' => 'Byg en nedenfor, og gem den for at se den her.',
        'cta' => 'Byg din første rapport →',
    ],

    'pin' => [
        'pinned_aria' => 'Fastgjort — frigør fra overblikket',
        'pin_aria' => 'Fastgør — fastgør til overblikket',
        'pinned_title' => 'Fastgjort',
        'pin_title' => 'Fastgør til overblikket',
        'pinned_label' => 'Fastgjort',
        'pin_label' => 'Fastgør',
    ],

    'open' => 'Åbn',
    'edit' => 'Redigér',

    'delete_confirm' => 'Slet ":name"?',
    'delete_report' => 'Slet rapporten',
    'cancel' => 'Annullér',
    'delete' => 'Slet',
    'delete_aria' => 'Slet :name',

    'col' => [
        'name' => 'Navn',
        'summary' => 'Sammendrag',
        'pinned' => 'Fastgjort',
        'actions' => 'Handlinger',
    ],

    'flash' => [
        'not_found' => 'Rapporten blev ikke fundet (den kan være slettet i en anden fane).',
        'deleted' => 'Rapporten er slettet.',
    ],
    'pin_cap' => 'Du kan fastgøre op til 3 rapporter. Frigør en for at tilføje denne.',

    'summary' => [
        'metric' => [
            'spend' => 'Udgifter',
            'income' => 'Indtægter',
            'net' => 'Netto',
            'net_worth' => 'Nettoformue',
            'fallback' => 'Beløb',
        ],
        'dimension' => [
            'category' => 'kategori',
            'time_bucket' => 'tidsinterval',
            'counterparty' => 'modpart',
            'account' => 'konto',
            'fallback' => 'kategori',
        ],
        'period' => [
            'this_month' => 'Denne måned',
            'last_3_months' => 'Sidste 3 måneder',
            'last_6_months' => 'Sidste 6 måneder',
            'last_12_months' => 'Sidste 12 måneder',
            'ytd' => 'År til dato',
            'this_year' => 'I år',
            'custom' => 'Tilpasset interval',
        ],
        'with_dimension' => ':metric · efter :dimension · :period',
        'without_dimension' => ':metric · :period',
    ],
];
