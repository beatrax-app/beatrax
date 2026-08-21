<?php

declare(strict_types=1);

// -6.0% rather than exactly -5.0%: the threshold test is strictly
// greater-than, so a move of precisely the threshold would not fire.

$transactions = [];
$amounts = [350000, 350000, 350000, 329000, 329000, 329000];
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
                'latest_amount_minor' => 329000,
                'delta_minor' => -21000,
                'annualized_impact_minor' => -252000,
                'threshold_percent_used' => 5,
                'threshold_source' => 'global',
                'currency' => 'EUR',
            ],
        ],
    ],
];
