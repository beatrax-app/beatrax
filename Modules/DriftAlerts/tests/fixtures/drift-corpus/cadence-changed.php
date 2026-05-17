<?php

declare(strict_types=1);

// Subscription on a series whose state is 'cadence_changed' with a
// +10% drift on top. Drift detection still fires on cadence_changed
// series — the user can act on cadence and drift independently.
// Math:
//   delta_minor = -1099 - (-999) = -100
//   annualized_impact_minor = -100 × 12 = -1200 (-€12/yr)
// The series_state on the recurring_series row is 'cadence_changed';
// consumers of this fixture seed the row accordingly.

$transactions = [];
$amounts = [-999, -999, -999, -1099, -1099, -1099];
for ($i = 0; $i < 6; $i++) {
    $year = 2025 + intdiv($i, 12);
    $month = ($i % 12) + 1;
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
        'counterparty_normalized' => 'cadence-changed-streamer',
        'counterparty_iban' => null,
    ];
}

return [
    'transactions' => $transactions,
    'expected' => [
        'series_state' => 'cadence_changed',
        'alerts' => [
            [
                'state' => 'open',
                'direction' => 'expense',
                'baseline_amount_minor' => -999,
                'latest_amount_minor' => -1099,
                'delta_minor' => -100,
                'annualized_impact_minor' => -1200,
                'threshold_percent_used' => 5,
                'threshold_source' => 'global',
                'currency' => 'EUR',
            ],
        ],
    ],
];
