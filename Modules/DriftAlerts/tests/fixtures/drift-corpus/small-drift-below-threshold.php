<?php

declare(strict_types=1);

// Scenario 2: Spotify €9.99 → €10.20 (+2.1% month-over-month). The
// drift sits below the default ±5% threshold so the detector skips
// the alert. Expected: zero drift alerts.

$transactions = [];
$amounts = [-999, -999, -999, -1020, -1020, -1020];
for ($i = 0; $i < 6; $i++) {
    $year = 2025 + intdiv($i, 12);
    $month = ($i % 12) + 1;
    $date = sprintf('%04d-%02d-10', $year, $month);
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
        'alerts' => [],
    ],
];
