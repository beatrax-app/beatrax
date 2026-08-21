<?php

declare(strict_types=1);

// A price bump mid-window: 12 occurrences at €9.99, then 6 at €11.49. The 15%
// drift sits inside the default ±25% variance tolerance, so this must stay one
// series rather than fragment into two.

$transactions = [];
$amounts = array_merge(
    array_fill(0, 12, -999),
    array_fill(0, 6, -1149),
);
for ($i = 0; $i < 18; $i++) {
    $year = 2024 + intdiv(10 + $i, 12);
    $month = ((10 + $i) % 12) + 1;
    $date = sprintf('%04d-%02d-15', $year, $month);
    $transactions[] = [
        'account_id' => null,
        'type' => 'expense',
        'posted_at' => $date,
        'booked_at' => $date,
        'amount_minor' => $amounts[$i],
        'currency' => 'EUR',
        'original_amount_minor' => $amounts[$i],
        'original_currency' => 'EUR',
        'counterparty_normalized' => 'spotify',
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
                'counterparty_normalized' => 'spotify',
                'latest_amount_minor' => -1149,
                'currency' => 'EUR',
                'monthly_equivalent_minor' => -1149,
                'drift_detected' => true,
            ],
        ],
    ],
];
