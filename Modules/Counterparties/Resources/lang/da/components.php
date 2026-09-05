<?php

declare(strict_types=1);

return [
    'type_chip' => [
        'aria' => 'Modpartstype: :type',
        'merchant' => 'Forhandler',
        'personal' => 'Privat',
        'bank' => 'Bank',
        'government' => 'Offentlig myndighed',
        'self' => 'Egen',
        'unknown' => 'Ukendt',
    ],

    'filter_chips' => [
        'aria' => 'Filtrér efter type',
        'all' => 'Alle',
        'merchant' => 'Forhandlere',
        'personal' => 'Privat',
        'bank' => 'Banker',
        'government' => 'Offentlige myndigheder',
        'self' => 'Egne',
        'unknown' => 'Ukendte',
    ],

    'default_name' => [
        'bank_fee' => 'Bankgebyr',
        'account_maintenance' => 'Kontogebyr',
        'monthly_fee' => 'Månedsgebyr',
        'quarterly_fee' => 'Kvartalsgebyr',
        'annual_fee' => 'Årsgebyr',
        'card_fee' => 'Kortgebyr',
        'transaction_fee' => 'Transaktionsgebyr',
        'transfer_fee' => 'Overførselsgebyr',
        'withdrawal_fee' => 'Hævegebyr',
        'transaction_levy' => 'Transaktionsafgift',
        'foreign_transaction_fee' => 'Valutagebyr',
        'commission' => 'Provision',
        'debit_interest' => 'Debetrente',
        'overdraft' => 'Overtræksgebyr',
        'overdraft_interest' => 'Overtræksrente',
        'insufficient_funds' => 'Gebyr for manglende dækning',
        'penalty_fee' => 'Strafgebyr',
        'loan_arrangement_fee' => 'Stiftelsesprovision',
    ],

    'cp_card' => [
        'aria' => 'Modpart: :name',
        'recent_aria' => 'Seneste aktivitet',
    ],

    'chain_flow' => [
        'aria_prefix' => 'Finansieringskæde: ',
        'join' => ' til ',
    ],

    'iban_row' => [
        'label' => 'IBAN',
        'hidden_aria' => 'IBAN skjult — klik på Vis IBAN for at vise det',
        // i18n-review: da · hidden_aria_touch — the same line for a touch
        // screen; check the verb governs this case.
        'hidden_aria_touch' => 'IBAN skjult — tryk på Vis IBAN for at vise det',
        'show' => 'Vis IBAN',
        'hide' => 'Skjul IBAN',
    ],

    'privacy_banner' => [
        'aria' => 'Privatlivsmeddelelse for privat kontakt',
        'body' => '🔒 Dette er en privat kontakt. IBAN og personoplysninger er skjult som standard og deles aldrig i eksporter.',
    ],

    'self_stub' => [
        'aria' => 'Ikke en rigtig modpart',
        'heading' => 'Dette er egentlig ikke en modpart',

        'body_rest_html' => ' vises her, fordi det optræder i dine transaktioner som finansieringsleddet mellem konti. Men det er <strong>din egen konto</strong>, ikke nogen, du handler med.',
        'body2' => 'Åbn kontovisningen for saldo, kontoudtog og den fulde transaktionshistorik.',
        'open_cta' => 'Åbn kontovisningen for :name →',
        'hide_cta' => 'Skjul fra denne liste',
        'recent_legs' => 'Seneste led mellem konti',
    ],
];
