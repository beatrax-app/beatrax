<?php

declare(strict_types=1);

// Series whose cadence='irregular' (the recurring detector flags
// series that don't fit weekly/monthly/quarterly/yearly). The
// cadence-to-year multiplier match() returns 0 for 'irregular' so an
// annualized impact of zero is meaningless; the detector guards
// against this upstream by excluding irregular series. Zero alerts.

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
