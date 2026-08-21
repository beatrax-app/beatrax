<?php

declare(strict_types=1);

// The same volatile bill under a 50% per-series override. The widest pair,
// 11000 -> 14500 (+31.8%), still sits inside it, so nothing fires.

$transactions = [];
$amounts = [-10000, -13000, -9500, -11800, -11000, -14500];
for ($i = 0; $i < 6; $i++) {
    $year = 2025 + intdiv($i, 12);
    $month = ($i % 12) + 1;
    $date = sprintf('%04d-%02d-05', $year, $month);
    $transactions[] = [
        'account_id' => null,
        'type' => 'expense',
        'posted_at' => $date,
        'booked_at' => $date,
        'amount_minor' => $amounts[$i],
        'currency' => 'EUR',
        'original_amount_minor' => $amounts[$i],
        'original_currency' => 'EUR',
        'counterparty_normalized' => 'volatile-utility-tolerant',
        'counterparty_iban' => null,
    ];
}

return [
    'transactions' => $transactions,
    'expected' => [
        'series_drift_threshold_percent' => 50,
        'alerts' => [],
    ],
];
