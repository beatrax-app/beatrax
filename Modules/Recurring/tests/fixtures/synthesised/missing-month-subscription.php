<?php

declare(strict_types=1);

// Two consecutive months missing mid-window. The hole is wide enough to count
// as missed periods but stays inside the "2 missing per rolling 6" tolerance,
// so the series must not fragment.

$transactions = [];
$start = new DateTimeImmutable('2024-12-05');
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
