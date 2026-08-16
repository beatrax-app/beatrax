<?php

declare(strict_types=1);

return [
    'help_paypal' => 'PayPal izvozi ne sadrže redove sa stanjem, pa ovo postavi ručno.',
    'help_asn' => 'Automatski usidreno prema tvom poslednjem izvodu. Nadjačaj samo ako znaš da se stvarno stanje razlikuje.',
    'help_default' => 'Nadjačaj samo ako znaš da se trenutno stvarno stanje razlikuje od onoga što Beatrax izračuna.',

    'legend' => 'Početno stanje prognoze za :name',
    'opening_label' => 'Početno stanje',
    'opening_placeholder' => 'npr. 1.250,00',
    'as_of_label' => 'Početno stanje na dan',
    'as_of_help' => 'Datum na koji gornji iznos važi.',

    'divergence' => 'Ovo odstupa više od €500 od stanja koje Beatrax izračuna iz tvojih uvezenih transakcija. Da li si siguran?',
    'use_beatrax' => 'Koristi Beatraxov iznos',
    'use_mine' => 'Koristi moj iznos',

    'save' => 'Sačuvaj početno stanje',
    'saved' => 'Sačuvano.',

    'toast' => [
        'updated' => 'Početno stanje je ažurirano.',
    ],

    'errors' => [
        'invalid_number' => 'Početno stanje mora biti ispravan broj.',
        'date_required' => 'Izaberi datum na koji se ovo početno stanje odnosi.',
        'date_invalid' => 'Datum početnog stanja mora biti ispravan ISO datum (YYYY-MM-DD).',
        'date_future' => 'Datum početnog stanja ne može biti u budućnosti.',
    ],
];
