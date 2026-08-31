<?php

declare(strict_types=1);

// EUR 10.00 a month became EUR 100.00 a year: the reader now pays EUR 100.00
// where they paid EUR 120.00, a EUR 20.00 saving. Annualising the raw delta at
// the NEW multiplier reported EUR 90.00/yr extra.

$transactions = [];
$rows = [
    ['2024-09-15', -1000],
    ['2024-10-15', -1000],
    ['2024-11-15', -1000],
    ['2025-11-15', -10000],
];
foreach ($rows as $i => [$date, $amount]) {
    $transactions[] = [
        'account_id' => null,
        'type' => 'expense',
        'posted_at' => $date,
        'booked_at' => $date,
        'amount_minor' => $amount,
        'currency' => 'EUR',
        'original_amount_minor' => $amount,
        'original_currency' => 'EUR',
        'counterparty_normalized' => 'restructured-plan',
        'counterparty_iban' => null,
    ];
}

return [
    'transactions' => $transactions,
    'expected' => [
        'series_cadence' => 'yearly',
        'alerts' => [
            [
                'state' => 'open',
                'direction' => 'expense',
                'baseline_amount_minor' => -1000,
                'latest_amount_minor' => -10000,
                'delta_minor' => -9000,
                'annualized_impact_minor' => 2000,
                'threshold_percent_used' => 5,
                'threshold_source' => 'global',
                'currency' => 'EUR',
            ],
        ],
    ],
];
