<?php

declare(strict_types=1);

return [
    'help_paypal' => 'PayPal izvozi ne sadrže retke sa stanjem, pa ovo postavi ručno.',
    'help_asn' => 'Automatski usidreno prema tvojem posljednjem izvodu. Nadjačaj samo ako znaš da se stvarno stanje razlikuje.',
    'help_default' => 'Nadjačaj samo ako znaš da se trenutno stvarno stanje razlikuje od onoga što Beatrax izračuna.',

    'legend' => 'Početno stanje prognoze za :name',
    'opening_label' => 'Početno stanje',
    'opening_placeholder' => 'npr. 1.250,00',
    'as_of_label' => 'Početno stanje na dan',
    'as_of_help' => 'Datum na koji gornji iznos vrijedi.',

    'divergence' => 'Ovo odstupa više od €500 od stanja koje Beatrax izračuna iz tvojih uvezenih transakcija. Jesi li siguran?',
    'use_beatrax' => 'Koristi Beatraxov iznos',
    'use_mine' => 'Koristi moj iznos',

    'save' => 'Spremi početno stanje',
    'saved' => 'Spremljeno.',

    'toast' => [
        'updated' => 'Početno stanje je ažurirano.',
    ],

    'errors' => [
        'invalid_number' => 'Početno stanje mora biti ispravan broj.',
        'date_required' => 'Odaberi datum na koji se ovo početno stanje odnosi.',
        'date_invalid' => 'Datum početnog stanja mora biti ispravan ISO datum (YYYY-MM-DD).',
        'date_future' => 'Datum početnog stanja ne može biti u budućnosti.',
    ],
];
