<?php

declare(strict_types=1);

return [
    'type_chip' => [
        'aria' => 'Kitos šalies tipas: :type',
        'merchant' => 'Prekybininkas',
        'personal' => 'Asmuo',
        'bank' => 'Bankas',
        'government' => 'Valstybės institucija',
        'self' => 'Sava sąskaita',
        'unknown' => 'Nežinoma',
    ],

    'filter_chips' => [
        'aria' => 'Filtruoti pagal tipą',
        'all' => 'Visos',
        'merchant' => 'Prekybininkai',
        'personal' => 'Asmenys',
        'bank' => 'Bankai',
        'government' => 'Valstybės institucijos',
        'self' => 'Savos sąskaitos',
        'unknown' => 'Nežinomos',
    ],

    'default_name' => [
        'bank_fee' => 'Banko mokestis',
        'account_maintenance' => 'Sąskaitos tvarkymo mokestis',
        'monthly_fee' => 'Mėnesinis mokestis',
        'quarterly_fee' => 'Ketvirtinis mokestis',
        'annual_fee' => 'Metinis mokestis',
        'card_fee' => 'Kortelės mokestis',
        'transaction_fee' => 'Operacijos mokestis',
        'transfer_fee' => 'Pervedimo mokestis',
        'withdrawal_fee' => 'Grynųjų išėmimo mokestis',
        'transaction_levy' => 'Sandorių mokestis',
        'foreign_transaction_fee' => 'Valiutos keitimo mokestis',
        'commission' => 'Komisinis mokestis',
        'debit_interest' => 'Debeto palūkanos',
        'overdraft' => 'Kredito limito mokestis',
        'overdraft_interest' => 'Kredito limito palūkanos',
        'insufficient_funds' => 'Mokestis už nepakankamas lėšas',
        'penalty_fee' => 'Delspinigiai',
        'loan_arrangement_fee' => 'Kredito suteikimo mokestis',
    ],

    'cp_card' => [
        'aria' => 'Kita šalis: :name',
        'recent_aria' => 'Naujausia veikla',
    ],

    'chain_flow' => [
        'aria_prefix' => 'Finansavimo grandinė: ',
        'join' => ' į ',
    ],

    'iban_row' => [
        'label' => 'IBAN',
        'hidden_aria' => 'IBAN paslėptas — spustelėk „Rodyti IBAN“, kad jį pamatytum',
        // i18n-review: lt · hidden_aria_touch — the same line for a touch
        // screen; check the verb governs this case.
        'hidden_aria_touch' => 'IBAN paslėptas — palieski „Rodyti IBAN“, kad jį pamatytum',
        'show' => 'Rodyti IBAN',
        'hide' => 'Slėpti IBAN',
    ],

    'privacy_banner' => [
        'aria' => 'Privatumo pranešimas apie asmeninį kontaktą',
        'body' => '🔒 Tai asmeninis kontaktas. IBAN ir asmens duomenys pagal numatytuosius nustatymus paslėpti ir niekada neįtraukiami į eksportus.',
    ],

    'self_stub' => [
        'aria' => 'Ne tikra kita šalis',
        'heading' => 'Iš tikrųjų tai ne kita šalis',

        'body_rest_html' => ' čia rodoma todėl, kad tavo operacijose ji figūruoja kaip finansavimo atkarpa tarp sąskaitų. Bet tai <strong>tavo paties sąskaita</strong>, o ne kas nors, su kuo atlieki operacijas.',
        'body2' => 'Atverk sąskaitos rodinį, kad matytum likutį, išrašus ir visą operacijų istoriją.',
        'open_cta' => 'Atverti sąskaitos :name rodinį →',
        'hide_cta' => 'Slėpti iš šio sąrašo',
        'recent_legs' => 'Naujausios tarpsąskaitinės atkarpos',
    ],
];
