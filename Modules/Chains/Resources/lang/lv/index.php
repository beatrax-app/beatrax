<?php

declare(strict_types=1);

return [
    'page_title' => 'Ķēdes',
    'heading' => 'Ķēdes',
    'review_link' => 'Pārskatīšanas rinda →',
    'hints_link' => 'Norādes →',
    'subtitle' => 'Pirkumi, kas apvienoti vienā maksājumā. Katrā kartītē redzams viens maksājums un tajā iekļautie maksājumi.',

    'empty_heading' => 'Vēl nav nevienas ķēdes',
    'empty_body' => 'Importējiet dažus konta izrakstus (banka, PayPal, karte), un atrisinātājs šeit automātiski parādīs starpkontu ķēdes.',

    'no_counterparty' => '(nav darījuma partnera)',
    'leg_count' => ':count maksājumu|:count maksājums|:count maksājumi',
    'legs_more' => '+ vēl :count',
    'state_aria' => 'Statuss: :state',

    'state' => [
        'candidate' => 'Kandidāts',
        'confirmed' => 'Apstiprināts',
        'rejected' => 'Noraidīts',
    ],

    'kind' => [
        'paypal_funding' => 'PayPal finansējums',
        'ics_bulk_settle' => 'Apkopots iDEAL norēķins',
        'funded_by_card_hint' => 'Finansēts ar karti (norāde)',
        'refund_of_hint' => 'Atmaksa (norāde)',
    ],
];
