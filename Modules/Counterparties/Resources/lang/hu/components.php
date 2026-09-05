<?php

declare(strict_types=1);

return [
    'type_chip' => [
        'aria' => 'Partner típusa: :type',
        'merchant' => 'Kereskedő',
        'personal' => 'Magánszemély',
        'bank' => 'Bank',
        'government' => 'Állami',
        'self' => 'Saját',
        'unknown' => 'Ismeretlen',
    ],

    'filter_chips' => [
        'aria' => 'Szűrés típus szerint',
        'all' => 'Összes',
        'merchant' => 'Kereskedők',
        'personal' => 'Magánszemélyek',
        'bank' => 'Bankok',
        'government' => 'Állami',
        'self' => 'Saját',
        'unknown' => 'Ismeretlen',
    ],

    'default_name' => [
        'bank_fee' => 'Banki díj',
        'account_maintenance' => 'Számlavezetési díj',
        'monthly_fee' => 'Havi díj',
        'quarterly_fee' => 'Negyedéves díj',
        'annual_fee' => 'Éves díj',
        'card_fee' => 'Kártyadíj',
        'transaction_fee' => 'Tranzakciós díj',
        'transfer_fee' => 'Átutalási díj',
        'withdrawal_fee' => 'Készpénzfelvételi díj',
        'transaction_levy' => 'Tranzakciós illeték',
        'foreign_transaction_fee' => 'Devizaátváltási díj',
        'commission' => 'Jutalék',
        'debit_interest' => 'Tartozás utáni kamat',
        'overdraft' => 'Hitelkeret díja',
        'overdraft_interest' => 'Hitelkeret kamata',
        'insufficient_funds' => 'Fedezethiány díja',
        'penalty_fee' => 'Büntetődíj',
        'loan_arrangement_fee' => 'Hitelfolyósítási díj',
    ],

    'cp_card' => [
        'aria' => 'Partner: :name',
        'recent_aria' => 'Legutóbbi tevékenység',
    ],

    'chain_flow' => [
        'aria_prefix' => 'Fedezeti lánc: ',
        'join' => ' ide: ',
    ],

    'iban_row' => [
        'label' => 'IBAN',
        'hidden_aria' => 'Az IBAN rejtve — kattints az IBAN megjelenítésére',
        // i18n-review: hu · hidden_aria_touch — the same line for a touch
        // screen; check the verb governs this case.
        'hidden_aria_touch' => 'Az IBAN rejtve — koppints az IBAN megjelenítésére',
        'show' => 'IBAN megjelenítése',
        'hide' => 'IBAN elrejtése',
    ],

    'privacy_banner' => [
        'aria' => 'Adatvédelmi tájékoztatás magánszemély kapcsolathoz',
        'body' => '🔒 Ez egy magánszemély kapcsolat. Az IBAN és a személyes adatok alapértelmezés szerint rejtve vannak, és exportáláskor soha nem kerülnek megosztásra.',
    ],

    'self_stub' => [
        'aria' => 'Nem valódi partner',
        'heading' => 'Ez valójában nem partner',

        'body_rest_html' => ' azért jelenik meg itt, mert a tranzakcióidban a számlák közötti fedezeti szakaszként szerepel. Ez azonban a <strong>saját számlád</strong>, nem olyasvalaki, akivel tranzakciózol.',
        'body2' => 'Nyisd meg a számlanézetet az egyenleghez, a kivonatokhoz és a teljes tranzakciótörténethez.',
        'open_cta' => 'A(z) :name számlanézetének megnyitása →',
        'hide_cta' => 'Elrejtés ebből a listából',
        'recent_legs' => 'Legutóbbi számlák közti szakaszok',
    ],
];
