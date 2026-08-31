<?php

declare(strict_types=1);

return [
    'page_title' => 'Ketjut',
    'heading' => 'Ketjut',
    'review_link' => 'Tarkistusjono →',
    'hints_link' => 'Vihjeet →',
    'subtitle' => 'Ostokset, jotka on koottu yhdeksi veloitukseksi. Jokaisella kortilla näkyy yksi veloitus ja siihen johtaneet maksut.',

    'empty_heading' => 'Ei vielä ketjuja',
    'empty_body' => 'Tuo muutama tiliote (pankki, PayPal, kortti), niin ratkaisija nostaa tilien väliset ketjut tänne automaattisesti.',

    'no_counterparty' => '(ei vastapuolta)',
    'leg_count' => ':count maksu|:count maksua',
    'legs_more' => '+ :count lisää',
    'state_aria' => 'Tila: :state',

    'state' => [
        'candidate' => 'Ehdokas',
        'confirmed' => 'Vahvistettu',
        'rejected' => 'Hylätty',
    ],

    'kind' => [
        'paypal_funding' => 'PayPal-rahoitus',
        'ics_bulk_settle' => 'iDEAL-koontitilitys',
        'funded_by_card_hint' => 'Rahoitettu kortilla (vihje)',
        'refund_of_hint' => 'Hyvitys (vihje)',
    ],
];
