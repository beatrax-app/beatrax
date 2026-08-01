<?php

declare(strict_types=1);

// FX-only USD subscription: same ASN account + a Netflix USD series
// (latest_amount_minor=-1199 USD, latest_currency='USD',
// latest_fx_rate_used=0.9050). Per-series legend renders
// "$11.99/mo (≈ €10.85/mo)"; per-account ASN chart converts to EUR
// via the stored fx_rate; daily fold runs in EUR; band reflects the
// EUR-converted amounts. Exercises the FX-preservation invariant.

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
            'latest_fx_rate_used' => 0.9050,
            'next_expected_date' => '2026-05-18',
            'occurrences' => [
                ['date' => '2026-02-18', 'observed_amount_minor' => -1199, 'observed_currency' => 'USD', 'fx_rate_used' => 0.9120],
                ['date' => '2026-03-18', 'observed_amount_minor' => -1199, 'observed_currency' => 'USD', 'fx_rate_used' => 0.9080],
                ['date' => '2026-04-18', 'observed_amount_minor' => -1199, 'observed_currency' => 'USD', 'fx_rate_used' => 0.9050],
                ['date' => '2026-05-18', 'observed_amount_minor' => -1199, 'observed_currency' => 'USD', 'fx_rate_used' => 0.9050],
            ],
        ],
    ],
    'expected' => [
        'projection' => [
            [
                'horizon_days' => 30,
                'account_id' => 1,
                'date' => '2026-05-18',
                'low_minor' => 148861,
                'point_minor' => 148915,
                'high_minor' => 148969,
                'currency' => 'EUR',
            ],
            [
                'horizon_days' => 60,
                'account_id' => 1,
                'date' => '2026-06-18',
                'low_minor' => 147776,
                'point_minor' => 147830,
                'high_minor' => 147884,
                'currency' => 'EUR',
            ],
            [
                'horizon_days' => 90,
                'account_id' => 1,
                'date' => '2026-07-18',
                'low_minor' => 146691,
                'point_minor' => 146745,
                'high_minor' => 146799,
                'currency' => 'EUR',
            ],
        ],
        'shortfalls' => [],
    ],
];
