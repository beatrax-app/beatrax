<?php

declare(strict_types=1);

return [
    'type_chip' => [
        'aria' => 'Tip de contraparte: :type',
        'merchant' => 'Comerciant',
        'personal' => 'Personal',
        'bank' => 'Bancă',
        'government' => 'Instituție publică',
        'self' => 'Cont propriu',
        'unknown' => 'Necunoscut',
    ],

    'filter_chips' => [
        'aria' => 'Filtrează după tip',
        'all' => 'Toate',
        'merchant' => 'Comercianți',
        'personal' => 'Personale',
        'bank' => 'Bănci',
        'government' => 'Instituții publice',
        'self' => 'Conturi proprii',
        'unknown' => 'Necunoscute',
    ],

    'default_name' => [
        'bank_fee' => 'Comision bancar',
        'account_maintenance' => 'Comision administrare cont',
        'monthly_fee' => 'Comision lunar',
        'quarterly_fee' => 'Comision trimestrial',
        'annual_fee' => 'Comision anual',
        'card_fee' => 'Comision card',
        'transaction_fee' => 'Comision tranzacție',
        'transfer_fee' => 'Comision transfer',
        'withdrawal_fee' => 'Comision retragere',
        'transaction_levy' => 'Taxă pe tranzacții',
        'foreign_transaction_fee' => 'Comision schimb valutar',
        'commission' => 'Comision',
        'debit_interest' => 'Dobândă debitoare',
        'overdraft' => 'Comision descoperit de cont',
        'overdraft_interest' => 'Dobândă descoperit de cont',
        'insufficient_funds' => 'Comision fonduri insuficiente',
        'penalty_fee' => 'Penalitate',
        'loan_arrangement_fee' => 'Comision de acordare credit',
    ],

    'cp_card' => [
        'aria' => 'Contraparte: :name',
        'recent_aria' => 'Activitate recentă',
    ],

    'chain_flow' => [
        'aria_prefix' => 'Lanț de finanțare: ',
        'join' => ' către ',
    ],

    'iban_row' => [
        'label' => 'IBAN',
        'hidden_aria' => 'IBAN ascuns — dă clic pe Arată IBAN pentru a-l dezvălui',
        // i18n-review: ro · hidden_aria_touch — the same line for a touch
        // screen; check the verb governs this case.
        'hidden_aria_touch' => 'IBAN ascuns — atinge pe Arată IBAN pentru a-l dezvălui',
        'show' => 'Arată IBAN',
        'hide' => 'Ascunde IBAN',
    ],

    'privacy_banner' => [
        'aria' => 'Notificare de confidențialitate pentru contact personal',
        'body' => '🔒 Acesta este un contact personal. IBAN-ul și datele personale sunt ascunse implicit și nu sunt niciodată incluse în exporturi.',
    ],

    'self_stub' => [
        'aria' => 'Nu este o contraparte reală',
        'heading' => 'Aceasta nu este chiar o contraparte',

        'body_rest_html' => ' apare aici pentru că figurează în tranzacțiile tale drept segmentul de finanțare dintre conturi. Dar este <strong>contul tău</strong>, nu cineva cu care faci tranzacții.',
        'body2' => 'Deschide vizualizarea contului pentru sold, extrase și istoricul complet al tranzacțiilor.',
        'open_cta' => 'Deschide vizualizarea contului :name →',
        'hide_cta' => 'Ascunde din această listă',
        'recent_legs' => 'Segmente recente între conturi',
    ],
];
