<?php

declare(strict_types=1);

/** @link ../../../../../.docs/features/forecasting/forecast-corpus.md#stable-monthly-subscription */

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
            'id' => 101,
            'user_id' => 1,
            'name' => 'Netflix',
            'cadence' => 'monthly',
            'direction' => 'expense',
            'account_id' => 1,
            'latest_amount_minor' => -1199,
            'latest_currency' => 'EUR',
            'variance_tolerance_percent' => 5,
            'state' => 'approved',
            'latest_fx_rate_used' => null,
            'next_expected_date' => '2026-05-15',
            'occurrences' => [
                ['date' => '2025-11-15', 'observed_amount_minor' => -1199, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2025-12-15', 'observed_amount_minor' => -1199, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2026-01-15', 'observed_amount_minor' => -1199, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2026-02-15', 'observed_amount_minor' => -1199, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2026-03-15', 'observed_amount_minor' => -1199, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2026-04-15', 'observed_amount_minor' => -1199, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
            ],
        ],
    ],
    'expected' => [
        'projection' => [
            [
                'horizon_days' => 30,
                'account_id' => 1,
                'date' => '2026-05-15',
                'low_minor' => 148741,
                'point_minor' => 148801,
                'high_minor' => 148861,
                'currency' => 'EUR',
            ],
            [
                'horizon_days' => 60,
                'account_id' => 1,
                'date' => '2026-06-15',
                'low_minor' => 147542,
                'point_minor' => 147602,
                'high_minor' => 147662,
                'currency' => 'EUR',
            ],
            [
                'horizon_days' => 90,
                'account_id' => 1,
                'date' => '2026-07-15',
                'low_minor' => 146343,
                'point_minor' => 146403,
                'high_minor' => 146463,
                'currency' => 'EUR',
            ],
        ],
        'shortfalls' => [],
    ],
];
