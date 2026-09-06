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

    'default_name' => [
        'bank_fee' => 'Bankarska naknada',
        'account_maintenance' => 'Naknada za vođenje računa',
        'monthly_fee' => 'Mesečna naknada',
        'quarterly_fee' => 'Tromesečna naknada',
        'annual_fee' => 'Godišnja naknada',
        'card_fee' => 'Naknada za karticu',
        'transaction_fee' => 'Naknada za transakciju',
        'transfer_fee' => 'Naknada za prenos',
        'withdrawal_fee' => 'Naknada za podizanje',
        'transaction_levy' => 'Porez na transakcije',
        'foreign_transaction_fee' => 'Naknada za konverziju valute',
        'commission' => 'Provizija',
        'debit_interest' => 'Kamata na dugovanje',
        'overdraft' => 'Naknada za prekoračenje',
        'overdraft_interest' => 'Kamata na prekoračenje',
        'insufficient_funds' => 'Naknada za nedovoljna sredstva',
        'penalty_fee' => 'Kazna',
        'loan_arrangement_fee' => 'Naknada za odobrenje kredita',
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
        // i18n-review: sr · hidden_aria_touch — the same line for a touch
        // screen; check the verb governs this case.
        'hidden_aria_touch' => 'IBAN je sakriven — dodirni Prikaži IBAN za prikaz',
        'show' => 'Prikaži IBAN',
        'hide' => 'Sakrij IBAN',
    ],

    'privacy_banner' => [
        'aria' => 'Obaveštenje o privatnosti za lični kontakt',
        'body' => '🔒 Ovo je lični kontakt. IBAN je sakriven dok ga ne prikažeš i ne ulazi u izvoze. Ime kontakta se i dalje pojavljuje svuda gde se pojavljuju i njegove transakcije.',
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
