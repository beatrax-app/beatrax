<?php

declare(strict_types=1);

return [
    'page_title' => 'Lanci',
    'heading' => 'Lanci',
    'review_link' => 'Red za pregled →',
    'hints_link' => 'Saveti →',
    'subtitle' => 'Kupovine koje su objedinjene u jedno zaduženje. Svaka kartica prikazuje jedno zaduženje i plaćanja koja su u njega ušla.',

    'empty_heading' => 'Još nema lanaca',
    'empty_body' => 'Uvezi nekoliko izvoda (banka, PayPal, kartica) i resolver će ovde automatski prikazati lance između računa.',

    'no_counterparty' => '(nema druge strane)',
    'leg_count' => ':count plaćanje|:count plaćanja|:count plaćanja',
    'legs_more' => '+ još :count',
    'state_aria' => 'Stanje: :state',

    'state' => [
        'candidate' => 'Kandidat',
        'confirmed' => 'Potvrđeno',
        'rejected' => 'Odbijeno',
    ],

    'kind' => [
        'paypal_funding' => 'Finansiranje PayPalom',
        'ics_bulk_settle' => 'Zbirno namirenje iDEAL',
        'funded_by_card_hint' => 'Finansirano karticom (savet)',
        'refund_of_hint' => 'Povraćaj (savet)',
    ],
];
