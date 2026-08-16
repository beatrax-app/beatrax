<?php

declare(strict_types=1);

return [
    'type_chip' => [
        'aria' => 'Tip druge strane: :type',
        'merchant' => 'Trgovac',
        'personal' => 'Privatno lice',
        'bank' => 'Banka',
        'government' => 'Državna ustanova',
        'self' => 'Sopstveni račun',
        'unknown' => 'Nepoznato',
    ],

    'filter_chips' => [
        'aria' => 'Filtriraj po tipu',
        'all' => 'Sve',
        'merchant' => 'Trgovci',
        'personal' => 'Privatna lica',
        'bank' => 'Banke',
        'government' => 'Državne ustanove',
        'self' => 'Sopstveni računi',
        'unknown' => 'Nepoznati',
    ],

    'cp_card' => [
        'aria' => 'Druga strana: :name',
        'recent_aria' => 'Nedavna aktivnost',
    ],

    'chain_flow' => [
        'aria_prefix' => 'Lanac finansiranja: ',
        'join' => ' do ',
    ],

    'iban_row' => [
        'label' => 'IBAN',
        'hidden_aria' => 'IBAN je sakriven — klikni Prikaži IBAN za prikaz',
        'show' => 'Prikaži IBAN',
        'hide' => 'Sakrij IBAN',
    ],

    'privacy_banner' => [
        'aria' => 'Obaveštenje o privatnosti za lični kontakt',
        'body' => '🔒 Ovo je lični kontakt. IBAN i lični podaci su podrazumevano sakriveni i nikada se ne dele u izvozima.',
    ],

    'self_stub' => [
        'aria' => 'Nije prava druga strana',
        'heading' => 'Ovo zapravo nije druga strana',

        'body_rest_html' => ' se ovde pojavljuje jer se u tvojim transakcijama javlja kao karika u lancu finansiranja između računa. Ali to je <strong>tvoj sopstveni račun</strong>, a ne neko s kim posluješ.',
        'body2' => 'Otvori prikaz računa za stanje, izvode i celu istoriju transakcija.',
        'open_cta' => 'Otvori prikaz računa :name →',
        'hide_cta' => 'Sakrij sa ove liste',
        'recent_legs' => 'Nedavne karike između računa',
    ],
];
