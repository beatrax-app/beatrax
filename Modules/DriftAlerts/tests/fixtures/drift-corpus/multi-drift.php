<?php

declare(strict_types=1);

// Two escalations queue two open alerts rather than one merged row: the
// per-event audit trail stays honest and the grouped-by-series UI collapses it.

$transactions = [];
$amounts = [-999, -999, -1099, -1099, -1199, -1199];
for ($i = 0; $i < 6; $i++) {
    $year = 2025 + intdiv($i, 12);
    $month = ($i % 12) + 1;
    $date = sprintf('%04d-%02d-15', $year, $month);
    $transactions[] = [
        'account_id' => null,
        'type' => 'expense',
        'posted_at' => $date,
        'booked_at' => $date,
        'amount_minor' => $amounts[$i],
        'currency' => 'EUR',
        'original_amount_minor' => $amounts[$i],
        'original_currency' => 'EUR',
        'counterparty_normalized' => 'spotify',
        'counterparty_iban' => null,
    ];
}

return [
    'transactions' => $transactions,
    'expected' => [
        'alerts' => [
            [
                'state' => 'open',
                'direction' => 'expense',
                'baseline_amount_minor' => -999,
                'latest_amount_minor' => -1099,
                'delta_minor' => -100,
                'annualized_impact_minor' => -1200,
                'threshold_percent_used' => 5,
                'threshold_source' => 'global',
                'currency' => 'EUR',
            ],
            [
                'state' => 'open',
                'direction' => 'expense',
                'baseline_amount_minor' => -1099,
                'latest_amount_minor' => -1199,
                'delta_minor' => -100,
                'annualized_impact_minor' => -1200,
                'threshold_percent_used' => 5,
                'threshold_source' => 'global',
                'currency' => 'EUR',
            ],
        ],
    ],
];
