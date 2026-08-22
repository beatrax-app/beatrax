<?php

declare(strict_types=1);

return [
    'title' => 'Izveštaji',
    'page_title' => 'Izveštaji · Beatrax',
    'saved_report' => ':count sačuvan izveštaj|:count sačuvana izveštaja|:count sačuvanih izveštaja',
    'pinned_count' => ':count od :max zakačen|:count od :max zakačena|:count od :max zakačenih',
    'dismiss' => 'Odbaci',

    'build_new' => 'Napravi novi izveštaj',
    'view_mode_aria' => 'Način prikaza',
    'cards' => 'Kartice',
    'list' => 'Lista',

    'empty' => [
        'heading' => 'Još nema sačuvanih izveštaja',
        'body' => 'Napravi jedan ispod i sačuvaj ga da se pojavi ovde.',
        'cta' => 'Napravi svoj prvi izveštaj →',
    ],

    'pin' => [
        'pinned_aria' => 'Zakačeno — otkači sa kontrolne table',
        'pin_aria' => 'Zakači — zakači na kontrolnu tablu',
        'pinned_title' => 'Zakačeno',
        'pin_title' => 'Zakači na kontrolnu tablu',
        'pinned_label' => 'Zakačeno',
        'pin_label' => 'Zakači',
    ],

    'open' => 'Otvori',
    'edit' => 'Izmeni',

    'delete_confirm' => 'Obrisati „:name”?',
    'delete_report' => 'Obriši izveštaj',
    'cancel' => 'Otkaži',
    'delete' => 'Obriši',
    'delete_aria' => 'Obriši :name',

    'col' => [
        'name' => 'Naziv',
        'summary' => 'Rezime',
        'pinned' => 'Zakačeno',
        'actions' => 'Radnje',
    ],

    'flash' => [
        'not_found' => 'Izveštaj nije pronađen (možda je obrisan u drugoj kartici).',
        'deleted' => 'Izveštaj je obrisan.',
    ],
    'pin_cap' => 'Možeš da zakačiš :max izveštaj. Otkači ga da dodaš ovaj.|Možeš da zakačiš najviše :max izveštaja. Otkači jedan da dodaš ovaj.|Možeš da zakačiš najviše :max izveštaja. Otkači jedan da dodaš ovaj.',

    'summary' => [
        'metric' => [
            'spend' => 'Potrošnja',
            'income' => 'Prihodi',
            'net' => 'Neto',
            'net_worth' => 'Neto vrednost',
            'fallback' => 'Iznos',
        ],
        'dimension' => [
            'category' => 'kategorija',
            'time_bucket' => 'vremenski interval',
            'counterparty' => 'druga strana',
            'account' => 'račun',
            'fallback' => 'kategorija',
        ],
        'period' => [
            'this_month' => 'Ovaj mesec',
            'last_3_months' => 'Poslednja 3 meseca',
            'last_6_months' => 'Poslednjih 6 meseci',
            'last_12_months' => 'Poslednjih 12 meseci',
            'ytd' => 'Od početka godine',
            'this_year' => 'Ova godina',
            'custom' => 'Prilagođeni opseg',
        ],
        'with_dimension' => ':metric · po :dimension · :period',
        'without_dimension' => ':metric · :period',
    ],
];
