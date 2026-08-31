<?php

declare(strict_types=1);

return [
    'page_title' => 'Saveti za lance',
    'heading' => 'Saveti',
    'back_to_review' => '← Nazad na red za pregled',
    'subtitle' => 'Kandidati koje je usklađivač izdao bez odgovarajućeg parnjaka. Nagoveštaj o izmirenju nestaje sam kad stignu troškovi koji su nedostajali; ostali ostaju dok ih ovde ne odbacite.',

    'empty_heading' => 'Nema saveta za trijažu',
    'empty_body' => 'Kad matcher pronađe lanac koji nije mogao automatski da reši, pojaviće se ovde.',

    'no_counterparty' => '(nema druge strane)',
    'unknown_account' => '(nepoznat račun)',

    'dismiss' => 'Odbaci',
    'dismiss_aria' => 'Odbaci savet :id',
    'dismissed' => 'Savet je odbačen.',

    'kind' => [
        'ics_bulk_settle' => 'Zbirno poravnanje iDEAL (izvan tolerancije)',
        'funded_by_card_hint' => 'Finansirano karticom (savet)',
        'refund_of_hint' => 'Povraćaj (savet)',
    ],

    'evidence' => [
        'tolerance' => 'Tolerancija: :tolerance',
        'tolerance_used' => [
            'amount_5eur' => 'unutar fiksne tolerancije',
            'percent_2' => 'unutar procentualne tolerancije',
            'exceeded' => 'izvan tolerancije',
            'refund_after_close' => 'povraćaj nakon zatvaranja',
        ],
        'delta_overpaid' => 'Preplaćeno :amount',
        'delta_underpaid' => 'Nedostaje :amount',
        'delta_balanced' => 'Slaže se tačno',
        'covered' => 'Pokrivene transakcije: :count',
        'statement' => 'Izvod kartice br. :id',
        'card_last4' => 'Kartica koja se završava na :last4',
        'original_reference' => 'Originalna referenca porudžbine: :reference',
    ],
];
