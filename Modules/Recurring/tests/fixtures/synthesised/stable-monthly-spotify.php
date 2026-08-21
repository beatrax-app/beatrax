<?php

declare(strict_types=1);

// The no-drift baseline: identical amount and day-of-month, 18 months running.

$transactions = [];
for ($i = 0; $i < 18; $i++) {
    $year = 2024 + intdiv(10 + $i, 12);
    $month = ((10 + $i) % 12) + 1;
    $date = sprintf('%04d-%02d-15', $year, $month);
    $transactions[] = [
        'account_id' => null,
        'type' => 'expense',
        'posted_at' => $date,
        'booked_at' => $date,
        'amount_minor' => -999,
        'currency' => 'EUR',
        'original_amount_minor' => -999,
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
                'latest_amount_minor' => -999,
                'currency' => 'EUR',
                'monthly_equivalent_minor' => -999,
            ],
        ],
    ],
];
