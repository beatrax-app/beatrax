<?php

declare(strict_types=1);

return [
    'title' => 'Izvješća',
    'page_title' => 'Izvješća · Beatrax',
    'saved_report' => ':count spremljeno izvješće|:count spremljena izvješća|:count spremljenih izvješća',
    'pinned_count' => ':count od :max prikvačeno|:count od :max prikvačena|:count od :max prikvačenih',
    'dismiss' => 'Odbaci',

    'build_new' => 'Izradi novo izvješće',
    'view_mode_aria' => 'Način prikaza',
    'cards' => 'Kartice',
    'list' => 'Popis',

    'empty' => [
        'heading' => 'Još nema spremljenih izvješća',
        'body' => 'Izradi jedno u nastavku i spremi ga da se pojavi ovdje.',
        'cta' => 'Izradi svoje prvo izvješće →',
    ],

    'pin' => [
        'pinned_aria' => 'Prikvačeno — otkvači s nadzorne ploče',
        'pin_aria' => 'Prikvači — prikvači na nadzornu ploču',
        'pinned_title' => 'Prikvačeno',
        'pin_title' => 'Prikvači na nadzornu ploču',
        'pinned_label' => 'Prikvačeno',
        'pin_label' => 'Prikvači',
    ],

    'open' => 'Otvori',
    'edit' => 'Uredi',

    'delete_confirm' => 'Izbrisati „:name”?',
    'delete_report' => 'Izbriši izvješće',
    'cancel' => 'Odustani',
    'delete' => 'Izbriši',
    'delete_aria' => 'Izbriši :name',

    'col' => [
        'name' => 'Naziv',
        'summary' => 'Sažetak',
        'pinned' => 'Prikvačeno',
        'actions' => 'Radnje',
    ],

    'flash' => [
        'not_found' => 'Izvješće nije pronađeno (možda je izbrisano u drugoj kartici).',
        'deleted' => 'Izvješće je izbrisano.',
    ],
    'pin_cap' => 'Možeš prikvačiti :max izvješće. Otkvači ga da dodaš ovo.|Možeš prikvačiti najviše :max izvješća. Otkvači jedno da dodaš ovo.|Možeš prikvačiti najviše :max izvješća. Otkvači jedno da dodaš ovo.',

    'summary' => [
        'metric' => [
            'spend' => 'Potrošnja',
            'income' => 'Prihodi',
            'net' => 'Neto',
            'net_worth' => 'Neto vrijednost',
            'fallback' => 'Iznos',
        ],
        'dimension' => [
            'category' => 'kategorija',
            'time_bucket' => 'vremenski interval',
            'counterparty' => 'protustranka',
            'account' => 'račun',
            'fallback' => 'kategorija',
        ],
        'period' => [
            'this_month' => 'Ovaj mjesec',
            'last_3_months' => 'Zadnja 3 mjeseca',
            'last_6_months' => 'Zadnjih 6 mjeseci',
            'last_12_months' => 'Zadnjih 12 mjeseci',
            'ytd' => 'Od početka godine',
            'this_year' => 'Ova godina',
            'custom' => 'Prilagođeni raspon',
        ],
        'with_dimension' => ':metric · po :dimension · :period',
        'without_dimension' => ':metric · :period',
    ],
];
