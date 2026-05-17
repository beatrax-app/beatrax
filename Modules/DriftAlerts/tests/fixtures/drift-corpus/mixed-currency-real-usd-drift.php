<?php

declare(strict_types=1);

// Scenario 19: USD $11.99 → $14.99 (+25.0% in USD original currency).
// The original-currency drift is real, the alert fires in USD with
// USD-denominated delta + annualized impact. Math:
//   delta_minor = -1499 - (-1199) = -300 (signed expense, in USD cents)
//   annualized_impact_minor = -300 × 12 = -3600 (-$36/yr)
// Currency on the alert row is USD.

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
        // Settled EUR: stable for clarity — the test asserts USD drift
        // is what the detector picks up, regardless of EUR.
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
