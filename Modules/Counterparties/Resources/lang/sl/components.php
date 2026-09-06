<?php

declare(strict_types=1);

return [
    'type_chip' => [
        'aria' => 'Vrsta nasprotne stranke: :type',
        'merchant' => 'Trgovec',
        'personal' => 'Zasebna oseba',
        'bank' => 'Banka',
        'government' => 'Državna ustanova',
        'self' => 'Lastni račun',
        'unknown' => 'Neznano',
    ],

    'filter_chips' => [
        'aria' => 'Filtriraj po vrsti',
        'all' => 'Vse',
        'merchant' => 'Trgovci',
        'personal' => 'Zasebne osebe',
        'bank' => 'Banke',
        'government' => 'Državne ustanove',
        'self' => 'Lastni računi',
        'unknown' => 'Neznani',
    ],

    'default_name' => [
        'bank_fee' => 'Bančna provizija',
        'account_maintenance' => 'Nadomestilo za vodenje računa',
        'monthly_fee' => 'Mesečno nadomestilo',
        'quarterly_fee' => 'Četrtletno nadomestilo',
        'annual_fee' => 'Letno nadomestilo',
        'card_fee' => 'Nadomestilo za kartico',
        'transaction_fee' => 'Nadomestilo za transakcijo',
        'transfer_fee' => 'Nadomestilo za prenos',
        'withdrawal_fee' => 'Nadomestilo za dvig',
        'transaction_levy' => 'Davek na transakcije',
        'foreign_transaction_fee' => 'Nadomestilo za menjavo valute',
        'commission' => 'Provizija',
        'debit_interest' => 'Obresti na dolg',
        'overdraft' => 'Nadomestilo za prekoračitev',
        'overdraft_interest' => 'Obresti za prekoračitev',
        'insufficient_funds' => 'Nadomestilo za nezadostna sredstva',
        'penalty_fee' => 'Kazen',
        'loan_arrangement_fee' => 'Nadomestilo za odobritev kredita',
    ],

    'cp_card' => [
        'aria' => 'Nasprotna stranka: :name',
        'recent_aria' => 'Nedavna dejavnost',
    ],

    'chain_flow' => [
        'aria_prefix' => 'Veriga financiranja: ',
        'join' => ' do ',
    ],

    'iban_row' => [
        'label' => 'IBAN',
        'hidden_aria' => 'IBAN je skrit — klikni Prikaži IBAN za prikaz',
        // i18n-review: sl · hidden_aria_touch — the same line for a touch
        // screen; check the verb governs this case.
        'hidden_aria_touch' => 'IBAN je skrit — tapni Prikaži IBAN za prikaz',
        'show' => 'Prikaži IBAN',
        'hide' => 'Skrij IBAN',
    ],

    'privacy_banner' => [
        'aria' => 'Obvestilo o zasebnosti za osebni stik',
        'body' => '🔒 To je osebni stik. IBAN je skrit, dokler ga ne prikažeš, in ne pride v izvoze. Ime stika se še vedno pojavi povsod, kjer se pojavijo njegove transakcije.',
    ],

    'self_stub' => [
        'aria' => 'Ni prava nasprotna stranka',
        'heading' => 'To pravzaprav ni nasprotna stranka',

        'body_rest_html' => ' se tukaj pojavi, ker se v tvojih transakcijah pojavlja kot člen v verigi financiranja med računi. A to je <strong>tvoj lastni račun</strong>, ne nekdo, s katerim posluješ.',
        'body2' => 'Odpri pogled računa za stanje, izpiske in celotno zgodovino transakcij.',
        'open_cta' => 'Odpri pogled računa :name →',
        'hide_cta' => 'Skrij s tega seznama',
        'recent_legs' => 'Nedavni členi med računi',
    ],
];
