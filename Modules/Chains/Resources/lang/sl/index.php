<?php

declare(strict_types=1);

return [
    'page_title' => 'Verige',
    'heading' => 'Verige',
    'review_link' => 'Čakalna vrsta za pregled →',
    'hints_link' => 'Namigi →',
    'subtitle' => 'Nakupi, ki so bili združeni v eno bremenitev. Vsaka kartica prikaže eno bremenitev in plačila, ki so vanjo pritekla.',

    'empty_heading' => 'Verig še ni',
    'empty_body' => 'Uvozi nekaj izpiskov (banka, PayPal, kartica) in resolver bo tu samodejno prikazal verige med računi.',

    'no_counterparty' => '(brez nasprotne stranke)',
    'leg_count' => ':count plačilo|:count plačili|:count plačila|:count plačil',
    'legs_more' => '+ še :count',
    'state_aria' => 'Stanje: :state',

    'state' => [
        'candidate' => 'Kandidat',
        'confirmed' => 'Potrjeno',
        'rejected' => 'Zavrnjeno',
    ],

    'kind' => [
        'paypal_funding' => 'Financiranje prek PayPala',
        'ics_bulk_settle' => 'Zbirna poravnava iDEAL',
        'funded_by_card_hint' => 'Financirano s kartico (namig)',
        'refund_of_hint' => 'Vračilo (namig)',
    ],
];
