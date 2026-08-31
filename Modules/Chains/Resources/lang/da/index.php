<?php

declare(strict_types=1);

return [
    'page_title' => 'Kæder',
    'heading' => 'Kæder',
    'review_link' => 'Gennemgangskø →',
    'hints_link' => 'Hints →',
    'subtitle' => 'Alle kæder, som kædeløseren har fundet. Klik på rækkens finansierede transaktion for at åbne kædepanelet med hele forgreningen.',

    'empty_heading' => 'Ingen kæder endnu',
    'empty_body' => 'Importér et par kontoudtog (bank, PayPal, kort), så viser kædeløseren automatisk kæder på tværs af konti her.',

    'no_counterparty' => '(ingen modpart)',
    'leg_count' => ':count betaling|:count betalinger',
    'legs_more' => '+ :count mere',
    'state_aria' => 'Status: :state',

    'state' => [
        'candidate' => 'Kandidat',
        'confirmed' => 'Bekræftet',
        'rejected' => 'Afvist',
    ],

    'kind' => [
        'paypal_funding' => 'PayPal-finansiering',
        'ics_bulk_settle' => 'Samlet iDEAL-afregning',
        'funded_by_card_hint' => 'Finansieret med kort (hint)',
        'refund_of_hint' => 'Refusion (hint)',
    ],
];
