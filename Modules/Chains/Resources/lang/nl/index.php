<?php

declare(strict_types=1);

return [
    'page_title' => 'Ketens',
    'heading' => 'Ketens',
    'review_link' => 'Beoordelingswachtrij →',
    'hints_link' => 'Hints →',
    'subtitle' => 'Aankopen die samen als één afschrijving zijn geïnd. Elke kaart toont één afschrijving en de betalingen die erin zaten.',

    'empty_heading' => 'Nog geen ketens',
    'empty_body' => 'Importeer een paar afschriften (bank, PayPal, kaart) en de resolver toont hier automatisch ketens over meerdere rekeningen.',

    'no_counterparty' => '(geen tegenpartij)',
    'leg_count' => ':count betaling|:count betalingen',
    'legs_more' => '+ :count meer',
    'state_aria' => 'Status: :state',

    'state' => [
        'candidate' => 'Kandidaat',
        'confirmed' => 'Bevestigd',
        'rejected' => 'Afgewezen',
    ],

    'kind' => [
        'paypal_funding' => 'PayPal-financiering',
        'ics_bulk_settle' => 'Bulk-iDEAL-afwikkeling',
        'funded_by_card_hint' => 'Gefinancierd met kaart (hint)',
        'refund_of_hint' => 'Terugbetaling (hint)',
    ],
];
