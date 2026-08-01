<?php

declare(strict_types=1);

// Income coverage: same ASN account + TWO approved income series
// (Salary €3500/mo monthly + Side income €450/mo monthly). Expected
// 30-day projection shows net positive flow on payday(s); income
// series project with the `+` direction in the daily fold. No
// shortfalls because the inflows dominate.

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
            'id' => 201,
            'user_id' => 1,
            'name' => 'Salary',
            'cadence' => 'monthly',
            'direction' => 'income',
            'account_id' => 1,
            'latest_amount_minor' => 350000,
            'latest_currency' => 'EUR',
            'variance_tolerance_percent' => 5,
            'state' => 'approved',
            'latest_fx_rate_used' => null,
            'next_expected_date' => '2026-05-25',
            'occurrences' => [
                ['date' => '2026-01-25', 'observed_amount_minor' => 350000, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2026-02-25', 'observed_amount_minor' => 350000, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2026-03-25', 'observed_amount_minor' => 350000, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2026-04-25', 'observed_amount_minor' => 350000, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2026-05-25', 'observed_amount_minor' => 350000, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
            ],
        ],
        [
            'id' => 202,
            'user_id' => 1,
            'name' => 'Side income',
            'cadence' => 'monthly',
            'direction' => 'income',
            'account_id' => 1,
            'latest_amount_minor' => 45000,
            'latest_currency' => 'EUR',
            'variance_tolerance_percent' => 10,
            'state' => 'approved',
            'latest_fx_rate_used' => null,
            'next_expected_date' => '2026-05-10',
            'occurrences' => [
                ['date' => '2026-01-10', 'observed_amount_minor' => 45000, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2026-02-10', 'observed_amount_minor' => 45000, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2026-03-10', 'observed_amount_minor' => 45000, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2026-04-10', 'observed_amount_minor' => 45000, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2026-05-10', 'observed_amount_minor' => 45000, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
            ],
        ],
    ],
    'expected' => [
        'projection' => [
            [
                'horizon_days' => 30,
                'account_id' => 1,
                'date' => '2026-05-25',
                'low_minor' => 526750,
                'point_minor' => 545000,
                'high_minor' => 563250,
                'currency' => 'EUR',
            ],
            [
                'horizon_days' => 60,
                'account_id' => 1,
                'date' => '2026-06-25',
                'low_minor' => 921750,
                'point_minor' => 940000,
                'high_minor' => 958250,
                'currency' => 'EUR',
            ],
            [
                'horizon_days' => 90,
                'account_id' => 1,
                'date' => '2026-07-25',
                'low_minor' => 1316750,
                'point_minor' => 1335000,
                'high_minor' => 1353250,
                'currency' => 'EUR',
            ],
        ],
        'shortfalls' => [],
    ],
];
