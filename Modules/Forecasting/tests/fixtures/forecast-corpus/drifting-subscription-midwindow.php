<?php

declare(strict_types=1);

/** @link ../../../../../.docs/features/forecasting/forecast-corpus.md#drifting-subscription-midwindow */

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
            'id' => 102,
            'user_id' => 1,
            'name' => 'Spotify',
            'cadence' => 'monthly',
            'direction' => 'expense',
            'account_id' => 1,
            'latest_amount_minor' => -1149,
            'latest_currency' => 'EUR',
            'variance_tolerance_percent' => 5,
            'state' => 'approved',
            'latest_fx_rate_used' => null,
            'next_expected_date' => '2026-05-20',
            'occurrences' => [
                ['date' => '2025-11-20', 'observed_amount_minor' => -999, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2025-12-20', 'observed_amount_minor' => -999, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2026-01-20', 'observed_amount_minor' => -999, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2026-02-20', 'observed_amount_minor' => -999, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2026-03-20', 'observed_amount_minor' => -1149, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2026-04-20', 'observed_amount_minor' => -1149, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
            ],
        ],
    ],
    'expected' => [
        'projection' => [
            [
                'horizon_days' => 30,
                'account_id' => 1,
                'date' => '2026-05-20',
                'low_minor' => 148794,
                'point_minor' => 148851,
                'high_minor' => 148908,
                'currency' => 'EUR',
            ],
            [
                'horizon_days' => 60,
                'account_id' => 1,
                'date' => '2026-06-20',
                'low_minor' => 147645,
                'point_minor' => 147702,
                'high_minor' => 147759,
                'currency' => 'EUR',
            ],
            [
                'horizon_days' => 90,
                'account_id' => 1,
                'date' => '2026-07-20',
                'low_minor' => 146496,
                'point_minor' => 146553,
                'high_minor' => 146610,
                'currency' => 'EUR',
            ],
        ],
        'shortfalls' => [],
    ],
];
