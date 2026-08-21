<?php

declare(strict_types=1);

$transactions = [];
$amounts = [-24000, -24000, -27000, -27000];
$start = new DateTimeImmutable('2025-01-15');
for ($i = 0; $i < 4; $i++) {
    $date = $start->modify('+'.($i * 3).' months')->format('Y-m-d');
    $transactions[] = [
        'account_id' => null,
        'type' => 'expense',
        'posted_at' => $date,
        'booked_at' => $date,
        'amount_minor' => $amounts[$i],
        'currency' => 'EUR',
        'original_amount_minor' => $amounts[$i],
        'original_currency' => 'EUR',
        'counterparty_normalized' => 'quarterly-insurance',
        'counterparty_iban' => null,
    ];
}

return [
    'transactions' => $transactions,
    'expected' => [
        'series_cadence' => 'quarterly',
        'alerts' => [
            [
                'state' => 'open',
                'direction' => 'expense',
                'baseline_amount_minor' => -24000,
                'latest_amount_minor' => -27000,
                'delta_minor' => -3000,
                'annualized_impact_minor' => -12000,
                'threshold_percent_used' => 5,
                'threshold_source' => 'global',
                'currency' => 'EUR',
            ],
        ],
    ],
];
