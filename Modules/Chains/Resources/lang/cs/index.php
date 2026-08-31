<?php

declare(strict_types=1);

return [
    'page_title' => 'Řetězce',
    'heading' => 'Řetězce',
    'review_link' => 'Fronta ke kontrole →',
    'hints_link' => 'Náznaky →',
    'subtitle' => 'Nákupy stažené jako jedna platba. Každá karta ukazuje jednu platbu a platby, které do ní vstoupily.',

    'empty_heading' => 'Zatím žádné řetězce',
    'empty_body' => 'Naimportuj pár výpisů z účtu (banka, PayPal, karta) a řetězce napříč účty se tu objeví samy.',

    'no_counterparty' => '(bez protistrany)',
    'leg_count' => ':count platba|:count platby|:count plateb',
    'legs_more' => '+ dalších :count',
    'state_aria' => 'Stav: :state',

    'state' => [
        'candidate' => 'Kandidát',
        'confirmed' => 'Potvrzeno',
        'rejected' => 'Odmítnuto',
    ],

    'kind' => [
        'paypal_funding' => 'Financování z PayPalu',
        'ics_bulk_settle' => 'Hromadné vypořádání iDEAL',
        'funded_by_card_hint' => 'Financováno kartou (náznak)',
        'refund_of_hint' => 'Vrácení peněz (náznak)',
    ],
];
