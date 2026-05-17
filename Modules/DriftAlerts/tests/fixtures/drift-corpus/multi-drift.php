<?php

declare(strict_types=1);

// Spotify €9.99 → €10.99 → €11.99 over three escalations.
// Each transition exceeds the ±5% threshold so the detector queues
// TWO open alerts (one per drift event). Queue-all-as-open keeps an
// honest per-event audit trail; the grouped-by-series UI later
// collapses the visual noise. Math:
//   step 1: delta = -1099 - (-999) = -100; annual = -1200 (-€12/yr)
//   step 2: delta = -1199 - (-1099) = -100; annual = -1200 (-€12/yr)

$transactions = [];
$amounts = [-999, -999, -1099, -1099, -1199, -1199];
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
        'counterparty_normalized' => 'spotify',
        'counterparty_iban' => null,
    ];
}

return [
    'transactions' => $transactions,
    'expected' => [
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
            [
                'state' => 'open',
                'direction' => 'expense',
                'baseline_amount_minor' => -1099,
                'latest_amount_minor' => -1199,
                'delta_minor' => -100,
                'annualized_impact_minor' => -1200,
                'threshold_percent_used' => 5,
                'threshold_source' => 'global',
                'currency' => 'EUR',
            ],
        ],
    ],
];
