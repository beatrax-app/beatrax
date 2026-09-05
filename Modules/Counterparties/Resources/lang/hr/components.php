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

    'default_name' => [
        'bank_fee' => 'Bankovna naknada',
        'account_maintenance' => 'Naknada za vođenje računa',
        'monthly_fee' => 'Mjesečna naknada',
        'quarterly_fee' => 'Tromjesečna naknada',
        'annual_fee' => 'Godišnja naknada',
        'card_fee' => 'Naknada za karticu',
        'transaction_fee' => 'Naknada za transakciju',
        'transfer_fee' => 'Naknada za prijenos',
        'withdrawal_fee' => 'Naknada za podizanje',
        'transaction_levy' => 'Porez na transakcije',
        'foreign_transaction_fee' => 'Naknada za konverziju valute',
        'commission' => 'Provizija',
        'debit_interest' => 'Kamata na dugovanje',
        'overdraft' => 'Naknada za prekoračenje',
        'overdraft_interest' => 'Kamata na prekoračenje',
        'insufficient_funds' => 'Naknada za nedostatna sredstva',
        'penalty_fee' => 'Kazna',
        'loan_arrangement_fee' => 'Naknada za odobrenje kredita',
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
        // i18n-review: hr · hidden_aria_touch — the same line for a touch
        // screen; check the verb governs this case.
        'hidden_aria_touch' => 'IBAN je skriven — dodirni Prikaži IBAN za prikaz',
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
