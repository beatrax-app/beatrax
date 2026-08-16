<?php

declare(strict_types=1);

return [
    'title' => 'Poročila',
    'page_title' => 'Poročila · Beatrax',
    'saved_report' => 'shranjeno poročilo|shranjeni poročili|shranjena poročila|shranjenih poročil',
    'pinned_count' => 'pripeto',
    'dismiss' => 'Opusti',

    'build_new' => 'Sestavi novo poročilo',
    'view_mode_aria' => 'Način prikaza',
    'cards' => 'Kartice',
    'list' => 'Seznam',

    'empty' => [
        'heading' => 'Shranjenih poročil še ni',
        'body' => 'Sestavi ga spodaj in shrani, da se prikaže tukaj.',
        'cta' => 'Sestavi svoje prvo poročilo →',
    ],

    'pin' => [
        'pinned_aria' => 'Pripeto — odpni z nadzorne plošče',
        'pin_aria' => 'Pripni — pripni na nadzorno ploščo',
        'pinned_title' => 'Pripeto',
        'pin_title' => 'Pripni na nadzorno ploščo',
        'pinned_label' => 'Pripeto',
        'pin_label' => 'Pripni',
    ],

    'open' => 'Odpri',
    'edit' => 'Uredi',

    'delete_confirm' => 'Izbrisati „:name“?',
    'delete_report' => 'Izbriši poročilo',
    'cancel' => 'Prekliči',
    'delete' => 'Izbriši',
    'delete_aria' => 'Izbriši :name',

    'col' => [
        'name' => 'Ime',
        'summary' => 'Povzetek',
        'pinned' => 'Pripeto',
        'actions' => 'Dejanja',
    ],

    'flash' => [
        'not_found' => 'Poročila ni bilo mogoče najti (morda je bilo izbrisano v drugem zavihku).',
        'deleted' => 'Poročilo je izbrisano.',
    ],
    'pin_cap' => 'Pripneš lahko največ 3 poročila. Odpni eno, da dodaš to.',

    'summary' => [
        'metric' => [
            'spend' => 'Poraba',
            'income' => 'Prihodki',
            'net' => 'Neto',
            'net_worth' => 'Neto vrednost',
            'fallback' => 'Znesek',
        ],
        'dimension' => [
            'category' => 'kategorija',
            'time_bucket' => 'časovni interval',
            'counterparty' => 'nasprotna stranka',
            'account' => 'račun',
            'fallback' => 'kategorija',
        ],
        'period' => [
            'this_month' => 'Ta mesec',
            'last_3_months' => 'Zadnji 3 meseci',
            'last_6_months' => 'Zadnjih 6 mesecev',
            'last_12_months' => 'Zadnjih 12 mesecev',
            'ytd' => 'Od začetka leta',
            'this_year' => 'To leto',
            'custom' => 'Poljuben razpon',
        ],
        'with_dimension' => ':metric · po :dimension · :period',
        'without_dimension' => ':metric · :period',
    ],
];
