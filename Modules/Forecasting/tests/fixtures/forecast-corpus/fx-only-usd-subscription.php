<?php

declare(strict_types=1);

/** @link ../../../../../.docs/features/forecasting/forecast-corpus.md#fx-only-usd-subscription */

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
            'id' => 401,
            'user_id' => 1,
            'name' => 'Netflix US',
            'cadence' => 'monthly',
            'direction' => 'expense',
            'account_id' => 1,
            'latest_amount_minor' => -1199,
            'latest_currency' => 'USD',
            'variance_tolerance_percent' => 5,
            'state' => 'approved',
            'next_expected_date' => '2026-05-18',
            'occurrences' => [
                ['date' => '2026-01-18', 'observed_amount_minor' => -1199, 'observed_currency' => 'USD', 'fx_rate_used' => 0.9120],
                ['date' => '2026-02-18', 'observed_amount_minor' => -1199, 'observed_currency' => 'USD', 'fx_rate_used' => 0.9080],
                ['date' => '2026-03-18', 'observed_amount_minor' => -1199, 'observed_currency' => 'USD', 'fx_rate_used' => 0.9050],
                ['date' => '2026-04-18', 'observed_amount_minor' => -1199, 'observed_currency' => 'USD', 'fx_rate_used' => 0.9050],
            ],
        ],
    ],
    // No rate on the series row: production never wrote one, so the pipeline
    // resolves USD->EUR from exchange_rates at fold time. The figures below
    // are the bundled snapshot's EUR 1 = USD 1.1359 read the other way round,
    // 0.88035919, applied to each of the three bounds before the band is taken.
    'expected' => [
        'projection' => [
            [
                'horizon_days' => 30,
                'account_id' => 1,
                'date' => '2026-05-18',
                'low_minor' => 148891,
                'point_minor' => 148944,
                'high_minor' => 148997,
                'currency' => 'EUR',
            ],
            [
                'horizon_days' => 60,
                'account_id' => 1,
                'date' => '2026-06-18',
                'low_minor' => 147835,
                'point_minor' => 147888,
                'high_minor' => 147941,
                'currency' => 'EUR',
            ],
            [
                'horizon_days' => 90,
                'account_id' => 1,
                'date' => '2026-07-18',
                'low_minor' => 146779,
                'point_minor' => 146832,
                'high_minor' => 146885,
                'currency' => 'EUR',
            ],
        ],
        'shortfalls' => [],
    ],
];
