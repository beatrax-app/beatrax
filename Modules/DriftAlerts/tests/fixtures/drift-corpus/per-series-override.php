<?php

declare(strict_types=1);

// +25.0%, but under the series's own 50% threshold, so nothing fires.

$transactions = [];
$amounts = [-12000, -12000, -12000, -15000, -15000, -15000];
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
        'counterparty_normalized' => 'electricity-utility',
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
