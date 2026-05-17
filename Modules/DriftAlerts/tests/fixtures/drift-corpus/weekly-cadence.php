<?php

declare(strict_types=1);

// Weekly streaming credit €10/wk → €11/wk (+10.0%). The weekly
// cadence multiplier is ×52 (calendar-year approximation, chosen for
// integer consistency with the monthly-equivalent multiplier). Math:
//   delta_minor = -1100 - (-1000) = -100
//   annualized_impact_minor = -100 × 52 = -5200 (-€52/yr)

$transactions = [];
$amounts = [-1000, -1000, -1000, -1000, -1100, -1100, -1100, -1100];
$start = new DateTimeImmutable('2025-04-01');
for ($i = 0; $i < 8; $i++) {
    $date = $start->modify('+'.$i.' weeks')->format('Y-m-d');
    $transactions[] = [
        'account_id' => null,
        'type' => 'expense',
        'posted_at' => $date,
        'booked_at' => $date,
        'amount_minor' => $amounts[$i],
        'currency' => 'EUR',
        'original_amount_minor' => $amounts[$i],
        'original_currency' => 'EUR',
        'counterparty_normalized' => 'weekly-streaming',
        'counterparty_iban' => null,
    ];
}

return [
    'transactions' => $transactions,
    'expected' => [
        'series_cadence' => 'weekly',
        'alerts' => [
            [
                'state' => 'open',
                'direction' => 'expense',
                'baseline_amount_minor' => -1000,
                'latest_amount_minor' => -1100,
                'delta_minor' => -100,
                'annualized_impact_minor' => -5200,
                'threshold_percent_used' => 5,
                'threshold_source' => 'global',
                'currency' => 'EUR',
            ],
        ],
    ],
];
