<?php

declare(strict_types=1);

return [
    'type_chip' => [
        'aria' => 'Vrsta protustranke: :type',
        'merchant' => 'Trgovac',
        'personal' => 'Privatna osoba',
        'bank' => 'Banka',
        'government' => 'Državna ustanova',
        'self' => 'Vlastiti račun',
        'unknown' => 'Nepoznato',
    ],

    'filter_chips' => [
        'aria' => 'Filtriraj po vrsti',
        'all' => 'Sve',
        'merchant' => 'Trgovci',
        'personal' => 'Privatne osobe',
        'bank' => 'Banke',
        'government' => 'Državne ustanove',
        'self' => 'Vlastiti računi',
        'unknown' => 'Nepoznati',
    ],

    'cp_card' => [
        'aria' => 'Protustranka: :name',
        'recent_aria' => 'Nedavna aktivnost',
    ],

    'chain_flow' => [
        'aria_prefix' => 'Lanac financiranja: ',
        'join' => ' do ',
    ],

    'iban_row' => [
        'label' => 'IBAN',
        'hidden_aria' => 'IBAN je skriven — klikni Prikaži IBAN za prikaz',
        'show' => 'Prikaži IBAN',
        'hide' => 'Sakrij IBAN',
    ],

    'privacy_banner' => [
        'aria' => 'Obavijest o privatnosti za osobni kontakt',
        'body' => '🔒 Ovo je osobni kontakt. IBAN i osobni podaci skriveni su prema zadanome i nikad se ne dijele u izvozima.',
    ],

    'self_stub' => [
        'aria' => 'Nije prava protustranka',
        'heading' => 'Ovo zapravo nije protustranka',

        'body_rest_html' => ' se ovdje pojavljuje jer se u tvojim transakcijama javlja kao karika u lancu financiranja između računa. Ali to je <strong>tvoj vlastiti račun</strong>, a ne netko s kim posluješ.',
        'body2' => 'Otvori prikaz računa za stanje, izvode i cijelu povijest transakcija.',
        'open_cta' => 'Otvori prikaz računa :name →',
        'hide_cta' => 'Sakrij s ovog popisa',
        'recent_legs' => 'Nedavne karike između računa',
    ],
];
