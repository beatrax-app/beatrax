<?php

declare(strict_types=1);

// Series whose state is 'pending' (awaiting user approval) and where
// the underlying transactions would otherwise produce a +20% drift.
// Drift detection only runs against state IN ('approved',
// 'cadence_changed'), so a pending series produces zero alerts
// regardless of amount movement.

$transactions = [];
$amounts = [-999, -999, -999, -1199, -1199, -1199];
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
        'counterparty_normalized' => 'pending-series-merchant',
        'counterparty_iban' => null,
    ];
}

return [
    'transactions' => $transactions,
    'expected' => [
        'series_state' => 'pending',
        'alerts' => [],
    ],
];
