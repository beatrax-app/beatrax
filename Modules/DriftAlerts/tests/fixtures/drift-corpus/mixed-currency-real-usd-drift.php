<?php

declare(strict_types=1);

// The USD price itself moved, so the alert is denominated in USD.

$transactions = [];
$amounts = [-1199, -1199, -1199, -1499, -1499, -1499];
for ($i = 0; $i < 6; $i++) {
    $year = 2025 + intdiv($i, 12);
    $month = ($i % 12) + 1;
    $date = sprintf('%04d-%02d-08', $year, $month);
    $transactions[] = [
        'account_id' => null,
        'type' => 'expense',
        'posted_at' => $date,
        'booked_at' => $date,
        // Settled EUR is held stable so only the USD move can explain the alert.
        'amount_minor' => -1100,
        'currency' => 'EUR',
        'original_amount_minor' => $amounts[$i],
        'original_currency' => 'USD',
        'counterparty_normalized' => 'us-streaming-drift',
        'counterparty_iban' => null,
    ];
}

return [
    'transactions' => $transactions,
    'expected' => [
        'series_currency' => 'USD',
        'alerts' => [
            [
                'state' => 'open',
                'direction' => 'expense',
                'baseline_amount_minor' => -1199,
                'latest_amount_minor' => -1499,
                'delta_minor' => -300,
                'annualized_impact_minor' => -3600,
                'threshold_percent_used' => 5,
                'threshold_source' => 'global',
                'currency' => 'USD',
            ],
        ],
    ],
];
