<?php

declare(strict_types=1);

return [
    'page_title' => 'Reťazce',
    'heading' => 'Reťazce',
    'review_link' => 'Rad na kontrolu →',
    'hints_link' => 'Indície →',
    'subtitle' => 'Nákupy, ktoré sa zlúčili do jednej platby. Každá karta ukazuje jednu platbu a platby, ktoré do nej vstúpili.',

    'empty_heading' => 'Zatiaľ žiadne reťazce',
    'empty_body' => 'Naimportuj niekoľko výpisov z účtu (banka, PayPal, karta) a reťazce naprieč účtami sa tu objavia automaticky.',

    'no_counterparty' => '(bez protistrany)',
    'leg_count' => ':count platba|:count platby|:count platieb',
    'legs_more' => '+ ďalších :count',
    'state_aria' => 'Stav: :state',

    'state' => [
        'candidate' => 'Kandidát',
        'confirmed' => 'Potvrdené',
        'rejected' => 'Zamietnuté',
    ],

    'kind' => [
        'paypal_funding' => 'Financovanie cez PayPal',
        'ics_bulk_settle' => 'Hromadné zúčtovanie iDEAL',
        'funded_by_card_hint' => 'Uhradené kartou (indícia)',
        'refund_of_hint' => 'Vrátenie peňazí (indícia)',
    ],
];
