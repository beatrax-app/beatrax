<?php

declare(strict_types=1);

/** @link ../../../../../.docs/features/forecasting/forecast-corpus.md#buffer-crossing */

return [
    'accounts' => [
        [
            'id' => 1,
            'user_id' => 1,
            'name' => 'ASN Betaalrekening',
            'kind' => 'bank',
            'default_currency' => 'EUR',
            'opening_balance_minor' => 60000,
            'opening_balance_as_of_date' => '2026-05-01',
            'forecast_min_buffer_minor' => 50000,
        ],
    ],
    'series' => [
        [
            'id' => 601,
            'user_id' => 1,
            'name' => 'Rent share',
            'cadence' => 'monthly',
            'direction' => 'expense',
            'account_id' => 1,
            'latest_amount_minor' => -12000,
            'latest_currency' => 'EUR',
            'variance_tolerance_percent' => 5,
            'state' => 'approved',
            'latest_fx_rate_used' => null,
            'next_expected_date' => '2026-05-07',
            'occurrences' => [
                ['date' => '2026-01-07', 'observed_amount_minor' => -12000, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2026-02-07', 'observed_amount_minor' => -12000, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2026-03-07', 'observed_amount_minor' => -12000, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2026-04-07', 'observed_amount_minor' => -12000, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
            ],
        ],
        [
            'id' => 602,
            'user_id' => 1,
            'name' => 'Energy',
            'cadence' => 'monthly',
            'direction' => 'expense',
            'account_id' => 1,
            'latest_amount_minor' => -5000,
            'latest_currency' => 'EUR',
            'variance_tolerance_percent' => 5,
            'state' => 'approved',
            'latest_fx_rate_used' => null,
            'next_expected_date' => '2026-05-12',
            'occurrences' => [
                ['date' => '2026-01-12', 'observed_amount_minor' => -5000, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2026-02-12', 'observed_amount_minor' => -5000, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2026-03-12', 'observed_amount_minor' => -5000, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2026-04-12', 'observed_amount_minor' => -5000, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
            ],
        ],
        [
            'id' => 603,
            'user_id' => 1,
            'name' => 'Streaming',
            'cadence' => 'monthly',
            'direction' => 'expense',
            'account_id' => 1,
            'latest_amount_minor' => -3000,
            'latest_currency' => 'EUR',
            'variance_tolerance_percent' => 5,
            'state' => 'approved',
            'latest_fx_rate_used' => null,
            'next_expected_date' => '2026-05-22',
            'occurrences' => [
                ['date' => '2026-01-22', 'observed_amount_minor' => -3000, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2026-02-22', 'observed_amount_minor' => -3000, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2026-03-22', 'observed_amount_minor' => -3000, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2026-04-22', 'observed_amount_minor' => -3000, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
            ],
        ],
    ],
    'expected' => [
        'projection' => [
            [
                'horizon_days' => 30,
                'account_id' => 1,
                'date' => '2026-05-07',
                'low_minor' => 47400,
                'point_minor' => 48000,
                'high_minor' => 48600,
                'currency' => 'EUR',
            ],
            [
                'horizon_days' => 60,
                'account_id' => 1,
                'date' => '2026-06-07',
                'low_minor' => 27400,
                'point_minor' => 28000,
                'high_minor' => 28600,
                'currency' => 'EUR',
            ],
            [
                'horizon_days' => 90,
                'account_id' => 1,
                'date' => '2026-07-07',
                'low_minor' => 7400,
                'point_minor' => 8000,
                'high_minor' => 8600,
                'currency' => 'EUR',
            ],
        ],
        'shortfalls' => [
            [
                'account_id' => 1,
                'starts_at' => '2026-05-07',
                'ends_at' => '2026-05-31',
                'lowest_balance_minor' => 40000,
                'currency' => 'EUR',
                'buffer_used_minor' => 50000,
            ],
        ],
    ],
];
