<?php

declare(strict_types=1);

// Scenario 1: Stable monthly Netflix at €9.99 across 6 occurrences.
// Every consecutive pair has delta=0, so the detector never fires.
// Expected: zero drift alerts.

$transactions = [];
for ($i = 0; $i < 6; $i++) {
    $year = 2025 + intdiv($i, 12);
    $month = ($i % 12) + 1;
    $date = sprintf('%04d-%02d-15', $year, $month);
    $transactions[] = [
        'account_id' => null,
        'type' => 'expense',
        'posted_at' => $date,
        'booked_at' => $date,
        'amount_minor' => -999,
        'currency' => 'EUR',
        'original_amount_minor' => -999,
        'original_currency' => 'EUR',
        'counterparty_normalized' => 'netflix',
        'counterparty_iban' => null,
    ];
}

return [
    'transactions' => $transactions,
    'expected' => [
        'alerts' => [],
    ],
];
