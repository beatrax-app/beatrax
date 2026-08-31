<?php

declare(strict_types=1);

return [
    'page_title' => 'Savjeti za lance',
    'heading' => 'Savjeti',
    'back_to_review' => '← Natrag na red za pregled',
    'subtitle' => 'Kandidati koje je usklađivač izdao bez odgovarajućeg parnjaka. Natuknica o podmirenju nestaje sama kad stignu troškovi koji su nedostajali; ostale ostaju dok ih ovdje ne odbacite.',

    'empty_heading' => 'Nema savjeta za trijažu',
    'empty_body' => 'Kad matcher pronađe lanac koji nije mogao automatski riješiti, pojavit će se ovdje.',

    'no_counterparty' => '(nema protustranke)',
    'unknown_account' => '(nepoznat račun)',

    'dismiss' => 'Odbaci',
    'dismiss_aria' => 'Odbaci savjet :id',
    'dismissed' => 'Savjet je odbačen.',

    'kind' => [
        'ics_bulk_settle' => 'Skupna namira iDEAL (izvan tolerancije)',
        'funded_by_card_hint' => 'Financirano karticom (savjet)',
        'refund_of_hint' => 'Povrat (savjet)',
    ],

    'evidence' => [
        'tolerance' => 'Tolerancija: :tolerance',
        'tolerance_used' => [
            'amount_5eur' => 'unutar fiksne tolerancije',
            'percent_2' => 'unutar postotne tolerancije',
            'exceeded' => 'izvan tolerancije',
            'refund_after_close' => 'povrat nakon zatvaranja',
        ],
        'delta_overpaid' => 'Preplaćeno :amount',
        'delta_underpaid' => 'Nedostaje :amount',
        'delta_balanced' => 'Slaže se točno',
        'covered' => 'Pokrivene transakcije: :count',
        'statement' => 'Izvod kartice br. :id',
        'card_last4' => 'Kartica koja završava na :last4',
        'original_reference' => 'Izvorna referenca narudžbe: :reference',
    ],
];
