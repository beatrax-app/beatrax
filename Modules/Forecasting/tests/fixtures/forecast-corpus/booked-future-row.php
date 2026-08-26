<?php

declare(strict_types=1);

/** @link ../../../../../.docs/features/forecasting/forecast-corpus.md#booked-future-row */

return [
    'accounts' => [
        [
            'id' => 1,
            'user_id' => 1,
            'name' => 'ASN Betaalrekening',
            'kind' => 'bank',
            'default_currency' => 'EUR',
            'opening_balance_minor' => 300000,
            'opening_balance_as_of_date' => '2026-05-01',
            'forecast_min_buffer_minor' => null,
        ],
    ],
    'series' => [
        [
            'id' => 901,
            'user_id' => 1,
            'name' => 'Rent',
            'cadence' => 'monthly',
            'direction' => 'expense',
            'account_id' => 1,
            'latest_amount_minor' => -80000,
            'latest_currency' => 'EUR',
            'variance_tolerance_percent' => 10,
            'state' => 'approved',
            'latest_fx_rate_used' => null,
            'next_expected_date' => '2026-05-05',
            'occurrences' => [
                ['date' => '2026-02-05', 'observed_amount_minor' => -80000, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2026-03-05', 'observed_amount_minor' => -80000, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2026-04-05', 'observed_amount_minor' => -80000, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
            ],
        ],
    ],
    // Dated ahead of the clock and carrying no occurrence row: the detection
    // sweep has not reached it, so the series link resolves on cluster
    // identity alone. The amount differs from the series' latest by EUR5 so
    // that the certainty and the estimate it retires cannot be confused.
    'booked_rows' => [
        [
            'account_id' => 1,
            'counterparty' => 'Rent',
            'direction' => 'expense',
            'date' => '2026-05-05',
            'settled_amount_minor' => -80500,
            'settled_currency' => 'EUR',
        ],
    ],
    'expected' => [
        'projection' => [
            [
                'horizon_days' => 30,
                'account_id' => 1,
                'date' => '2026-05-05',
                'low_minor' => 219500,
                'point_minor' => 219500,
                'high_minor' => 219500,
                'currency' => 'EUR',
            ],
            [
                'horizon_days' => 30,
                'account_id' => 1,
                'date' => '2026-05-31',
                'low_minor' => 219500,
                'point_minor' => 219500,
                'high_minor' => 219500,
                'currency' => 'EUR',
            ],
            [
                'horizon_days' => 60,
                'account_id' => 1,
                'date' => '2026-06-05',
                'low_minor' => 131500,
                'point_minor' => 139500,
                'high_minor' => 147500,
                'currency' => 'EUR',
            ],
            [
                'horizon_days' => 90,
                'account_id' => 1,
                'date' => '2026-07-05',
                'low_minor' => 51500,
                'point_minor' => 59500,
                'high_minor' => 67500,
                'currency' => 'EUR',
            ],
        ],
        'shortfalls' => [],
    ],
];
