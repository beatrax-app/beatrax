<?php

declare(strict_types=1);

return [
    'page_title' => 'Ahelad',
    'heading' => 'Ahelad',
    'review_link' => 'Ülevaatusjärjekord →',
    'hints_link' => 'Vihjed →',
    'subtitle' => 'Ostud, mis koondati üheks makseks. Iga kaart näitab üht makset ja makseid, mis sellesse sisse läksid.',

    'empty_heading' => 'Ahelaid veel pole',
    'empty_body' => 'Impordi mõni kontoväljavõte (pank, PayPal, kaart) ja lahendaja toob kontodeülesed ahelad automaatselt siia.',

    'no_counterparty' => '(vastaspooleta)',
    'leg_count' => ':count makse|:count makset',
    'legs_more' => '+ veel :count',
    'state_aria' => 'Olek: :state',

    'state' => [
        'candidate' => 'Kandidaat',
        'confirmed' => 'Kinnitatud',
        'rejected' => 'Tagasi lükatud',
    ],

    'kind' => [
        'paypal_funding' => 'PayPali rahastus',
        'ics_bulk_settle' => 'iDEALi koondarveldus',
        'funded_by_card_hint' => 'Rahastatud kaardiga (vihje)',
        'refund_of_hint' => 'Tagasimakse (vihje)',
    ],
];
