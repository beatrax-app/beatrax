<?php

declare(strict_types=1);

/** @link ../../../../../.docs/features/forecasting/forecast-corpus.md#variable-utility */

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
            'id' => 103,
            'user_id' => 1,
            'name' => 'Electricity',
            'cadence' => 'monthly',
            'direction' => 'expense',
            'account_id' => 1,
            'latest_amount_minor' => -14000,
            'latest_currency' => 'EUR',
            'variance_tolerance_percent' => 45,
            'state' => 'approved',
            'latest_fx_rate_used' => null,
            'next_expected_date' => '2026-05-28',
            'occurrences' => [
                ['date' => '2025-09-28', 'observed_amount_minor' => -6000, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2025-10-28', 'observed_amount_minor' => -10500, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2025-11-28', 'observed_amount_minor' => -22000, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2025-12-28', 'observed_amount_minor' => -19500, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2026-01-28', 'observed_amount_minor' => -18000, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2026-02-28', 'observed_amount_minor' => -13000, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2026-03-28', 'observed_amount_minor' => -8500, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2026-04-28', 'observed_amount_minor' => -14000, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
            ],
        ],
    ],
    'expected' => [
        'projection' => [
            [
                'horizon_days' => 30,
                'account_id' => 1,
                'date' => '2026-05-28',
                'low_minor' => 141391,
                'point_minor' => 142284,
                'high_minor' => 143177,
                'currency' => 'EUR',
            ],
            [
                'horizon_days' => 60,
                'account_id' => 1,
                'date' => '2026-06-28',
                'low_minor' => 127888,
                'point_minor' => 128781,
                'high_minor' => 129674,
                'currency' => 'EUR',
            ],
            [
                'horizon_days' => 90,
                'account_id' => 1,
                'date' => '2026-07-28',
                'low_minor' => 114385,
                'point_minor' => 115278,
                'high_minor' => 116171,
                'currency' => 'EUR',
            ],
        ],
        'shortfalls' => [],
    ],
];
