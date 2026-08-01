<?php

declare(strict_types=1);

// Zero-occurrence edge case: same ASN + a single approved series
// with EMPTY occurrence history (just-approved series;
// latest_amount_minor is set but no prior occurrences exist).
// Envelope tier applies since there's no occurrence history to
// support percentile; 30-day points use latest_amount_minor ×
// variance_tolerance_percent directly. No shortfalls.

return [
    'accounts' => [
        [
            'id' => 1,
            'user_id' => 1,
            'name' => 'ASN Betaalrekening',
            'kind' => 'bank',
            'default_currency' => 'EUR',
            'opening_balance_minor' => 150000,
            'opening_balance_as_of_date' => '2026-05-01',
            'forecast_min_buffer_minor' => null,
        ],
    ],
    'series' => [
        [
            'id' => 501,
            'user_id' => 1,
            'name' => 'NewSubscription',
            'cadence' => 'monthly',
            'direction' => 'expense',
            'account_id' => 1,
            'latest_amount_minor' => -1500,
            'latest_currency' => 'EUR',
            'variance_tolerance_percent' => 10,
            'state' => 'approved',
            'latest_fx_rate_used' => null,
            'next_expected_date' => '2026-05-22',
            'occurrences' => [],
        ],
    ],
    'expected' => [
        'projection' => [
            [
                'horizon_days' => 30,
                'account_id' => 1,
                'date' => '2026-05-22',
                'low_minor' => 148350,
                'point_minor' => 148500,
                'high_minor' => 148650,
                'currency' => 'EUR',
            ],
            [
                'horizon_days' => 60,
                'account_id' => 1,
                'date' => '2026-06-22',
                'low_minor' => 146850,
                'point_minor' => 147000,
                'high_minor' => 147150,
                'currency' => 'EUR',
            ],
            [
                'horizon_days' => 90,
                'account_id' => 1,
                'date' => '2026-07-22',
                'low_minor' => 145350,
                'point_minor' => 145500,
                'high_minor' => 145650,
                'currency' => 'EUR',
            ],
        ],
        'shortfalls' => [],
    ],
];
