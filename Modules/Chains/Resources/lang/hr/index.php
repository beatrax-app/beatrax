<?php

declare(strict_types=1);

return [
    'page_title' => 'Lanci',
    'heading' => 'Lanci',
    'review_link' => 'Red za pregled →',
    'hints_link' => 'Savjeti →',
    'subtitle' => 'Kupnje koje su objedinjene u jedno terećenje. Svaka kartica prikazuje jedno terećenje i plaćanja koja su u njega ušla.',

    'empty_heading' => 'Još nema lanaca',
    'empty_body' => 'Uvezi nekoliko izvoda (banka, PayPal, kartica) i resolver će ovdje automatski prikazati lance između računa.',

    'no_counterparty' => '(nema protustranke)',
    'leg_count' => ':count plaćanje|:count plaćanja|:count plaćanja',
    'legs_more' => '+ još :count',
    'state_aria' => 'Stanje: :state',

    'state' => [
        'candidate' => 'Kandidat',
        'confirmed' => 'Potvrđeno',
        'rejected' => 'Odbijeno',
    ],

    'kind' => [
        'paypal_funding' => 'Financiranje PayPalom',
        'ics_bulk_settle' => 'Skupno namirenje iDEAL',
        'funded_by_card_hint' => 'Financirano karticom (savjet)',
        'refund_of_hint' => 'Povrat (savjet)',
    ],
];
