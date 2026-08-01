<?php

declare(strict_types=1);

// Variable utility: same ASN account + an Electricity series with
// variance_tolerance_percent=45 (≥40% percentile-tier trigger) and
// 8 historical occurrences ranging from €60 to €220. The expected
// 30-day projection shows a WIDE band on the occurrence date —
// P10/P90 spread reflects the natural variance. No shortfalls at
// the documented opening balance.

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
                ['date' => '2025-10-28', 'observed_amount_minor' => -6000, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2025-11-28', 'observed_amount_minor' => -10500, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2025-12-28', 'observed_amount_minor' => -22000, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2026-01-28', 'observed_amount_minor' => -19500, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2026-02-28', 'observed_amount_minor' => -18000, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2026-03-28', 'observed_amount_minor' => -13000, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2026-04-28', 'observed_amount_minor' => -8500, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
                ['date' => '2026-05-28', 'observed_amount_minor' => -14000, 'observed_currency' => 'EUR', 'fx_rate_used' => null],
            ],
        ],
    ],
    'expected' => [
        'projection' => [
            [
                'horizon_days' => 30,
                'account_id' => 1,
                'date' => '2026-05-28',
                'low_minor' => 128000,
                'point_minor' => 136000,
                'high_minor' => 144000,
                'currency' => 'EUR',
            ],
            [
                'horizon_days' => 60,
                'account_id' => 1,
                'date' => '2026-06-28',
                'low_minor' => 114000,
                'point_minor' => 122000,
                'high_minor' => 138000,
                'currency' => 'EUR',
            ],
            [
                'horizon_days' => 90,
                'account_id' => 1,
                'date' => '2026-07-28',
                'low_minor' => 100000,
                'point_minor' => 108000,
                'high_minor' => 132000,
                'currency' => 'EUR',
            ],
        ],
        'shortfalls' => [],
    ],
];
