<?php

declare(strict_types=1);

// Drifting monthly Spotify — 12 occurrences at €9.99 followed by 6 at
// €11.49 (price bump mid-window). The 15% drift sits inside the default
// ±25% variance tolerance so detector expectation is ONE expense series,
// monthly cadence, latest_amount reflects the post-bump price.

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
