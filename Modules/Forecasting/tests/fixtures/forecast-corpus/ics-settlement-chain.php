<?php

declare(strict_types=1);

/** @link ../../../../../.docs/features/forecasting/forecast-corpus.md#ics-settlement-chain */

return [
    'accounts' => [
        [
            'id' => 1,
            'user_id' => 1,
            'name' => 'ASN Betaalrekening',
            'kind' => 'bank',
            'default_currency' => 'EUR',
            'opening_balance_minor' => 200000,
            'opening_balance_as_of_date' => '2026-05-01',
            'forecast_min_buffer_minor' => null,
        ],
        [
            'id' => 2,
            'user_id' => 1,
            'name' => 'ICS World Card',
            'kind' => 'ics_card',
            'default_currency' => 'EUR',
            'opening_balance_minor' => null,
            'opening_balance_as_of_date' => null,
            'forecast_min_buffer_minor' => null,
        ],
    ],
    'series' => [
        [
            'id' => 301,
            'user_id' => 1,
            'name' => 'Adobe Creative Cloud',
            'cadence' => 'monthly',
            'direction' => 'expense',
            'account_id' => 2,
            'latest_amount_minor' => -1999,
            'latest_currency' => 'EUR',
            'variance_tolerance_percent' => 5,
            'state' => 'approved',
            'latest_fx_rate_used' => null,
            'next_expected_date' => '2026-05-20',
            'occurrences' => [
                ['date' => '2026-02-20', 'observed_amount_minor' => -1999, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2026-03-20', 'observed_amount_minor' => -1999, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2026-04-20', 'observed_amount_minor' => -1999, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
            ],
        ],
    ],
    'chain_state' => [
        'next_settlement_date' => '2026-05-29',
        'next_settlement_amount_minor' => -22500,
        'account_id' => 2,
        'funder_account_id' => 1,
    ],
    'expected' => [
        'projection' => [
            [
                'horizon_days' => 30,
                'account_id' => 1,
                'date' => '2026-05-29',
                'low_minor' => 177400,
                'point_minor' => 177500,
                'high_minor' => 177600,
                'currency' => 'EUR',
            ],
            [
                'horizon_days' => 30,
                'account_id' => 2,
                'date' => '2026-05-20',
                'low_minor' => -2099,
                'point_minor' => -1999,
                'high_minor' => -1899,
                'currency' => 'EUR',
            ],
            [
                'horizon_days' => 60,
                'account_id' => 1,
                'date' => '2026-06-29',
                'low_minor' => 155400,
                'point_minor' => 155500,
                'high_minor' => 155600,
                'currency' => 'EUR',
            ],
            [
                'horizon_days' => 90,
                'account_id' => 1,
                'date' => '2026-07-29',
                'low_minor' => 133400,
                'point_minor' => 133500,
                'high_minor' => 133600,
                'currency' => 'EUR',
            ],
        ],
        'shortfalls' => [],
    ],
];
