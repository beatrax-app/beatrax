<?php

declare(strict_types=1);

// An 'irregular' cadence has a year multiplier of 0, so any annualised impact
// would be meaningless; such series are excluded before evaluation.

$transactions = [];
$amounts = [-999, -999, -999, -1199, -1199, -1199];
$days = [3, 19, 27, 7, 22, 13];
for ($i = 0; $i < 6; $i++) {
    $year = 2025 + intdiv($i, 12);
    $month = ($i % 12) + 1;
    $date = sprintf('%04d-%02d-%02d', $year, $month, $days[$i]);
    $transactions[] = [
        'account_id' => null,
        'type' => 'expense',
        'posted_at' => $date,
        'booked_at' => $date,
        'amount_minor' => $amounts[$i],
        'currency' => 'EUR',
        'original_amount_minor' => $amounts[$i],
        'original_currency' => 'EUR',
        'counterparty_normalized' => 'irregular-merchant',
        'counterparty_iban' => null,
    ];
}

return [
    'transactions' => $transactions,
    'expected' => [
        'series_cadence' => 'irregular',
        'alerts' => [],
    ],
];
