<?php

declare(strict_types=1);

// A reader who asked to hear about 1% moves hears about a 2% move. The settings
// ladder offers 1 and 2, so a 5% floor applied over the top would make both
// options inert while still reading back the number the reader picked.

$transactions = [];
$amounts = [-10000, -10000, -10000, -10200, -10200, -10200];
for ($i = 0; $i < 6; $i++) {
    $date = sprintf('2025-%02d-18', $i + 1);
    $transactions[] = [
        'account_id' => null,
        'type' => 'expense',
        'posted_at' => $date,
        'booked_at' => $date,
        'amount_minor' => $amounts[$i],
        'currency' => 'EUR',
        'original_amount_minor' => $amounts[$i],
        'original_currency' => 'EUR',
        'counterparty_normalized' => 'closely-watched-utility',
        'counterparty_iban' => null,
    ];
}

return [
    'transactions' => $transactions,
    'expected' => [
        'user_drift_threshold_percent' => 1,
        'alerts' => [
            [
                'state' => 'open',
                'direction' => 'expense',
                'baseline_amount_minor' => -10000,
                'latest_amount_minor' => -10200,
                'delta_minor' => -200,
                'annualized_impact_minor' => -2400,
                'threshold_percent_used' => 1,
                'threshold_source' => 'global',
                'currency' => 'EUR',
            ],
        ],
    ],
];
