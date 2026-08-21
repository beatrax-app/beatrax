<?php

declare(strict_types=1);

// +10.0%, over the default 5% threshold.

$transactions = [];
$amounts = [350000, 350000, 350000, 385000, 385000, 385000];
for ($i = 0; $i < 6; $i++) {
    $year = 2025 + intdiv($i, 12);
    $month = ($i % 12) + 1;
    $date = sprintf('%04d-%02d-25', $year, $month);
    $transactions[] = [
        'account_id' => null,
        'type' => 'income',
        'posted_at' => $date,
        'booked_at' => $date,
        'amount_minor' => $amounts[$i],
        'currency' => 'EUR',
        'original_amount_minor' => $amounts[$i],
        'original_currency' => 'EUR',
        'counterparty_normalized' => 'acme-employer',
        'counterparty_iban' => null,
    ];
}

return [
    'transactions' => $transactions,
    'expected' => [
        'alerts' => [
            [
                'state' => 'open',
                'direction' => 'income',
                'baseline_amount_minor' => 350000,
                'latest_amount_minor' => 385000,
                'delta_minor' => 35000,
                'annualized_impact_minor' => 420000,
                'threshold_percent_used' => 5,
                'threshold_source' => 'global',
                'currency' => 'EUR',
            ],
        ],
    ],
];
