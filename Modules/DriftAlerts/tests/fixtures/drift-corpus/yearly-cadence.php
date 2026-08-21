<?php

declare(strict_types=1);

$transactions = [
    [
        'account_id' => null,
        'type' => 'expense',
        'posted_at' => '2024-06-15',
        'booked_at' => '2024-06-15',
        'amount_minor' => -1000,
        'currency' => 'EUR',
        'original_amount_minor' => -1000,
        'original_currency' => 'EUR',
        'counterparty_normalized' => 'yearly-domain',
        'counterparty_iban' => null,
    ],
    [
        'account_id' => null,
        'type' => 'expense',
        'posted_at' => '2025-06-15',
        'booked_at' => '2025-06-15',
        'amount_minor' => -1500,
        'currency' => 'EUR',
        'original_amount_minor' => -1500,
        'original_currency' => 'EUR',
        'counterparty_normalized' => 'yearly-domain',
        'counterparty_iban' => null,
    ],
];

return [
    'transactions' => $transactions,
    'expected' => [
        'series_cadence' => 'yearly',
        'alerts' => [
            [
                'state' => 'open',
                'direction' => 'expense',
                'baseline_amount_minor' => -1000,
                'latest_amount_minor' => -1500,
                'delta_minor' => -500,
                'annualized_impact_minor' => -500,
                'threshold_percent_used' => 5,
                'threshold_source' => 'global',
                'currency' => 'EUR',
            ],
        ],
    ],
];
