<?php

declare(strict_types=1);

// A variable bill (NL electricity with seasonal heating swings) fires on nearly
// every pair against the default 5% threshold — the alert avalanche the
// per-series override exists to absorb.

$transactions = [];
$amounts = [-10000, -13000, -9500, -11800, -8200, -14500];
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
        'counterparty_normalized' => 'volatile-utility',
        'counterparty_iban' => null,
    ];
}

return [
    'transactions' => $transactions,
    'expected' => [
        // The contract test only asserts a count of 3 or more, not these exact rows.
        'alerts' => [
            ['state' => 'open', 'direction' => 'expense', 'threshold_percent_used' => 5, 'threshold_source' => 'global', 'currency' => 'EUR'],
            ['state' => 'open', 'direction' => 'expense', 'threshold_percent_used' => 5, 'threshold_source' => 'global', 'currency' => 'EUR'],
            ['state' => 'open', 'direction' => 'expense', 'threshold_percent_used' => 5, 'threshold_source' => 'global', 'currency' => 'EUR'],
            ['state' => 'open', 'direction' => 'expense', 'threshold_percent_used' => 5, 'threshold_source' => 'global', 'currency' => 'EUR'],
            ['state' => 'open', 'direction' => 'expense', 'threshold_percent_used' => 5, 'threshold_source' => 'global', 'currency' => 'EUR'],
        ],
    ],
];
