<?php

declare(strict_types=1);

// Monthly subscription with two consecutive missing months mid-window
// (e.g. provider billing hiccup, bank holiday processing skew). The
// 1.8 × median gap appears for the missed period but stays under the
// "2 missing in any rolling 6-period window" tolerance. Expectation:
// ONE expense series, monthly cadence, unfragmented.

$transactions = [];
$start = new DateTimeImmutable('2024-12-05');
// Months observed: 0,1,2,3, [4,5 skipped], 6,7,8,9,10,11 — 10 of 12.
$monthsObserved = [0, 1, 2, 3, 6, 7, 8, 9, 10, 11];
foreach ($monthsObserved as $i => $offset) {
    $d = $start->modify('+'.$offset.' months');
    $transactions[] = [
        'account_id' => null,
        'type' => 'expense',
        'posted_at' => $d->format('Y-m-d'),
        'booked_at' => $d->format('Y-m-d'),
        'amount_minor' => -1499,
        'currency' => 'EUR',
        'original_amount_minor' => -1499,
        'original_currency' => 'EUR',
        'counterparty_normalized' => 'newsletter pro',
        'counterparty_iban' => null,
    ];
}

return [
    'transactions' => $transactions,
    'expected' => [
        'series_count' => 1,
        'series' => [
            [
                'direction' => 'expense',
                'cadence' => 'monthly',
                'counterparty_normalized' => 'newsletter pro',
                'latest_amount_minor' => -1499,
                'currency' => 'EUR',
                'monthly_equivalent_minor' => -1499,
            ],
        ],
        'reason' => 'Missed-occurrence tolerance covers the two-month gap; series stays unfragmented.',
    ],
];
