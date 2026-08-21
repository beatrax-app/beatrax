<?php

declare(strict_types=1);

// The weekly multiplier is 52, not 52.18: an integer keeps it consistent with
// the monthly-equivalent multiplier.

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
